<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\AuditLog;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\Portal\Requests\StaffQueue;
use App\Services\Savings\Api\AccountCloser;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The P3 money review's findings (one Medium, six Lows), each reproduced
 * here before its fix.
 */
class RequestReviewFixesTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    // M1: no payout once the member's access or membership has ended.

    public function test_access_turned_off_for_any_reason_cancels_open_requests_and_releases_their_holds(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->freshRequest();
        $this->actingAs($this->admin)->putJson("/api/portal-admin/members/{$this->member->id}/access", ['enabled' => false, 'reason' => 'security_concern'])->assertOk();

        $request->refresh();
        $this->assertSame([PortalRequest::STATUS_CANCELLED, null], [$request->status, $request->live_key]);
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['released', null], [$hold->status, $hold->released_by]);
        $this->assertTrue(AuditLog::query()->where('action', 'portal_request_cancelled')->whereNull('user_id')->whereNull('actor_type')->exists());
    }

    public function test_a_deceased_member_waiting_at_the_second_check_gets_no_payout(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $request->refresh();

        $this->freshRequest();
        $this->actingAs($this->admin);
        $this->member->refresh()->update(['status' => 'deceased']);

        $approval = SavingsApproval::query()->findOrFail($request->savings_approval_id);
        $this->assertSame(['cancelled', null], [$approval->status, $approval->live_key]);
        $this->assertSame(PortalRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        $this->assertTrue(AuditLog::query()->where('action', 'savings_approval_cancelled')->whereNull('user_id')->exists());

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approval->id}/approve")->assertStatus(409);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame('30000.00', $this->balanceOf($account));
    }

    public function test_an_approve_after_the_access_or_membership_ends_answers_409(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $first = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        // A bulk update fires no model events, so nothing cancelled the request.
        DB::table('portal_users')->where('id', $this->portalUser->id)->update(['status' => PortalUser::STATUS_DISABLED]);
        $this->decide($first, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(409)
            ->assertJsonPath('message', 'This member\'s portal access or membership isn\'t active, so the request can\'t be paid. Reject it instead.');

        DB::table('portal_users')->where('id', $this->portalUser->id)->update(['status' => PortalUser::STATUS_ACTIVE]);
        DB::table('borrowers')->where('id', $this->member->id)->update(['status' => 'inactive']);
        $this->decide($first, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(409);

        $this->assertSame('active', SavingsHold::query()->findOrFail($first->savings_hold_id)->status);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->decide($first, ['decision' => 'reject', 'message' => 'Membership inactive.'])->assertOk();
    }

    public function test_the_second_check_pays_nothing_once_a_bulk_update_ended_the_membership(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $approvalId = $request->fresh()->savings_approval_id;

        // A query-builder update fires no model events, so nothing cancelled the approval.
        DB::table('borrowers')->where('id', $this->member->id)->update(['status' => 'deceased']);

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This member\'s portal access or membership isn\'t active, so the request can\'t be paid. Reject it instead.');

        DB::table('borrowers')->where('id', $this->member->id)->update(['status' => 'active']);
        DB::table('portal_users')->where('id', $this->portalUser->id)->update(['status' => PortalUser::STATUS_DISABLED]);
        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertStatus(409);

        $this->assertSame('pending', SavingsApproval::query()->findOrFail($approvalId)->status);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame('30000.00', $this->balanceOf($account));
        $this->assertSame(PortalRequest::STATUS_APPROVED, $request->fresh()->status);
    }

    /**
     * The payout reads the portal account under its row lock, after the
     * account and the request (lock order: account, request, portal user,
     * hold), so it waits for a disable() in flight and sees its outcome.
     */
    public function test_the_payout_reads_the_portal_account_under_its_lock_after_the_account_and_request(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update')) {
                preg_match('/from `(\w+)`/', $sql, $table);
                $locks[] = $table[1] ?? '?';
            }
        });

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();

        $at = fn (string $table): int|false => array_search($table, $locks, true);
        $this->assertNotFalse($at('portal_users'), 'The portal account is read with FOR UPDATE: '.implode(', ', $locks));
        $this->assertLessThan($at('portal_users'), $at('savings_accounts'));
        $this->assertLessThan($at('portal_users'), $at('portal_requests'));
        $this->assertGreaterThan($at('portal_users'), $at('savings_holds'));
    }

    /**
     * A membership ended inside a transaction (which holds the member and
     * portal account rows) ends the member's requests only once it commits,
     * so it never takes the savings account and request locks while holding
     * those rows: the opposite order to a payout.
     */
    public function test_requests_end_only_after_the_membership_end_commits(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->freshRequest();
        $this->actingAs($this->admin);
        DB::transaction(function () use ($request): void {
            $this->member->refresh()->update(['status' => 'deceased']);

            $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status, 'Nothing is ended before the commit.');
            $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        });

        $this->assertSame(PortalRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    // L1: a member's request hold blocking a close says what to do.

    public function test_a_close_blocked_by_a_member_request_hold_says_decide_the_request(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $this->submit($this->withdrawalBody($account, '100.00'))->assertCreated();

        $blockers = implode(' ', app(AccountCloser::class)->blockers($account->refresh()));
        $this->assertStringContainsString('Decide the member\'s request first', $blockers);
        $this->assertStringNotContainsString('Release the hold first', $blockers);
    }

    // L2: each HoldDesk path takes only its own hold types.

    public function test_hold_desk_paths_take_only_their_own_types(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $manual = DB::transaction(fn () => app(HoldDesk::class)->place($account->id, ['type' => 'manual', 'amount' => '10.00', 'reason' => 'x'], $this->admin));

        $this->assertThrows(fn () => DB::transaction(fn () => app(HoldDesk::class)->place($account->id, ['type' => SavingsHold::TYPE_MEMBER_REQUEST, 'amount' => '10.00', 'reason' => 'x'], $this->admin)), LogicException::class);
        $this->assertThrows(fn () => DB::transaction(fn () => app(HoldDesk::class)->releaseForRequest($manual, 'x', null)), LogicException::class);
        $this->assertSame('active', $manual->fresh()->status);
    }

    // L3: each idempotency store takes only its own kind of caller.

    public function test_each_idempotency_store_takes_only_its_own_caller(): void
    {
        $request = Request::create('/api/x', 'POST', [], [], [], ['HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()]);
        $write = fn () => response()->json(['ok' => true], 201);

        $request->setUserResolver(fn () => $this->admin);
        $this->assertThrows(fn () => app(IdempotentWrite::class)->run($request, $write, store: IdempotentWrite::STORE_PORTAL), LogicException::class);

        $request->setUserResolver(fn () => $this->portalUser);
        $this->assertThrows(fn () => app(IdempotentWrite::class)->run($request, $write), LogicException::class);
        $this->assertSame(0, DB::table('savings_idempotency_keys')->count() + DB::table('portal_idempotency_keys')->count());
    }

    // L4: a replay is answered before checks that depend on today's state.

    public function test_a_replay_after_the_request_was_decided_returns_the_stored_201(): void
    {
        $key = (string) Str::uuid();
        $body = ['type' => PortalRequest::TYPE_PROFILE_UPDATE, 'details' => ['city' => 'Pasig']];
        $first = $this->submit($body, $key)->assertCreated();
        $this->decide((int) $first->json('data.id'), ['decision' => 'approve'])->assertOk();
        $this->assertSame('Pasig', $this->member->fresh()->city);

        $this->submit($body, $key)
            ->assertCreated()
            ->assertHeader(IdempotentWrite::REPLAY_HEADER, 'true')
            ->assertJsonPath('data.reference', $first->json('data.reference'));
        $this->assertSame(1, PortalRequest::query()->count());
    }

    // L5: the decision's audit row reads "from" off the locked row.

    public function test_the_approval_audit_row_reads_its_from_status_off_the_locked_row(): void
    {
        $request = $this->submitted($this->submit(['type' => PortalRequest::TYPE_PROFILE_UPDATE, 'details' => ['city' => 'Pasig']])->assertCreated());
        // Read before another staff user moved it: the queue gets a stale copy, as in a race.
        $stale = PortalRequest::query()->findOrFail($request->id);
        DB::table('portal_requests')->where('id', $request->id)->update(['status' => PortalRequest::STATUS_UNDER_REVIEW]);
        $this->actingAs($this->admin);

        DB::transaction(fn () => app(StaffQueue::class)->approve($stale, ['decision' => 'approve'], $this->admin));

        $row = AuditLog::query()->where('action', 'portal_request_decided')->sole();
        $this->assertSame(['status' => 'under_review'], $row->old_values);
    }

    // L6: the tiers and the stale paths.

    public function test_a_withdrawal_above_fifty_thousand_needs_two_different_checkers(): void
    {
        $account = $this->account();
        $this->fund($account, 10_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '50000.01'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202)
            ->assertJsonPath('data.approval.required_checkers', 2);
        $approvalId = $request->fresh()->savings_approval_id;

        $first = $this->checker();
        $this->freshRequest();
        $this->actingAs($first)->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk();
        $this->assertSame(PortalRequest::STATUS_APPROVED, $request->fresh()->status, 'Still waiting for the second checker.');
        $this->freshRequest();
        $this->actingAs($first)->postJson("/api/savings/approvals/{$approvalId}/approve")->assertForbidden();

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$approvalId}/approve")->assertOk();
        $this->assertSame(PortalRequest::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertSame(1, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_a_second_staff_approval_answers_409_and_posts_nothing_more(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()], $this->queueStaff(['savings:transact']))->assertStatus(409);

        $this->assertSame(1, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_an_approve_after_the_hold_lapsed_but_before_the_sweep_answers_409(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->on('2026-11-18', function () use ($request): void {
            $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout(['date' => '2026-11-18'])])->assertStatus(409);
        });

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);
    }
}
