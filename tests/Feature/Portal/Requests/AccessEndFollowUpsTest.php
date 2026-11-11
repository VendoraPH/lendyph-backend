<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\AuditLog;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsLocks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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
