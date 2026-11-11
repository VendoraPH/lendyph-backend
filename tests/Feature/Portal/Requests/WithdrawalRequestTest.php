<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Savings\IdempotentWrite;
use App\Services\Savings\SavingsLedger;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A member's savings withdrawal request (portal design §6): it reserves the
 * money with a `member_request` hold at once, and a staff decision posts it
 * through the savings teller and its approval tiers. Reject, cancel, expiry
 * and the end of portal access each release the hold, once.
 */
class WithdrawalRequestTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_a_submitted_withdrawal_places_a_member_request_hold_and_lowers_available_at_once(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);

        $response = $this->submit($this->withdrawalBody($account, '1200.50', ['remarks' => 'School fees']))
            ->assertCreated()
            ->assertJsonPath('data.type', 'savings_withdrawal')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.amount', '1200.50')
            ->assertJsonPath('data.expires_on', '2026-11-17')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.details.account_number', $account->account_number);

        $this->assertMatchesRegularExpression('/^REQ-20261110-\d{6}$/', (string) $response->json('data.reference'));

        $request = $this->submitted($response);
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['member_request', '1200.50', 'active', '2026-11-17', PortalRequest::class, $request->id, null],
            [$hold->type, (string) $hold->amount, $hold->status, $hold->expires_on->toDateString(), $hold->source_type, $hold->source_id, $hold->created_by]);
        $this->assertSame('w:'.$account->id, $request->live_key);

        $this->member('GET', '/api/portal/requests/options')->assertOk()
            ->assertJsonPath('data.withdrawal_accounts.0.withdrawable', '3799.50');

        $row = AuditLog::query()->where('action', 'portal_request_submitted')->sole();
        $this->assertSame([null, 'member', $this->portalUser->id], [$row->user_id, $row->actor_type, $row->portal_user_id]);
        $this->assertSame(1, PortalRequestEvent::query()->where('request_id', $request->id)->where('actor_type', 'member')->count());
    }

    public function test_a_replayed_key_posts_once_and_the_same_key_with_another_body_is_422(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $key = (string) Str::uuid();

        $first = $this->submit($this->withdrawalBody($account, '100.00'), $key)->assertCreated();
        $this->submit($this->withdrawalBody($account, '100.00'), $key)
            ->assertCreated()
            ->assertHeader(IdempotentWrite::REPLAY_HEADER, 'true')
            ->assertJsonPath('data.reference', $first->json('data.reference'));
        $this->submit($this->withdrawalBody($account, '100.01'), $key)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, PortalRequest::query()->count());
        $this->assertSame(1, SavingsHold::query()->count());
        $this->assertDatabaseHas('portal_idempotency_keys', ['portal_user_id' => $this->portalUser->id, 'key' => $key]);
        $this->assertDatabaseMissing('savings_idempotency_keys', ['key' => $key]);
    }

    public function test_one_open_request_per_account_and_never_above_withdrawable(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $this->product->update(['maintaining_balance' => '100.00']);

        $this->submit($this->withdrawalBody($account, '900.01'))->assertUnprocessable()->assertJsonValidationErrors('details.amount');
        $this->submit($this->withdrawalBody($account, '900.00'))->assertCreated();
        $this->submit($this->withdrawalBody($account, '1.00'))->assertStatus(409);
    }

    public function test_frozen_dormant_another_members_and_off_product_accounts_are_refused(): void
    {
        $frozen = $this->account();
        $this->fund($frozen, 100_000);
        $frozen->forceFill(['status' => 'frozen'])->save();
        $errors = $this->submit($this->withdrawalBody($frozen, '10.00'))->assertUnprocessable()->json('errors');
        $this->assertStringContainsString('visit your branch', $errors['details.account_id'][0]);

        $other = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $theirs = $this->account(['borrower_id' => $other->id]);
        $this->fund($theirs, 100_000);
        $this->submit($this->withdrawalBody($theirs, '10.00'))->assertUnprocessable()->assertJsonValidationErrors('details.account_id');

        $mine = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        $this->fund($mine, 100_000);
        $mine->product->forceFill(['portal_withdrawal_requests' => false])->save();
        $this->submit($this->withdrawalBody($mine, '10.00'))->assertUnprocessable()->assertJsonValidationErrors('details.account_id');

        $this->assertSame(0, SavingsHold::query()->count());
    }

    public function test_a_bank_transfer_needs_its_account_details_and_they_are_masked_for_the_member(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);

        $this->submit($this->withdrawalBody($account, '10.00', ['method' => 'bank_transfer']))
            ->assertUnprocessable()->assertJsonValidationErrors(['details.bank_name', 'details.account_name', 'details.account_number']);

        $this->submit($this->withdrawalBody($account, '10.00', ['method' => 'bank_transfer', 'bank_name' => 'BDO', 'account_name' => 'Maria Santos', 'account_number' => '001234567890']))
            ->assertCreated()
            ->assertJsonPath('data.details.payout.account_number', '•••• 7890');
    }

    public function test_approval_at_tier_one_releases_the_hold_and_posts_through_the_teller(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '10000.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.transaction.debit', '10000.00');

        $request->refresh();
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['released', $this->admin->id], [$hold->status, $hold->released_by]);
        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame([$row->id, null], [$request->savings_transaction_id, $request->live_key]);
        $this->assertSame([['2050', 1_000_000, 0], ['1010', 0, 1_000_000]], $this->journalLines($this->journalFor($row)));
        $this->assertSame('10000.00', $this->balanceOf($account));

        $notice = DatabaseNotification::query()->where('notifiable_type', $this->portalUser->getMorphClass())->where('notifiable_id', $this->portalUser->id)->where('type', 'portal_requests')->latest('created_at')->first();
        $this->assertNotNull($notice, 'The member was told.');
    }

    public function test_approval_above_tier_one_waits_for_a_checker_and_the_maker_cannot_check(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '10000.01'))->assertCreated());
        $maker = $this->queueStaff(['savings:view', 'savings:transact', 'savings:approve']);

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()], $maker)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approval.status', 'pending')
            ->assertJsonPath('data.approval.required_checkers', 1);

        $request->refresh();
        $this->assertSame('released', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());

        $this->freshRequest();
        $this->actingAs($maker)->postJson("/api/savings/approvals/{$request->savings_approval_id}/approve")->assertForbidden();

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$request->savings_approval_id}/approve")->assertOk();

        $request->refresh();
        $row = SavingsTransaction::query()->where('type', 'withdrawal')->sole();
        $this->assertSame([PortalRequest::STATUS_COMPLETED, $row->id, null], [$request->status, $request->savings_transaction_id, $request->live_key]);
        $this->assertSame('19999.99', $this->balanceOf($account));
    }

    public function test_a_checker_rejecting_the_tier_two_approval_rejects_the_request_with_no_money_moved(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $request->refresh();

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$request->savings_approval_id}/reject", ['reason' => 'Signature mismatch'])->assertOk();

        $request->refresh();
        $this->assertSame([PortalRequest::STATUS_REJECTED, null], [$request->status, $request->live_key]);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
        $this->assertSame('30000.00', $this->balanceOf($account));
    }

    public function test_staff_reject_and_member_cancel_each_release_the_hold(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);

        $rejected = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->decide($rejected, ['decision' => 'reject'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->decide($rejected, ['decision' => 'reject', 'message' => 'Please visit the branch with an ID.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $hold = SavingsHold::query()->findOrFail($rejected->savings_hold_id);
        $this->assertSame(['released', $this->admin->id], [$hold->status, $hold->released_by]);

        $cancelled = $this->submitted($this->submit($this->withdrawalBody($account, '200.00'))->assertCreated());
        $this->member('POST', "/api/portal/requests/{$cancelled->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $hold = SavingsHold::query()->findOrFail($cancelled->savings_hold_id);
        $this->assertSame(['released', null], [$hold->status, $hold->released_by]);
        $this->member('POST', "/api/portal/requests/{$cancelled->id}/cancel")->assertStatus(409);

        $this->assertSame('1000.00', $this->balanceOf($account));
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_no_money_moves_on_a_frozen_account_and_the_hold_stays(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $account->forceFill(['status' => 'frozen'])->save();

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertUnprocessable();

        $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());
    }

    public function test_the_generic_hold_release_refuses_a_member_request_hold(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->freshRequest();
        $this->actingAs($this->admin)->postJson("/api/savings/holds/{$request->savings_hold_id}/release", ['reason' => 'x'])
            ->assertUnprocessable();
        $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    public function test_the_expiry_sweep_run_twice_expires_the_request_and_its_hold_once(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->on('2026-11-17', function (): void {
            Artisan::call('portal:requests-sweep');
        });
        $this->assertSame(PortalRequest::STATUS_SUBMITTED, $request->fresh()->status, 'Still open on its last day.');

        $this->on('2026-11-18', function (): void {
            Artisan::call('portal:requests-sweep');
            Artisan::call('portal:requests-sweep');
        });

        $request->refresh();
        $this->assertSame([PortalRequest::STATUS_EXPIRED, null], [$request->status, $request->live_key]);
        $this->assertSame('expired', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
        $this->assertSame(1, PortalRequestEvent::query()->where('request_id', $request->id)->where('to_status', 'expired')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'portal_request_expired')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'portal_request_expired')->whereNull('user_id')->whereNull('actor_type')->count());
    }

    public function test_the_sweep_rejects_an_approved_request_whose_savings_approval_lapsed(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);

        $this->on('2026-11-11', function (): void {
            Artisan::call('savings:expire');
            Artisan::call('portal:requests-sweep');
            Artisan::call('portal:requests-sweep');
        });

        $request->refresh();
        $this->assertSame('expired', SavingsApproval::query()->findOrFail($request->savings_approval_id)->status);
        $this->assertSame([PortalRequest::STATUS_REJECTED, null], [$request->status, $request->live_key]);
        $this->assertSame(1, PortalRequestEvent::query()->where('request_id', $request->id)->where('to_status', 'rejected')->count());
    }

    public function test_a_decision_needs_the_queue_and_the_type_permission_and_another_branch_is_404(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()], $this->userWith(['savings:transact']))->assertForbidden();
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()], $this->queueStaff())->assertForbidden();

        $far = $this->userWith(['member_portal:requests', 'savings:transact'], Branch::factory()->create());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()], $far)->assertNotFound();
        $this->freshRequest();
        $this->actingAs($far)->getJson("/api/portal-admin/requests/{$request->id}")->assertNotFound();

        $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    public function test_a_member_cannot_see_or_act_on_another_members_request(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $other = $this->portalMember(['branch_id' => $this->branch->id]);
        $theirs = $this->portalToken($other);

        $this->member('GET', "/api/portal/requests/{$request->id}", [], $theirs)->assertNotFound();
        $this->member('POST', "/api/portal/requests/{$request->id}/cancel", [], $theirs)->assertNotFound();
        $this->member('POST', "/api/portal/requests/{$request->id}/reply", ['message' => 'x'], $theirs)->assertNotFound();
        $this->member('GET', '/api/portal/requests', [], $theirs)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('active', SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    public function test_access_ending_with_the_membership_cancels_open_requests_and_releases_their_holds_as_the_system(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->freshRequest();
        $this->actingAs($this->admin);
        $this->member->refresh()->update(['status' => 'deceased']);

        $request->refresh();
        $this->assertSame([PortalRequest::STATUS_CANCELLED, null], [$request->status, $request->live_key]);
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['released', null], [$hold->status, $hold->released_by]);

        foreach (['portal_request_cancelled', 'portal_request_hold_released'] as $action) {
            $row = AuditLog::query()->where('action', $action)->sole();
            $this->assertSame([null, null, null], [$row->user_id, $row->actor_type, $row->portal_user_id], $action);
        }

        $this->assertSame(1, PortalRequestEvent::query()->where('request_id', $request->id)->where('actor_type', 'system')->count());
    }

    /**
     * No savings or audit write made during a portal request records the
     * portal user's id in a staff column: the member's hold names nobody on
     * staff, and the staff decision names the staff user passed explicitly.
     * The portal user's id is moved far from any staff id, so a leak can't
     * pass for a real staff user.
     */
    public function test_no_staff_column_ever_holds_a_portal_users_id(): void
    {
        $portalId = 900_001;
        DB::table('portal_users')->where('id', $this->portalUser->id)->update(['id' => $portalId]);
        DB::table('personal_access_tokens')->where('tokenable_type', $this->portalUser->getMorphClass())->delete();
        $this->portalUser = PortalUser::query()->findOrFail($portalId);
        $this->token = $this->portalToken($this->portalUser);
        $this->assertNull(User::query()->find($portalId));

        $account = $this->account();
        $this->fund($account, 3_000_000);
        $cancelled = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());
        $this->member('POST', "/api/portal/requests/{$cancelled->id}/cancel")->assertOk();
        $small = $this->submitted($this->submit($this->withdrawalBody($account, '200.00'))->assertCreated());
        $this->decide($small, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();
        $large = $this->submitted($this->submit($this->withdrawalBody($account, '15000.00'))->assertCreated());
        $this->decide($large, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);
        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$large->fresh()->savings_approval_id}/approve")->assertOk();
        $profile = $this->submitted($this->submit(['type' => 'profile_update', 'details' => ['city' => 'Pasig']])->assertCreated());
        $this->decide($profile, ['decision' => 'need_info', 'message' => 'Which barangay?'])->assertOk();
        $this->member('POST', "/api/portal/requests/{$profile->id}/reply", ['message' => 'Kapitolyo'])->assertOk();
        $this->decide($profile, ['decision' => 'approve'])->assertOk();

        $staffIds = User::query()->pluck('id')->all();
        $checked = 0;

        foreach (['savings_holds', 'savings_transactions', 'savings_approvals', 'accounting_journals', 'audit_logs', 'portal_requests', 'portal_request_events', 'borrowers'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if ($column !== 'user_id' && ! str_ends_with($column, '_by') && ! in_array($column, ['first_checker_id', 'received_by'], true)) {
                    continue;
                }

                foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $value) {
                    $this->assertContains((int) $value, $staffIds, "{$table}.{$column} holds {$value}, which is no staff user.");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(5, $checked);
        $this->assertSame([null], SavingsHold::query()->distinct()->pluck('created_by')->all(), 'The member\'s holds name nobody on staff.');
        $this->assertTrue(AuditLog::query()->where('portal_user_id', $portalId)->where('actor_type', 'member')->whereNull('user_id')->exists());
    }

    /**
     * SavingsLedger's `created_by` fallback reads the signed-in caller only
     * when it is staff: under the portal guard it records nobody.
     */
    public function test_the_ledger_fallback_never_records_a_signed_in_member(): void
    {
        $account = $this->account();
        $this->app['auth']->shouldUse('portal');
        $this->app['auth']->guard('portal')->setUser($this->portalUser);

        $row = DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 10_000, ['method' => 'cash']));

        $this->assertNull($row->created_by);

        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->admin);
        $staffRow = DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 10_000, ['method' => 'cash']));
        $this->assertSame($this->admin->id, $staffRow->created_by);
    }
}
