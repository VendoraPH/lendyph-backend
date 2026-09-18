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
    {--skip-roleless : Proceed and leave role-less accounts unflagged, on purpose, having read the list.}
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
     * actually changes it. Closing the window completely means resetting every
     * password to a value nobody knows and distributing them out of band, which
     * locks the cooperative out of its own system until each person is reached
     * by phone. That is a business decision, not a command-line one; this is
     * the milder half the owner asked for and its limit should be read out
     * loud. Three parts of that limit, stated precisely rather than implied:
     *
     * - **The escape hatch is open to the attacker too.** Whoever cracked the
     *   hash knows `current_password`, so they can call `/auth/change-password`
     *   themselves: it clears the flag, sets a password only they know, and
     *   deletes every other token the victim holds. There is no self-service
     *   reset in this app, so the victim's only route back is an admin running
     *   `POST /users/{user}/reset-password`. This is still a net gain — before
     *   the flag a cracked credential bought silent, indefinite access and
     *   nobody ever found out; after it, using one requires an act that is
     *   recorded and that the victim notices the same morning. But it converts
     *   a silent compromise into a loud one rather than preventing it, so
     *   "I suddenly cannot log in" during the rollout window is a suspected
     *   account takeover, not a helpdesk ticket. Note that `audit_logs.ip_address`
     *   cannot tell the two apart on these deployments: TRUSTED_PROXIES is
     *   empty and every browser call arrives via the Next.js rewrite, so every
     *   row carries the frontend server's address.
     * - **"Locked out of everything" has two carve-outs.** `POST /auth/login`
     *   and `GET /auth/me` still answer, returning the account's own record.
     *   And signed KYC file links already minted keep streaming for the rest of
     *   their 30-minute TTL, because they authenticate by signature and key on
     *   `file_link_version`, which this command deliberately does not bump.
     * - **Clearing the flag only helps if the new password differs.** That is
     *   enforced by `different:current_password` in ChangePasswordRequest,
     *   added alongside this command. Without it the whole rotation completes
     *   with every exposed credential still live, and reports success.
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
            $this->line('Roles that DO exist here: '.implode(', ', $this->availableRoles()));
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
            $operator = $this->resolveAccountOption('me', $meOption);

            if ($operator === null) {
                $this->newLine();
                $this->line('Almost certainly a typo, and the consequence of guessing is the whole point of');
                $this->line('this guard: a --me that matches nobody protects nobody, so the run would flag you');
                $this->line('along with everyone else. Check the address, or pass --me='.self::NO_OPERATOR_ACCOUNT.' if you');
                $this->line('really have no account here.');

                return self::FAILURE;
            }

            // `--me` spares an account from THIS run; it cannot undo a previous
            // one, and it cannot undo an administrator's password reset, which
            // sets the very same flag. An operator whose account is already
            // flagged would otherwise read "SPARED — your account (--me)",
            // believe the guard had covered them, and close the terminal still
            // locked out of everything but the change-password screen — which
            // is the exact outcome the whole --me apparatus exists to prevent,
            // arrived at through the apparatus itself.
            if ($operator->must_change_password && ! $this->option('force')) {
                $this->error("Refusing to run: your own account ({$operator->email}) is ALREADY flagged.");
                $this->newLine();
                $this->line('--me only spares you from this run. It cannot clear a flag that is already set —');
                $this->line('nothing can, except changing your own password through the app. Left as it is, you');
                $this->line('are locked out of everything but the change-password screen, and this run would');
                $this->line('have told you that you were protected.');
                $this->newLine();
                $this->line('Change your own password first, then re-run. If you know this and want to proceed');
                $this->line('while still locked out, add --force.');

                return self::FAILURE;
            }
        }

        if ($includeMe && $operator === null) {
            $this->error('--include-me needs a --me account to include.');

            return self::FAILURE;
        }

        $spared = [];

        foreach ($this->normalisedList($this->option('except')) as $needle) {
            $account = $this->resolveAccountOption('except', $needle);

            if ($account === null) {
                $this->newLine();
                $this->line('An --except that matches nobody spares nobody — it would flag the very account');
                $this->line('you named to protect, and report success while doing it.');

                return self::FAILURE;
            }

            $spared[$account->id] = $account;
        }

        // --except wins over --include-me in the classification below, so this
        // combination is an instruction to lock yourself out and an instruction
        // to spare yourself, in the same command. The run would spare you and
        // then spend three separate lines — the header, the blast radius and
        // the confirmation prompt — telling you it was about to lock you out.
        // Wrong output at the exact moment the operator is reading carefully is
        // worse than no output, so neither reading is guessed at.
        if ($includeMe && $operator !== null && isset($spared[$operator->id])) {
            $this->error('Refusing to run: --include-me and --except both name your own account.');
            $this->newLine();
            $this->line('Those are opposite instructions. Drop whichever one you did not mean.');

            return self::FAILURE;
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
            $this->line('  accounts whose role is missing. Their hashes were in the same table as everybody');
            $this->line('  else\'s, because Auditable wrote a full user row on every create and update');
            $this->line('  regardless of role, so "no role" means unclassified rather than unexposed.');
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

            if ($meOption === '') {
                $this->newLine();
                $this->warn('This preview does not model --me, so it is one account WIDER than the real run.');
                $this->line('Your own row above says it would be flagged; with --me it will be spared. Preview');
                $this->line('again with the --me you intend to use if you want the two lists to match exactly.');
            }

            return self::SUCCESS;
        }

        // A real run stops here rather than exiting 0 with exposed accounts left
        // behind. This is a ten-box manual rollout: the only thing carried from
        // one box to the next is the operator's memory of what the last one
        // said, and "I left N accounts alone" that exits 0 is indistinguishable
        // from "I covered everyone" by the time you are on box seven. Every
        // other ambiguity in this command aborts and makes the human decide;
        // an unclassified account is an ambiguity.
        if ($roleless !== [] && ! $this->option('skip-roleless')) {
            $this->newLine();
            $this->error(sprintf('Refusing to run: %d account(s) hold no role and this run would leave them exposed.', count($roleless)));
            $this->newLine();
            $this->line('Decide, rather than letting the exit code say "done" while they are untouched:');
            $this->line('  --include-roleless  flag them too (they are staff; there are no borrower logins)');
            $this->line('  --skip-roleless     leave them, on purpose, having looked at the list above');

            return self::FAILURE;
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

        // `true` rather than the default callback, which only asks when
        // APP_ENV is literally `production`. Three reasons it must not be
        // environment-conditional: locking yourself out of staging at 2am is
        // not meaningfully better, which is the argument confirmSelfLock()
        // already makes below; the fleet is ten deployments and `.env.example`
        // ships APP_ENV=local, so one box with drifted env would lose its last
        // prompt precisely where it matters; and `--me=none` is a legitimate,
        // widest-possible-blast-radius invocation whose only other gate is that
        // the string is non-empty. --force still bypasses it, and under
        // --no-interaction the prompt takes its default, which is cancel.
        if (! $this->confirmToProceed('Forcing a password change on every privileged account', true)) {
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
        // reading the summary never has to wonder which half they got. The
        // attribution row below is inside it too, so the trail cannot end up
        // holding a receipt for a rotation that rolled back.
        DB::transaction(function () use ($toFlag, $alreadyFlagged, $protected, $excepted, $roleless, $operator, $meOption, $roleNames): void {
            foreach ($toFlag as $user) {
                // forceFill(), not update(). `must_change_password` is outside
                // User::$fillable on purpose — read the comment there — so a
                // mass-assigned update() would drop the key without erroring,
                // save a row that changed nothing, and report success. That
                // silent no-op is the whole trap the column's unfillability is
                // designed to force into the open, and this is the open part.
                $user->forceFill(['must_change_password' => true])->save();
            }

            // The per-user `updated` rows the Auditable trait writes carry no
            // user_id — auth() resolves to nobody on the console — so on their
            // own the trail would show a dozen accounts locked by no one. This
            // row is the attribution, which is what AuditLogService::log()'s
            // $userId and $ipAddress overrides exist for.
            //
            // `user_id` is an operator CLAIM, not an authenticated identity:
            // anyone with shell access can pass any --me. It is worth recording
            // and worth not over-reading. `deployment` is here because this is
            // a ten-box rollout and "which box was this?" is the one question a
            // later fleet-wide audit has to answer — the same lesson as the
            // private-files rollout, where the code shipped and one box served
            // borrower IDs for weeks afterwards.
            AuditLogService::log(
                'password_change_required',
                newValues: [
                    'flagged_user_ids' => array_map(static fn (User $user) => $user->id, $toFlag),
                    'flagged' => count($toFlag),
                    'already_flagged' => count($alreadyFlagged),
                    'spared' => count($protected) + count($excepted) + count($roleless),
                    'role_filter' => $roleNames === [] ? null : $roleNames,
                    'roleless_left_unflagged' => count($roleless),
                    'operator_claim' => $operator?->email ?? ('--me='.$meOption),
                    'deployment' => config('app.name').' ('.config('app.env').') @ '.gethostname(),
                ],
                description: sprintf(
                    'users:require-password-change flagged %d account(s) after the audit-log hash exposure',
                    count($toFlag),
                ),
                userId: $operator?->id,
                // Not request()->ip(). Laravel synthesises a console request
                // whose default makes that the literal 127.0.0.1, so the row
                // would claim the loopback address of whichever box ran it —
                // and on these deployments a real IP would be meaningless
                // anyway, because TRUSTED_PROXIES ships empty and every
                // browser call already records the frontend server's address.
                ipAddress: 'console',
            );
        });

        $this->newLine();
        $this->info(sprintf('Flagged %d account(s) — each must now change their password before the app will do anything else for them.', count($toFlag)));

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
     * The roles a user on this deployment can actually hold.
     *
     * Constrained to the `web` guard because that is the only guard in
     * config/auth.php and the one Spatie's `role()` scope resolves against. A
     * row with any other `guard_name` is constructible — `Role::$fillable`
     * includes it — and would otherwise pass validation here and then throw
     * RoleDoesNotExist from inside the query builder, turning a typo into a
     * stack trace instead of the sentence below.
     *
     * @return list<string>
     */
    private function availableRoles(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();
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

        return array_values(array_diff($roleNames, $this->availableRoles()));
    }

    /**
     * Resolve an account from an email address or a username.
     *
     * Both, because an operator on a console knows the username they log in
     * with (`super_admin`) at least as reliably as the mailbox on the account,
     * and every extra keystroke between them and a correct `--me` is a chance
     * to get it wrong.
     *
     * Returns every match rather than `first()`. Two rows cannot collide today
     * — `username` is `alpha_dash` so it cannot contain `@`, `email` must pass
     * the `email` rule, and both columns are UNIQUE — but that is a property of
     * two validation rule sets anyone may edit, not of this query, which is the
     * same argument `User::$fillable` makes about itself. Silently taking the
     * first of two would spare or flag an account the operator did not name.
     *
     * @return Collection<int, User>
     */
    private function findAccounts(string $needle): Collection
    {
        return User::query()
            ->with('roles')
            ->where(function ($query) use ($needle): void {
                $query->where('email', $needle)->orWhere('username', $needle);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Resolve exactly one account for an option, or explain why not.
     *
     * Both failure modes abort the run rather than shrug, because both end the
     * same way: an option that was supposed to name somebody names nobody, and
     * the account the operator meant to single out is treated like everyone
     * else while the command reports success.
     */
    private function resolveAccountOption(string $option, string $needle): ?User
    {
        $matches = $this->findAccounts($needle);

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            $this->error("Refusing to run: no account on this deployment matches --{$option}={$needle}.");

            return null;
        }

        $this->error("Refusing to run: --{$option}={$needle} matches {$matches->count()} accounts.");
        $this->newLine();
        $this->line('  '.$matches->map(fn (User $user) => "#{$user->id} {$user->email} / {$user->username}")->implode(PHP_EOL.'  '));
        $this->line('One account\'s username is another account\'s email address. Name the one you mean by id');
        $this->line('through the admin UI, or fix the collision, before running this.');

        return null;
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
        $this->newLine();
        $this->line('  Timing matters. Anyone still signed in goes straight to the change-password');
        $this->line('  screen without touching the login limiter. Anyone whose token has idled out');
        $this->line('  has to log in first, and login is metered at 40 attempts per 5 minutes for the');
        $this->line('  WHOLE deployment — trusted proxies are empty, so every staff member shares one');
        $this->line('  bucket. Run this mid-morning while people are working, not before opening, or');
        $this->line('  the entire coop queues behind that limit at 8am while trying to recover.');

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
