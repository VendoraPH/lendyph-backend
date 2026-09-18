<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `php artisan users:require-password-change`.
 *
 * The contract under test:
 *   --dry-run                  → prints the cohort, writes nothing
 *   a real run                 → users.must_change_password = true for the cohort
 *   a second real run          → no-op, reported as already flagged
 *   --me                       → the operator's own account is spared
 *   a flagged account, in HTTP → 423 everywhere but change-password
 *
 * The last one is the only assertion that proves the command did something that
 * matters. Everything above it checks a boolean column; that one checks that the
 * boolean actually locks the door, end to end, through the real middleware.
 */
class RequirePasswordChangeCommandTest extends TestCase
{
    use SetupLendyPH;

    private const COMMAND = 'users:require-password-change';

    private const PASSWORD = 'staff-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Run the command and hand back everything it printed.
     *
     * Preferred over `$this->artisan()->expectsOutputToContain()` for anything
     * asserting on wording: those expectations are ordered AND one-shot per
     * write, so a phrase that legitimately appears twice — "already flagged",
     * once in the table and once in the summary — is consumed by whichever came
     * first and the later assertion fails against output that plainly contains
     * it. Capturing the buffer instead makes the assertions order-independent
     * and the failure message show the real output.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runCommand(array $parameters = [], int $expectedExit = Command::SUCCESS): string
    {
        $exitCode = Artisan::call(self::COMMAND, $parameters);
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exitCode, "Unexpected exit code. Output was:\n{$output}");

