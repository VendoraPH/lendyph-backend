<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\AuditLog;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The P3 final money check's Low follow-ups (#291): requests left open when
 * access ended without its clean-up, an `endFor` failure inside `disable()`,
 * and the member's own status read under its row lock during a payout.
 */
class AccessEndFollowUpsTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    // The nightly sweep ends requests whose portal access is already off.

    public function test_the_sweep_ends_open_requests_whose_access_was_turned_off_without_its_clean_up(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        // A crash between the commit and endFor(): access is off, the request still open.
        DB::table('portal_users')->where('id', $this->portalUser->id)
            ->update(['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => 'security_concern', 'disabled_at' => now()]);

        Artisan::call('portal:requests-sweep');

        $request->refresh();
        $this->assertSame([PortalRequest::STATUS_CANCELLED, null], [$request->status, $request->live_key]);
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['released', null], [$hold->status, $hold->released_by]);
        $this->assertSame('Cancelled: portal access was turned off. Please contact your branch.', $request->events()->orderByDesc('id')->value('message'));
        $this->assertTrue(AuditLog::query()->where('action', 'portal_request_cancelled')->whereNull('user_id')->whereNull('actor_type')->exists());
        $this->assertStringContainsString('ended 1', Artisan::output());

        $rows = AuditLog::query()->count();
        $events = $request->events()->count();
        Artisan::call('portal:requests-sweep');
        $this->assertSame($rows, AuditLog::query()->count(), 'Running it twice writes nothing more.');
        $this->assertSame($events, $request->events()->count());
        $this->assertStringContainsString('ended 0', Artisan::output());
        $this->assertSame(0, SavingsHold::query()->where('account_id', $account->id)->where('status', 'active')->count(), 'Nothing is held any more.');
    }

    public function test_the_sweep_cancels_a_second_check_left_waiting_after_access_ended(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $approvalId = (int) $request->fresh()->savings_approval_id;

        DB::table('portal_users')->where('id', $this->portalUser->id)
            ->update(['status' => PortalUser::STATUS_DISABLED, 'disabled_reason' => PortalUser::REASON_MEMBERSHIP_ENDED, 'disabled_at' => now()]);

        Artisan::call('portal:requests-sweep');
        Artisan::call('portal:requests-sweep');

        $this->assertSame('cancelled', SavingsApproval::query()->findOrFail($approvalId)->status);
        $this->assertSame(PortalRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'savings_approval_cancelled')->count(), 'Cancelled once.');
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame('30000.00', $this->balanceOf($account));
    }

    /**
     * A lockout isn't access turned off: staff unlock it, and a stranger
     * guessing passwords must not be able to cancel a member's request.
     */
    public function test_the_sweep_leaves_a_locked_members_requests_open(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        DB::table('portal_users')->where('id', $this->portalUser->id)->update(['status' => PortalUser::STATUS_LOCKED, 'hard_locked_at' => now()]);

        Artisan::call('portal:requests-sweep');

        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);
        $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    // An endFor() failure inside disable() never fails the staff action.

    public function test_access_is_still_turned_off_when_ending_the_requests_fails_and_the_sweep_finishes_it(): void
    {
        Exceptions::fake();
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->app->instance(SavingsLocks::class, new class extends SavingsLocks
        {
            public function account(int $id): SavingsAccount
            {
                throw new RuntimeException('Lock wait timeout exceeded');
            }
        });

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => false, 'reason' => 'security_concern'])
            ->assertOk();

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Lock wait timeout exceeded');
        $this->assertSame([PortalUser::STATUS_DISABLED, 'security_concern'], [$this->portalUser->fresh()->status, $this->portalUser->fresh()->disabled_reason]);
        $this->assertTrue(AuditLog::query()->where('action', 'portal_access_disabled')->where('user_id', $this->admin->id)->exists());
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status, 'Left for the sweep.');

        $this->app->forgetInstance(SavingsLocks::class);
        Artisan::call('portal:requests-sweep');

        $this->assertSame(PortalRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    public function test_a_membership_end_still_answers_normally_when_ending_the_requests_fails_and_the_sweep_finishes_it(): void
    {
        Exceptions::fake();
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->app->instance(SavingsLocks::class, new class extends SavingsLocks
        {
            public function account(int $id): SavingsAccount
            {
                throw new RuntimeException('Lock wait timeout exceeded');
            }
        });

        $this->freshRequest();
        $this->actingAs($this->admin)->patchJson("/api/borrowers/{$this->member->id}/end-membership", [
            'status' => 'deceased', 'ended_on' => '2026-11-10', 'reason' => 'Reported by the family.',
        ])->assertOk()->assertJsonPath('data.status', 'deceased');

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Lock wait timeout exceeded');
        $this->assertSame([PortalUser::STATUS_DISABLED, PortalUser::REASON_DECEASED], [$this->portalUser->fresh()->status, $this->portalUser->fresh()->disabled_reason]);
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status, 'Left for the sweep.');

        $this->app->forgetInstance(SavingsLocks::class);
        Artisan::call('portal:requests-sweep');

        $this->assertSame(PortalRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame('Cancelled: the membership ended.', $request->events()->orderByDesc('id')->value('message'));
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    /**
     * report() drops an HttpResponseException (such as a 409 from
     * GeneratedNumberWrite), so every failed endFor() also leaves its own
     * warning: ids and the exception class only, no personal data.
     */
    public function test_a_409_while_ending_the_requests_leaves_a_warning_on_every_path(): void
    {
        $logged = $this->captureLogs();
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->failLocksWith(fn () => Refuse::conflict('Someone else is saving a record. Try again.'));

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => false, 'reason' => 'security_concern'])
            ->assertOk();
        Artisan::call('portal:requests-sweep');

        $expected = ['portal_user_id' => $this->portalUser->id, 'request_ids' => [$request->id], 'exception' => HttpResponseException::class];
        $warnings = array_values(array_filter($logged(), fn (array $log): bool => $log['level'] === 'warning' && $log['message'] === 'portal endFor failed'));
        $this->assertCount(2, $warnings, 'One from disable(), one from the sweep.');

        foreach ($warnings as $warning) {
            $this->assertSame($expected, $warning['context']);
        }

        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);
    }

    public function test_a_409_while_ending_the_requests_after_a_membership_end_leaves_a_warning(): void
    {
        $logged = $this->captureLogs();
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->failLocksWith(fn () => Refuse::conflict('Someone else is saving a record. Try again.'));

        $this->freshRequest();
        $this->actingAs($this->admin)->patchJson("/api/borrowers/{$this->member->id}/end-membership", [
            'status' => 'deceased', 'ended_on' => '2026-11-10', 'reason' => 'Reported by the family.',
        ])->assertOk();

        $warnings = array_values(array_filter($logged(), fn (array $log): bool => $log['message'] === 'portal endFor failed'));
        $this->assertSame([['level' => 'warning', 'message' => 'portal endFor failed', 'context' => [
            'portal_user_id' => $this->portalUser->id, 'request_ids' => [$request->id], 'exception' => HttpResponseException::class,
        ]]], $warnings);
    }

    // Turning access back on never keeps a request from before it was turned off.

    public function test_access_stays_off_while_earlier_requests_cannot_be_closed_and_returns_once_they_are(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->failLocksWith(fn () => throw new RuntimeException('Lock wait timeout exceeded'));

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => false, 'reason' => 'security_concern'])
            ->assertOk();
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This member\'s earlier requests are still being closed. Try again in a moment.');
        $this->assertSame(PortalUser::STATUS_DISABLED, $this->portalUser->fresh()->status, 'Access stays off.');
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);
        $this->assertFalse(AuditLog::query()->where('action', 'portal_access_enabled')->exists());

        $this->app->forgetInstance(SavingsLocks::class);
        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => true])->assertOk();

        $this->assertSame(PortalUser::STATUS_ACTIVE, $this->portalUser->fresh()->status);
        $this->assertSame(PortalRequest::STATUS_CANCELLED, $request->fresh()->status, 'Closed before access returned.');
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        $cancelled = AuditLog::query()->where('action', 'portal_request_cancelled')->value('id');
        $enabled = AuditLog::query()->where('action', 'portal_access_enabled')->value('id');
        $this->assertLessThan($enabled, $cancelled);
    }

    public function test_access_turned_back_on_after_a_clean_turn_off_needs_nothing_more(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => false, 'reason' => 'member_request'])
            ->assertOk();
        $this->assertSame(PortalRequest::STATUS_CANCELLED, $request->fresh()->status);

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => true])->assertOk();
        $this->assertSame(PortalUser::STATUS_ACTIVE, $this->portalUser->fresh()->status);
    }

    // The member's status is read under its row lock during a payout.

    public function test_the_payout_locks_the_member_after_the_request_and_before_the_portal_account(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $locks = $this->recordLocks();
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();

        $this->assertLockOrder(['savings_accounts', 'portal_requests', 'borrowers', 'portal_users', 'savings_holds'], $locks());
    }

    public function test_the_second_check_locks_the_member_before_the_portal_account(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $approvalId = $request->fresh()->savings_approval_id;

        $locks = $this->recordLocks();
        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertSuccessful();

        $this->assertLockOrder(['savings_accounts', 'savings_approvals', 'borrowers', 'portal_users'], $locks());
        $this->assertSame(PortalRequest::STATUS_COMPLETED, $request->fresh()->status);
    }

    public function test_a_member_made_inactive_before_the_payout_reads_its_lock_gets_no_payout(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        // The deactivation commits first; the payout's locked read sees it.
        $this->member->refresh()->update(['status' => 'blacklisted']);

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(409);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    /** Make every savings account lock fail the way `$fail` does. */
    private function failLocksWith(Closure $fail): void
    {
        $this->app->instance(SavingsLocks::class, new class($fail) extends SavingsLocks
        {
            public function __construct(private readonly Closure $fail) {}

            public function account(int $id): SavingsAccount
            {
                ($this->fail)();

                throw new LogicException('The failure above always throws.');
            }
        });
    }

    /** @return callable(): list<array{level: string, message: string, context: array<string, mixed>}> */
    private function captureLogs(): callable
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        return function () use (&$logged): array {
            return $logged;
        };
    }

    /** @return callable(): list<string> the tables read FOR UPDATE, in order */
    private function recordLocks(): callable
    {
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && preg_match('/from `(\w+)`/', $sql, $table) === 1) {
                $locks[] = $table[1];
            }
        });

        return function () use (&$locks): array {
            return $locks;
        };
    }

    /**
     * Each table is first locked in this order.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $locks
     */
    private function assertLockOrder(array $expected, array $locks): void
    {
        $first = [];

        foreach ($expected as $table) {
            $at = array_search($table, $locks, true);
            $this->assertNotFalse($at, "{$table} is read with FOR UPDATE: ".implode(', ', $locks));
            $first[$table] = $at;
        }

        asort($first);
        $this->assertSame($expected, array_keys($first), 'Lock order: '.implode(', ', $locks));
    }
}
