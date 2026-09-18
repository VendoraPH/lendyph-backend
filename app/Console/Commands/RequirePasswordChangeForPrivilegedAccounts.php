<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('users:require-password-change
    {--dry-run : Print every account that would be flagged and write nothing. Start here, every time.}
    {--me= : Your own account, by email or username. Required for a real run, and spared from the flag unless --include-me. Pass the literal "none" if you have no account on this deployment.}
    {--include-me : Flag the --me account as well. Locks you out of everything but the change-password screen. Asks first unless --force.}
    {--role=* : Narrow to accounts holding this role. Repeatable. Omit it to cover every account holding any role.}
    {--except=* : Spare one account, by email or username. Repeatable.}
    {--include-roleless : Also flag accounts that hold no role at all. Cannot be combined with --role.}
    {--force : Skip the interactive confirmations. Required for a non-interactive production run.}')]
#[Description('Require privileged accounts to choose a new password before they can use the app again — always preview with --dry-run first')]
class RequirePasswordChangeForPrivilegedAccounts extends Command
{
    use ConfirmableTrait;

    /**
     * The literal `--me` value that means "I have no account on this box".
     *
     * `--me` has to be mandatory for a real run (see handle()), and a mandatory
     * option with no legal way to say "not applicable" is an option that gets
     * satisfied with a guess. A guess here is the exact failure this command
     * exists to avoid: `--me=admin@coop.ph` when the address is really
     * `admin@coop.ph.net` matches nobody, spares nobody, and flags the operator.
     * So an unmatched `--me` is a hard abort, and this word is the one way to
     * say it on purpose rather than by typo.
     */
    private const NO_OPERATOR_ACCOUNT = 'none';

    /**
     * Flag privileged accounts so the app makes them choose a new password.
     *
     * ## Why this exists
     *
     * `audit_logs` stored the bcrypt `password` of every user it recorded, and
     * `audit_logs:view` was held by roles that had no business reading them.
     * The rows are scrubbed and the trait now redacts (User::$auditRedacted),
     * but a scrub cannot un-read what was already taken: every hash that sat in
     * that table is assumed to be in someone's cracking queue. The remedy the
     * owner chose is a forced password change for privileged accounts.
     *
     * ## What it does NOT do, stated so nobody assumes otherwise
     *
     * This is a *require a change*, not a *reset*. It does not touch anybody's
     * password, so a cracked hash keeps working for login until its owner
     * actually changes it — the flag only stops that session doing anything
     * else. Closing the window completely means resetting every password to a
     * value nobody knows and distributing them out of band, which locks the
     * cooperative out of its own system until each person is reached by phone.
     * That is a business decision, not a command-line one; this is the milder
     * half the owner asked for and its limit should be read out loud.
     *
     * It also deliberately leaves two things alone:
     *
     * - **Tokens.** Deleting them cannot stop a password-based login by someone
     *   who cracked the hash, so it buys nothing against the actual threat —
     *   and it costs the good path something real: with the session alive, the
     *   frontend sees the 423 and walks the user straight to the change-password
     *   screen. Revoke it and they are dropped at a login form instead, where
     *   the only password they know is the one we are trying to retire.
     * - **`file_link_version`.** UserController::resetPassword() bumps it
     *   because it revokes every token and a signed KYC link must not outlive
     *   the session that minted it. Nothing is revoked here, and bumping the
     *   counter would break in-flight document links — visible damage, no
     *   security gain. AuthController::changePassword() leaves it alone for the
     *   same reason.
     *
     * ## Who is a "privileged account"
     *
     * Any user holding any role. Not a hardcoded list: the roles in use differ
     * per deployment — `bod_chairwoman`, `mis_credit_committee`, `it_head` and
     * others exist on one box and not the next, and admins can create more
     * through the roles screen — so a literal list would be silently incomplete
     * on the box it was not written for, and silently incomplete is the one
     * outcome a remediation command must not have. Membership of the set is
     * asked of the deployment instead of assumed by this file.
     *
     * The rule is also exhaustive here rather than merely broad: there are no
     * borrower user accounts on any deployment — members are `borrowers`, a
     * separate table with no login — so "holds a role" and "is staff" are the
     * same set, and an account holding no role at all is an anomaly rather than
     * a member. Those are reported loudly and left alone; `--include-roleless`
     * takes them too, once a human has looked at them.
     *
     * Inactive accounts ARE flagged. They hold roles, so their hashes were in
     * the same table, and a reactivation six months from now must not hand back
     * a password that is already in a wordlist. `status` is in the table so the
     * operator can see which ones they are.
     *
     * ## Idempotent
     *
     * Already-flagged accounts are counted and reported, never rewritten, so a
     * second run is a no-op that says so — and the audit trail does not grow a
     * second identical row per user for a change that did not happen.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeMe = (bool) $this->option('include-me');
        $includeRoleless = (bool) $this->option('include-roleless');

        $roleNames = $this->normalisedList($this->option('role'));

        if ($roleNames !== [] && $includeRoleless) {
            $this->error('--include-roleless cannot be combined with --role: an account with no role holds none of the roles you named.');

            return self::FAILURE;
        }

        if (($unknownRoles = $this->unknownRoles($roleNames)) !== []) {
            $this->error('Refusing to run: no such role on this deployment — '.implode(', ', $unknownRoles));
            $this->newLine();
            $this->line('Roles that DO exist here: '.Role::query()->orderBy('name')->pluck('name')->implode(', '));
            $this->line('Roles differ per deployment, so a name that is right on one box may not exist on the next.');
            $this->line('A --role nobody holds would flag nobody and report success, which reads exactly like "done".');

            return self::FAILURE;
        }

        $operator = null;
        $meOption = trim((string) $this->option('me'));

        if ($meOption === '' && ! $dryRun) {
            $this->error('Refusing to run: --me is required for a real run.');
            $this->newLine();
            $this->line('Name your own account so this command can leave it alone. Flagging yourself means');
            $this->line('every endpoint answers 423 to you until you change your own password.');
            $this->newLine();
            $this->line('  php artisan users:require-password-change --dry-run');
            $this->line('  php artisan users:require-password-change --me=you@example.com');
            $this->newLine();
            $this->line('If you genuinely have no account on this deployment, pass --me='.self::NO_OPERATOR_ACCOUNT.'.');

            return self::FAILURE;
        }

        if ($meOption !== '' && strcasecmp($meOption, self::NO_OPERATOR_ACCOUNT) !== 0) {
            $operator = $this->findAccount($meOption);

            if ($operator === null) {
                $this->error("Refusing to run: no account on this deployment matches --me={$meOption}.");
                $this->newLine();
                $this->line('Almost certainly a typo, and the consequence of guessing is the whole point of');
                $this->line('this guard: a --me that matches nobody protects nobody, so the run would flag you');
                $this->line('along with everyone else. Check the address, or pass --me='.self::NO_OPERATOR_ACCOUNT.' if you');
                $this->line('really have no account here.');

                return self::FAILURE;
            }
        }

        if ($includeMe && $operator === null) {
            $this->error('--include-me needs a --me account to include.');

            return self::FAILURE;
        }

        $spared = [];

        foreach ($this->normalisedList($this->option('except')) as $needle) {
            $account = $this->findAccount($needle);

            if ($account === null) {
                $this->error("Refusing to run: no account on this deployment matches --except={$needle}.");
                $this->newLine();
                $this->line('An --except that matches nobody spares nobody — it would flag the very account');
                $this->line('you named to protect, and report success while doing it.');

                return self::FAILURE;
            }

            $spared[$account->id] = $account;
        }

        $candidates = $this->candidates($roleNames, $includeRoleless);

        $toFlag = [];
        $alreadyFlagged = [];
        $protected = [];
        $excepted = [];

        foreach ($candidates as $candidate) {
            if (isset($spared[$candidate->id])) {
                $excepted[] = $candidate;

                continue;
            }

            if ($operator !== null && $candidate->id === $operator->id && ! $includeMe) {
                $protected[] = $candidate;

                continue;
            }

            if ($candidate->must_change_password) {
                $alreadyFlagged[] = $candidate;

                continue;
            }

            $toFlag[] = $candidate;
        }

        // Only an anomaly worth reporting when the run was supposed to cover
        // everyone. Narrowed by --role, an account with no role is simply out of
        // scope rather than unexplained.
        $roleless = ($roleNames === [] && ! $includeRoleless)
            ? User::query()->with('roles')->doesntHave('roles')->orderBy('email')->get()->all()
            : [];

        $this->printHeader($roleNames, $includeRoleless, $operator, $meOption, $dryRun);

        $this->printAccounts($dryRun, $toFlag, $alreadyFlagged, $protected, $excepted, $roleless);

        if ($roleless !== []) {
            $this->newLine();
            $this->warn(sprintf('%d account(s) hold no role at all and were NOT flagged.', count($roleless)));
            $this->line('  No deployment has borrower logins, so these are not members — they are staff');
            $this->line('  accounts whose role is missing. Look at them before you trust this run to have');
            $this->line('  covered everyone, then re-run with --include-roleless if they should be flagged.');
        }

        if ($dryRun) {
            // Suppressed at zero rather than printed with a 0 in it. A warning
            // that shouts about locking nobody out is noise on the run where
            // there is nothing to warn about, and noise is what gets skimmed on
            // the run where there is.
            if ($toFlag !== []) {
                $this->announceBlastRadius(count($toFlag), $includeMe, $operator);
            }

            $this->newLine();
            $this->info(sprintf(
                'DRY RUN — nothing was written. Would flag %d, already flagged %d, spared %d.',
                count($toFlag),
                count($alreadyFlagged),
                count($protected) + count($excepted) + count($roleless),
            ));
            $this->line('Re-run without --dry-run, adding --me=<your email>, to apply it.');

            return self::SUCCESS;
        }

        if ($toFlag === []) {
            $this->newLine();
            $this->info(sprintf(
                'Nothing to do: 0 flagged, %d already flagged, %d spared.',
                count($alreadyFlagged),
                count($protected) + count($excepted) + count($roleless),
            ));

            return self::SUCCESS;
        }

        $this->announceBlastRadius(count($toFlag), $includeMe, $operator);

        if (! $this->confirmToProceed('Forcing a password change on every privileged account')) {
            return self::FAILURE;
        }

        // Gated on the operator actually being in the cohort, not merely on the
        // flag being present. `--include-me` on a run that was never going to
        // reach them — already flagged, or narrowed away by --role — would
        // otherwise ask them to approve something that is not going to happen,
        // and a prompt that cries wolf is a prompt that gets answered without
        // being read.
        $selfIsBeingFlagged = $operator !== null && in_array(
            $operator->id,
            array_map(static fn (User $user) => $user->id, $toFlag),
            true,
        );

        if ($selfIsBeingFlagged && ! $this->confirmSelfLock($operator)) {
            return self::FAILURE;
        }

        // One transaction so the deployment is never left half-locked: either
        // every account in the cohort is flagged or none is, and an operator
        // reading the summary never has to wonder which half they got.
        DB::transaction(function () use ($toFlag): void {
            foreach ($toFlag as $user) {
                // forceFill(), not update(). `must_change_password` is outside
                // User::$fillable on purpose — read the comment there — so a
                // mass-assigned update() would drop the key without erroring,
                // save a row that changed nothing, and report success. That
                // silent no-op is the whole trap the column's unfillability is
                // designed to force into the open, and this is the open part.
                $user->forceFill(['must_change_password' => true])->save();
            }
        });

        // The per-user `updated` rows the Auditable trait writes carry no
        // user_id — auth() resolves to nobody on the console — so on their own
        // the trail would show a dozen accounts locked by no one. This row is
        // the attribution: it names the operator who ran it, which is exactly
        // what AuditLogService::log()'s $userId override exists for.
        AuditLogService::log(
            'password_change_required',
            newValues: [
                'flagged_user_ids' => array_map(static fn (User $user) => $user->id, $toFlag),
                'flagged' => count($toFlag),
                'already_flagged' => count($alreadyFlagged),
                'spared' => count($protected) + count($excepted) + count($roleless),
                'role_filter' => $roleNames === [] ? null : $roleNames,
            ],
            description: sprintf(
                'users:require-password-change flagged %d account(s) after the audit-log hash exposure',
                count($toFlag),
            ),
            userId: $operator?->id,
        );

        $this->newLine();
        $this->info(sprintf('Flagged %d account(s) — each must set a new password before it can use the app again.', count($toFlag)));

        $this->reportSkipped($alreadyFlagged, $protected, $excepted, $roleless);

        $this->newLine();
        $this->line('Tell them: sign in as normal, the app will ask for a new password, and nothing else');
        $this->line('will work until it is set. Re-running this command is safe — it will report them as');
        $this->line('already flagged and write nothing.');

        return self::SUCCESS;
    }

    /**
     * Trim, drop blanks and re-index a repeatable option.
     *
     * @return list<string>
     */
    private function normalisedList(mixed $values): array
    {
        return array_values(array_filter(
            array_map(trim(...), (array) $values),
            static fn (string $value) => $value !== '',
        ));
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    private function unknownRoles(array $roleNames): array
    {
        if ($roleNames === []) {
            return [];
        }

        return array_values(array_diff($roleNames, Role::query()->pluck('name')->all()));
    }

    /**
     * Resolve an account from an email address or a username.
     *
     * Both, because an operator on a console knows the username they log in
     * with (`super_admin`) at least as reliably as the mailbox on the account,
     * and every extra keystroke between them and a correct `--me` is a chance
     * to get it wrong.
     */
    private function findAccount(string $needle): ?User
    {
        return User::query()
            ->with('roles')
            ->where(function ($query) use ($needle): void {
                $query->where('email', $needle)->orWhere('username', $needle);
            })
            ->first();
    }

    /**
     * @param  list<string>  $roleNames
     * @return Collection<int, User>
     */
    private function candidates(array $roleNames, bool $includeRoleless): Collection
    {
        $query = User::query()->with('roles')->orderBy('email');

        if ($roleNames !== []) {
            $query->role($roleNames);
        } elseif (! $includeRoleless) {
            $query->has('roles');
        }

        return $query->get();
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function printHeader(array $roleNames, bool $includeRoleless, ?User $operator, string $meOption, bool $dryRun): void
    {
        $rule = match (true) {
            $roleNames !== [] => 'accounts holding '.implode(' or ', $roleNames),
            $includeRoleless => 'every account on this deployment, with or without a role',
            default => 'every account holding any role (there are no borrower logins)',
        };

        $this->newLine();
        $this->line($dryRun ? '=== DRY RUN — no writes ===' : '=== LIVE RUN — this writes ===');

        // now() is Asia/Manila (config/app.php), so this is local wall-clock
        // time. The zone is printed rather than an offset or a "PST" abbreviation
        // that also names a North American one.
        $this->line(sprintf('  When     : %s %s', now()->format('Y-m-d H:i:s'), config('app.timezone')));
        $this->line(sprintf('  Where    : %s (%s)', config('app.name'), config('app.env')));
        $this->line(sprintf('  Targeting: %s', $rule));
        $this->line(sprintf(
            '  Operator : %s',
            match (true) {
                $operator !== null => $operator->email.($this->option('include-me') ? ' (INCLUDED — will be locked)' : ' (protected)'),
                $meOption !== '' => 'declared as having no account here (--me='.self::NO_OPERATOR_ACCOUNT.')',
                default => 'not named — --me is required before this can run for real',
            },
        ));
    }

    /**
     * The point of the whole command: say exactly which accounts, by email and
     * role, and what is about to happen to each one.
     *
     * Every account the run considered gets a line, including the ones nothing
     * happens to. A list of only the casualties cannot be checked for the
     * omission that matters — the colleague who should have been on it.
     *
     * Future tense in BOTH modes, and that is not cosmetic. This table is
     * printed before confirmToProceed() and before the transaction, because an
     * operator has to see the cohort in order to answer the prompt about it. A
     * past-tense "FLAGGED" here would be a lie on every run that is then
     * cancelled at the prompt — and the run most likely to be cancelled is the
     * one where the list held a surprise. The past tense belongs to the summary
     * underneath, which is only reached once the write has happened.
     *
     * @param  list<User>  $toFlag
     * @param  list<User>  $alreadyFlagged
     * @param  list<User>  $protected
     * @param  list<User>  $excepted
     * @param  list<User>  $roleless
     */
    private function printAccounts(bool $dryRun, array $toFlag, array $alreadyFlagged, array $protected, array $excepted, array $roleless): void
    {
        $rows = [];

        foreach ($toFlag as $user) {
            $rows[] = $this->row($user, $dryRun ? 'would be FLAGGED' : 'will be FLAGGED');
        }

        foreach ($alreadyFlagged as $user) {
            $rows[] = $this->row($user, 'already flagged — no change');
        }

        foreach ($protected as $user) {
            $rows[] = $this->row($user, 'SPARED — your account (--me)');
        }

        foreach ($excepted as $user) {
            $rows[] = $this->row($user, 'spared — --except');
        }

        foreach ($roleless as $user) {
            $rows[] = $this->row($user, 'skipped — holds no role');
        }

        $this->newLine();

        if ($rows === []) {
            $this->line('  No account on this deployment matches.');

            return;
        }

        usort($rows, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        $this->table(['Email', 'Username', 'Role(s)', 'Status', $dryRun ? 'Would be' : 'Plan'], $rows);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function row(User $user, string $action): array
    {
        return [
            $user->email,
            $user->username,
            $user->roles->pluck('name')->sort()->implode(', ') ?: '(none)',
            (string) $user->status,
            $action,
        ];
    }

    /**
     * Say what this costs, in numbers and in plain words, before it is paid.
     *
     * Printed on the dry run AND immediately before the live write, because the
     * two are usually minutes and one shell-history arrow-up apart and the
     * second is the one that matters.
     */
    private function announceBlastRadius(int $count, bool $includeMe, ?User $operator): void
    {
        $total = User::query()->count();

        $this->newLine();
        $this->warn('  BLAST RADIUS');
        $this->line(sprintf(
            '  %d of the %d account(s) on this deployment will be locked out of the entire',
            $count,
            $total,
        ));
        $this->line('  application the moment this is written. Their tokens are not revoked, but');
        $this->line('  RequirePasswordChange answers 423 to every endpoint except GET /auth/me,');
        $this->line('  POST /auth/change-password and POST /auth/logout — so until each person has');
        $this->line('  chosen a new password, nobody can approve a loan, record a payment, release');
        $this->line('  cash or open a report. In practice this is a forced re-login for every');
        $this->line('  member of staff who uses this deployment, all at once.');
        $this->line('  Nothing here undoes it: an account clears its own flag only by changing its');
        $this->line('  own password through the app. Run it when someone can answer the phone.');

        if ($includeMe && $operator !== null) {
            // No "see below": the self-lock warning only exists on a live run,
            // and this block is printed in both modes.
            $this->line(sprintf(
                '  That count includes YOU (%s), so you will be locked out of the',
                $operator->email,
            ));
            $this->line('  deployment you are administering along with everybody else.');
        }
    }

    /**
     * The last gate in front of locking the operator out of the box they are
     * standing on.
     *
     * Deliberately separate from confirmToProceed(), which only speaks up in
     * production: locking yourself out of staging at 2am is not meaningfully
     * better, and this is the mistake the command was written to design
     * against. `--force` is the non-interactive way past it, and under
     * --no-interaction confirm() returns the default — false — so an
     * unattended run without --force cancels rather than guesses.
     */
    private function confirmSelfLock(User $operator): bool
    {
        $this->newLine();
        $this->error('  !!  --include-me: YOU ARE ABOUT TO LOCK YOUR OWN ACCOUNT  !!');
        $this->line(sprintf('  %s will get a 423 from every endpoint except the change-password screen,', $operator->email));
        $this->line('  including any further run of this command through the API. You can still fix it');
        $this->line('  by signing in and changing your own password — but do that before you close the');
        $this->line('  terminal, not after.');

        if ($this->option('force')) {
            return true;
        }

        if ($this->confirm(sprintf('Lock %s out until it changes its own password?', $operator->email), false)) {
            return true;
        }

        $this->line('Cancelled. Nothing was written.');

        return false;
    }

    /**
     * @param  list<User>  $alreadyFlagged
     * @param  list<User>  $protected
     * @param  list<User>  $excepted
     * @param  list<User>  $roleless
     */
    private function reportSkipped(array $alreadyFlagged, array $protected, array $excepted, array $roleless): void
    {
        $reasons = [
            'already flagged by an earlier run (no change made)' => count($alreadyFlagged),
            'your own account, protected by --me' => count($protected),
            'named in --except' => count($excepted),
            'holds no role, so outside the rule' => count($roleless),
        ];

        $skipped = array_sum($reasons);

        $this->line(sprintf('Skipped %d account(s):', $skipped));

        foreach ($reasons as $reason => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %d %s', $count, $reason));
            }
        }

        if ($skipped === 0) {
            $this->line('  (none)');
        }
    }
}