        return $output;
    }

    private function makeStaff(string $username, ?string $role = 'cashier', array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'username' => $username,
            'email' => "{$username}@binhscoop.ph",
            'password' => Hash::make(self::PASSWORD),
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ], $overrides));

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    /** @return array<string, bool> keyed by username */
    private function flagState(): array
    {
        return User::query()->pluck('must_change_password', 'username')
            ->map(fn ($value) => (bool) $value)
            ->all();
    }

    /**
     * Authenticate the next request with a real bearer token.
     *
     * Both halves matter, for the reasons ForcePasswordChangeTest sets out:
     * `config('sanctum.guard')` is `['web']`, so without forgetting the guards
     * every call answers as the session's super_admin and passes for the wrong
     * reason; and RequestGuard memoises whoever it resolved first.
     */
    private function bearer(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function loginAndGetToken(User $user): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])
            ->postJson('/api/auth/login', [
                'login' => $user->username,
                'password' => self::PASSWORD,
            ])
            ->assertOk()
            ->json('token');
    }

    // ── The dry run ─────────────────────────────────────────────────────────

    public function test_the_dry_run_writes_nothing(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $admin = $this->makeStaff('mgarcia', 'admin');

        $before = $this->flagState();

        $this->runCommand(['--dry-run' => true]);

        // Not just "the two I made" — the WHOLE table is unchanged. A dry run
        // that writes anything at all has failed, including to an account this
        // test never mentioned.
        $this->assertSame($before, $this->flagState());
        $this->assertFalse($cashier->fresh()->must_change_password);
        $this->assertFalse($admin->fresh()->must_change_password);
        $this->assertSame(0, AuditLog::where('action', 'password_change_required')->count());
    }

    public function test_the_dry_run_names_every_account_it_would_flag_with_its_role(): void
    {
        $this->makeStaff('rsantos', 'cashier');
        $this->makeStaff('mgarcia', 'admin');

        // "email and role", because an operator checks this list against the
        // people they are about to phone, and neither a user id nor a bare
        // count can be checked against anything.
        $output = $this->runCommand(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('rsantos@binhscoop.ph', $output);
        $this->assertStringContainsString('cashier', $output);
        $this->assertStringContainsString('mgarcia@binhscoop.ph', $output);
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('nothing was written', $output);
    }

    public function test_the_dry_run_states_the_blast_radius_with_a_count(): void
    {
        $this->makeStaff('rsantos');
        $this->makeStaff('mgarcia', 'admin');

        $output = $this->runCommand(['--dry-run' => true]);

        // 3 = the two above plus the seeded super_admin. No --me is given on a
        // dry run, so nobody is spared and the count is the whole cohort.
        $this->assertStringContainsString('BLAST RADIUS', $output);
        $this->assertStringContainsString('3 of the 3 account(s)', $output);
        $this->assertStringContainsString('locked out of the entire', $output);
        $this->assertStringContainsString('forced re-login', $output);
    }

    public function test_the_dry_run_stays_quiet_about_a_blast_radius_when_there_is_nothing_to_flag(): void
    {
        // A warning that shouts about locking nobody out is noise on the run
        // where there is nothing to warn about, and noise is what gets skimmed
        // on the run where there is.
        $this->makeStaff('rsantos');
        $this->runCommand(['--me' => 'none']);

        $output = $this->runCommand(['--dry-run' => true]);

        $this->assertStringNotContainsString('BLAST RADIUS', $output);
        $this->assertStringContainsString('Would flag 0', $output);
    }

    // ── The real run ────────────────────────────────────────────────────────

    public function test_a_real_run_sets_the_flag_on_every_account_holding_a_role(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $collector = $this->makeStaff('lreyes', 'collector');

        $this->runCommand(['--me' => 'super_admin']);

        // The point of the whole exercise, and the specific failure this guards:
        // `must_change_password` is outside User::$fillable, so an update() here
        // would have dropped the key, touched nothing, and exited 0.
        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertTrue($collector->fresh()->must_change_password);
    }

    public function test_a_real_run_flags_inactive_accounts_too(): void
    {
        // They hold roles, so their hashes were in the same table. A
        // reactivation months from now must not hand back a password that is
        // already in somebody's wordlist.
        $suspended = $this->makeStaff('lreyes', 'collector', ['status' => 'inactive']);

        $this->runCommand(['--me' => 'super_admin']);

        $this->assertTrue($suspended->fresh()->must_change_password);
    }

    public function test_a_second_run_changes_nothing_and_says_so(): void
    {
        $cashier = $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'super_admin']);
        $flaggedAt = $cashier->fresh()->updated_at;

        $output = $this->runCommand(['--me' => 'super_admin']);

        $this->assertStringContainsString('already flagged', $output);
        $this->assertStringContainsString('Nothing to do: 0 flagged', $output);

        $cashier->refresh();
        $this->assertTrue($cashier->must_change_password);

        // Idempotent means untouched, not merely unchanged in value: a rewrite
        // would bump updated_at and put a second, meaningless `updated` row in
        // the trail for every member of staff.
        $this->assertEquals($flaggedAt, $cashier->updated_at);
        $this->assertSame(1, AuditLog::where('action', 'updated')
            ->where('auditable_id', $cashier->id)
            ->where('auditable_type', User::class)
            ->count());
    }

    public function test_a_second_run_writes_no_further_summary_row(): void
    {
        $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'super_admin']);
        $this->runCommand(['--me' => 'super_admin']);

        $this->assertSame(1, AuditLog::where('action', 'password_change_required')->count());
    }

    public function test_the_blast_radius_is_repeated_immediately_before_a_live_write(): void
    {
        // The dry run and the real run are usually minutes and one arrow-up
        // apart, and the second is the one that costs something.
        $this->makeStaff('rsantos');

        $output = $this->runCommand(['--me' => 'super_admin']);

        $this->assertStringContainsString('LIVE RUN', $output);
        $this->assertStringContainsString('BLAST RADIUS', $output);
    }

    // ── Not locking out the operator ────────────────────────────────────────

    public function test_the_me_account_is_spared(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $operator = $this->makeStaff('mgarcia', 'admin');

        $output = $this->runCommand(['--me' => 'mgarcia@binhscoop.ph']);

        $this->assertStringContainsString('SPARED', $output);
        $this->assertFalse($operator->fresh()->must_change_password);
        $this->assertTrue($cashier->fresh()->must_change_password);
    }

    public function test_the_me_account_can_be_named_by_username_as_well_as_email(): void
    {
        $operator = $this->makeStaff('mgarcia', 'admin');

        $this->runCommand(['--me' => 'mgarcia']);

        $this->assertFalse($operator->fresh()->must_change_password);
    }

    public function test_a_real_run_refuses_to_start_without_me(): void
    {
        $cashier = $this->makeStaff('rsantos');

        $output = $this->runCommand([], Command::FAILURE);

        $this->assertStringContainsString('--me is required for a real run', $output);
        $this->assertFalse($cashier->fresh()->must_change_password);
    }

    public function test_a_me_that_matches_nobody_aborts_rather_than_flagging_everyone(): void
    {
        // The typo case, and the reason --me is validated instead of merely
        // compared: `--me=mgarcia@binhscop.ph` protects nobody, so a command
        // that shrugged at it would lock the operator out while reporting
        // success.
        $this->makeStaff('rsantos');

        $output = $this->runCommand(['--me' => 'mgarcia@binhscop.ph'], Command::FAILURE);

        $this->assertStringContainsString('no account on this deployment matches --me', $output);
        $this->assertSame([], array_filter($this->flagState()));
    }

    public function test_me_none_is_the_documented_way_to_declare_you_have_no_account_here(): void
    {
        $cashier = $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'none']);

        $this->assertTrue($cashier->fresh()->must_change_password);
    }

    public function test_include_me_needs_a_me_to_include(): void
    {
        $output = $this->runCommand(
            ['--include-me' => true, '--dry-run' => true],
            Command::FAILURE,
        );

        $this->assertStringContainsString('--include-me needs a --me account', $output);
    }

    public function test_include_me_asks_first_and_a_refusal_writes_nothing_at_all(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $operator = $this->makeStaff('mgarcia', 'admin');

        $this->artisan(self::COMMAND, ['--me' => 'mgarcia', '--include-me' => true])
            ->expectsConfirmation('Lock mgarcia@binhscoop.ph out until it changes its own password?', 'no')
            ->assertFailed();

        // The refusal cancels the RUN, not just the operator's own row. Half a
        // cohort flagged after a declined prompt is a worse state than either
        // end of it, and the operator declined without having said which half.
        $this->assertFalse($operator->fresh()->must_change_password);
        $this->assertFalse($cashier->fresh()->must_change_password);
    }

    public function test_include_me_proceeds_when_the_operator_confirms(): void
    {
        $operator = $this->makeStaff('mgarcia', 'admin');

        $this->artisan(self::COMMAND, ['--me' => 'mgarcia', '--include-me' => true])
            ->expectsConfirmation('Lock mgarcia@binhscoop.ph out until it changes its own password?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($operator->fresh()->must_change_password);
    }

    public function test_include_me_with_force_skips_the_prompt_and_flags_the_operator(): void
    {
        // The unattended path. --force is the only way past the self-lock
        // question, so an automated run cannot wander into it by default.
        $operator = $this->makeStaff('mgarcia', 'admin');

        $output = $this->runCommand([
            '--me' => 'mgarcia',
            '--include-me' => true,
            '--force' => true,
        ]);

        $this->assertStringContainsString('YOU ARE ABOUT TO LOCK YOUR OWN ACCOUNT', $output);
        $this->assertTrue($operator->fresh()->must_change_password);
    }

    public function test_include_me_does_not_ask_when_the_operator_is_not_actually_in_the_cohort(): void
    {
        // The operator is already flagged, so --include-me has nothing to do to
        // them. Asking anyway would be a prompt that cries wolf, and a prompt
        // that cries wolf is one that gets answered without being read.
        //
        // runCommand() goes through Artisan::call with no answer queued, so an
        // unexpected prompt would take its default — "no" — and cancel the run.
        // A successful exit here IS the assertion that nothing was asked.
        $operator = $this->makeStaff('mgarcia', 'admin');
        $this->runCommand(['--me' => 'mgarcia', '--include-me' => true, '--force' => true]);
        $this->assertTrue($operator->fresh()->must_change_password);

        $latecomer = $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'mgarcia', '--include-me' => true]);

        $this->assertTrue($latecomer->fresh()->must_change_password);
    }

    // ── Narrowing and sparing ───────────────────────────────────────────────

    public function test_the_role_filter_narrows_the_cohort(): void
    {
        $cashier = $this->makeStaff('rsantos', 'cashier');
        $collector = $this->makeStaff('lreyes', 'collector');

        $this->runCommand(['--me' => 'super_admin', '--role' => ['cashier']]);

        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertFalse($collector->fresh()->must_change_password);
        $this->assertFalse($this->admin->fresh()->must_change_password);
    }

    public function test_the_role_filter_is_repeatable(): void
    {
        $cashier = $this->makeStaff('rsantos', 'cashier');
        $collector = $this->makeStaff('lreyes', 'collector');
        $processor = $this->makeStaff('jtorres', 'loan_processor');

        $this->runCommand(['--me' => 'super_admin', '--role' => ['cashier', 'collector']]);

        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertTrue($collector->fresh()->must_change_password);
        $this->assertFalse($processor->fresh()->must_change_password);
    }

    public function test_an_unknown_role_aborts_instead_of_quietly_matching_nobody(): void
    {
        $cashier = $this->makeStaff('rsantos');

        // A role that exists nowhere would flag nobody and exit 0, which on a
        // terminal is indistinguishable from "done". Role names differ per
        // deployment, so this is the likely mistake, not an exotic one.
        $output = $this->runCommand(
            ['--me' => 'super_admin', '--role' => ['cashierr']],
            Command::FAILURE,
        );

        $this->assertStringContainsString('no such role on this deployment', $output);
        $this->assertStringContainsString('Roles that DO exist here', $output);
        $this->assertFalse($cashier->fresh()->must_change_password);
    }

    public function test_except_spares_a_named_account(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $collector = $this->makeStaff('lreyes', 'collector');

        $this->runCommand([
            '--me' => 'super_admin',
            '--except' => ['lreyes@binhscoop.ph'],
        ]);

        $this->assertTrue($cashier->fresh()->must_change_password);
        $this->assertFalse($collector->fresh()->must_change_password);
    }

    public function test_an_except_that_matches_nobody_aborts(): void
    {
        $cashier = $this->makeStaff('rsantos');

        $output = $this->runCommand(
            ['--me' => 'super_admin', '--except' => ['lreyes@binhscop.ph']],
            Command::FAILURE,
        );

        $this->assertStringContainsString('no account on this deployment matches --except', $output);
        $this->assertFalse($cashier->fresh()->must_change_password);
    }

    // ── Accounts holding no role ────────────────────────────────────────────

    public function test_an_account_with_no_role_is_left_alone_and_reported(): void
    {
        $orphan = $this->makeStaff('orphan', null);

        $output = $this->runCommand(['--me' => 'super_admin']);

        $this->assertStringContainsString('hold no role at all and were NOT flagged', $output);
        $this->assertFalse($orphan->fresh()->must_change_password);
    }

    public function test_include_roleless_takes_them_too(): void
    {
        $orphan = $this->makeStaff('orphan', null);

        $this->runCommand(['--me' => 'super_admin', '--include-roleless' => true]);

        $this->assertTrue($orphan->fresh()->must_change_password);
    }

    public function test_include_roleless_cannot_be_combined_with_role(): void
    {
        $output = $this->runCommand(
            ['--dry-run' => true, '--include-roleless' => true, '--role' => ['cashier']],
            Command::FAILURE,
        );

        $this->assertStringContainsString('cannot be combined with --role', $output);
    }

    // ── The summary ─────────────────────────────────────────────────────────

    public function test_the_summary_counts_the_skipped_and_gives_a_reason_for_each(): void
    {
        $this->makeStaff('rsantos');
        $this->makeStaff('lreyes', 'collector');
        $this->makeStaff('orphan', null);
        $operator = $this->makeStaff('mgarcia', 'admin');

        // Flag one of them first so the second run has one of every category:
        // flagged, already flagged, --me, --except and roleless.
        $this->runCommand(['--me' => 'mgarcia', '--role' => ['cashier']]);

        $output = $this->runCommand([
            '--me' => 'mgarcia',
            '--except' => ['lreyes@binhscoop.ph'],
        ]);

        $this->assertStringContainsString('Flagged 1 account(s)', $output);
        $this->assertStringContainsString('Skipped 4 account(s):', $output);
        $this->assertStringContainsString('1 already flagged by an earlier run', $output);
        $this->assertStringContainsString('1 your own account, protected by --me', $output);
        $this->assertStringContainsString('1 named in --except', $output);
        $this->assertStringContainsString('1 holds no role, so outside the rule', $output);
        $this->assertFalse($operator->fresh()->must_change_password);
    }

    // ── The audit trail ─────────────────────────────────────────────────────

    public function test_the_run_is_attributed_to_the_operator_in_the_audit_log(): void
    {
        $operator = $this->makeStaff('mgarcia', 'admin');
        $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'mgarcia']);

        // The per-user `updated` rows carry no user_id — auth() resolves to
        // nobody on the console — so without this row the trail would show a
        // cohort of accounts locked by no one.
        $summary = AuditLog::where('action', 'password_change_required')->sole();

        $this->assertSame($operator->id, $summary->user_id);
        $this->assertSame(2, $summary->new_values['flagged']);
    }

    public function test_the_run_writes_no_password_hash_into_the_audit_log(): void
    {
        // The incident that caused this command in the first place. Flagging
        // goes through Eloquent, so every save fires the Auditable `updated`
        // hook, which stores whole model rows.
        $this->makeStaff('rsantos');

        $this->runCommand(['--me' => 'super_admin']);

        $this->assertSame(0, AuditLog::query()
            ->where('old_values', 'like', '%$2y$%')
            ->orWhere('new_values', 'like', '%$2y$%')
            ->count());
    }

    // ── End to end: the flag actually locks the door ────────────────────────

    public function test_a_flagged_account_is_refused_over_http_but_can_still_change_its_password(): void
    {
        $cashier = $this->makeStaff('rsantos');
        $token = $this->loginAndGetToken($cashier);

        // Normal access first, so the 423 below cannot be the endpoint simply
        // being unreachable for this role.
        $this->bearer($token)->getJson('/api/borrowers')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->runCommand(['--me' => 'super_admin']);

        $this->bearer($token)->getJson('/api/borrowers')
            ->assertStatus(423)
            ->assertJsonPath('code', 'password_change_required')
            ->assertJsonPath('must_change_password', true);

        // The escape hatch is open, and the token still works to reach it —
        // the command deliberately does not revoke tokens, so the frontend can
        // walk the user from the 423 straight to the change-password screen
        // instead of dropping them at a login form whose only known password is
        // the one being retired.
        $this->bearer($token)->postJson('/api/auth/change-password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'chosen-by-me-456',
            'new_password_confirmation' => 'chosen-by-me-456',
        ])->assertOk();

        $this->assertFalse($cashier->fresh()->must_change_password);
        $this->assertTrue(Hash::check('chosen-by-me-456', $cashier->fresh()->password));

        // ...and normal access is back, on the same token.
        $this->bearer($token)->getJson('/api/borrowers')->assertOk();
    }

    public function test_an_operator_spared_by_me_keeps_working_over_http(): void
    {
        // The hazard --me exists for, proven at the HTTP layer rather than on
        // the column: the person who ran the command can still use the
        // deployment they just locked everybody else out of.
        $operator = $this->makeStaff('mgarcia', 'admin');
        $token = $this->loginAndGetToken($operator);

        $this->app['auth']->forgetGuards();
        $this->runCommand(['--me' => 'mgarcia']);

        $this->bearer($token)->getJson('/api/borrowers')->assertOk();
    }

    public function test_the_dry_run_leaves_a_logged_in_session_working(): void
    {
        // The safety property stated as the user experiences it: a preview
        // costs nobody their afternoon.
        $cashier = $this->makeStaff('rsantos');
        $token = $this->loginAndGetToken($cashier);

        $this->app['auth']->forgetGuards();
        $this->runCommand(['--dry-run' => true]);

        $this->bearer($token)->getJson('/api/borrowers')->assertOk();
    }
}
