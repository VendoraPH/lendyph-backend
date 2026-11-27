<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\BrandingSetting;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use App\Services\Savings\Api\HoldDesk;
use App\Services\Savings\AvailableBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Backend items from the portal browser check: the staff's message on an
 * approval reaches the member, the hold's release reason says what happened
 * when a withdrawal only moves to its second check, and staff see how much
 * of an account's holds is for member requests.
 */
class BrowserCheckFollowUpsTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    private const MESSAGE = 'Please bring your passbook when you collect it.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    // The staff's "Message to the member" on Approve.

    public function test_the_message_on_a_withdrawal_approval_reaches_the_member(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $paid = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->decide($paid, ['decision' => 'approve', 'payout' => $this->payout(), 'message' => self::MESSAGE])->assertCreated();
        $this->assertMemberSees($paid, PortalRequest::STATUS_COMPLETED);

        $waiting = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($waiting, ['decision' => 'approve', 'payout' => $this->payout(), 'message' => self::MESSAGE])->assertStatus(202);
        $this->assertMemberSees($waiting, PortalRequest::STATUS_APPROVED);
    }

    public function test_the_message_on_a_profile_approval_reaches_the_member(): void
    {
        $request = $this->submitted($this->submit(['type' => PortalRequest::TYPE_PROFILE_UPDATE, 'details' => ['city' => 'Quezon City']])->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'message' => self::MESSAGE])->assertOk();

        $this->assertMemberSees($request, PortalRequest::STATUS_COMPLETED);
    }

    public function test_the_message_on_a_new_account_approval_reaches_the_member(): void
    {
        $this->product->forceFill(['portal_account_requests' => true])->save();
        $request = $this->submitted($this->submit([
            'type' => PortalRequest::TYPE_SAVINGS_OPENING,
            'details' => ['product_id' => $this->product->id, 'initial_deposit' => '500.00', 'terms_accepted' => true],
        ])->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'cash'], 'message' => self::MESSAGE])->assertCreated();

        $this->assertMemberSees($request, PortalRequest::STATUS_COMPLETED);
    }

    // The hold when a withdrawal moves to its second check.

    /**
     * Above tier 1 the staff approval opens a savings approval, which
     * reserves the money as a pending outflow under the same account lock,
     * and the request's hold hands over to it: the money is reserved exactly
     * once, the whole time, and the release reason says so.
     */
    public function test_a_withdrawal_moved_to_its_second_check_hands_its_hold_over_and_posts_once_approved(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->assertSame(1_000_000, $this->availableOf($account));

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);

        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame('released', $hold->status);
        $this->assertSame("Moved to its second approval on request {$request->reference}; the savings approval now reserves the money", $hold->release_reason);
        $this->assertSame(1_000_000, $this->availableOf($account), 'Reserved once, by the savings approval.');

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$request->fresh()->savings_approval_id}/approve")->assertOk();

        $this->assertSame(PortalRequest::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertSame('10000.00', $this->balanceOf($account));
        $this->assertSame(1_000_000, $this->availableOf($account));
    }

    public function test_a_withdrawal_rejected_at_its_second_check_frees_the_money(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '20000.00'))->assertCreated());
        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertStatus(202);

        $this->freshRequest();
        $this->actingAs($this->checker())->postJson("/api/savings/approvals/{$request->fresh()->savings_approval_id}/reject", ['reason' => 'Signature mismatch'])->assertOk();

        $this->assertSame(PortalRequest::STATUS_REJECTED, $request->fresh()->status);
        $this->assertSame('30000.00', $this->balanceOf($account));
        $this->assertSame(3_000_000, $this->availableOf($account), 'Nothing is reserved any more.');
        $this->assertSame(0, SavingsHold::query()->where('account_id', $account->id)->where('status', 'active')->count());
    }

    public function test_a_withdrawal_paid_at_once_keeps_the_paid_out_reason(): void
    {
        $account = $this->account();
        $this->fund($account, 100_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '100.00'))->assertCreated());

        $this->decide($request, ['decision' => 'approve', 'payout' => $this->payout()])->assertCreated();

        $this->assertSame("Paid out on request {$request->reference}", SavingsHold::query()->findOrFail($request->savings_hold_id)->release_reason);
    }

    // Staff see the same on-hold split as the member.

    public function test_the_staff_account_payload_says_how_much_of_the_holds_is_for_member_requests(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        DB::transaction(fn () => app(HoldDesk::class)->place($account->id, ['type' => 'manual', 'amount' => '250.00', 'reason' => 'Court order'], $this->admin));
        $this->submit($this->withdrawalBody($account, '10000.00'))->assertCreated();

        $this->freshRequest();
        $data = $this->actingAs($this->admin)->getJson("/api/savings/accounts/{$account->id}")->assertOk()->json('data');

        $this->assertSame('10250.00', $data['hold_amount'], 'Unchanged: every active hold.');
        $this->assertSame('10000.00', $data['held_for_requests']);
        $this->assertSame('0.00', $data['pending_amount']);
        $this->assertSame('19750.00', $data['available_balance']);

        $row = collect($this->actingAs($this->admin)->getJson('/api/savings/accounts')->assertOk()->json('data'))->firstWhere('id', $account->id);
        $this->assertSame('10000.00', $row['held_for_requests']);
    }

    // The portal's logo only when there is one.

    public function test_the_portal_config_sends_the_logo_path_only_when_a_logo_is_stored(): void
    {
        Storage::fake('public');
        $config = fn (): mixed => $this->member('GET', '/api/portal/config')->assertOk()->json('data.logo_url');

        $this->assertNull($config(), 'No logo set.');

        BrandingSetting::current()->forceFill(['logo_path' => 'branding/logo.png'])->save();
        $this->assertNull($config(), 'A path with no file behind it answers 404 at /branding/logo, so none is sent.');

        Storage::disk('public')->put('branding/logo.png', 'png-bytes');
        $this->assertSame('/branding/logo', $config());
        $this->freshRequest();
        $this->get('/api/branding/logo')->assertOk();
    }

    private function assertMemberSees(PortalRequest $request, string $status): void
    {
        $shown = $this->member('GET', "/api/portal/requests/{$request->id}")->assertOk()->json('data');
        $this->assertSame($status, $shown['status']);
        $this->assertSame(self::MESSAGE, collect($shown['timeline'])->last()['message'], 'On the timeline.');

        $notice = $this->portalUser->notifications()->latest('created_at')->get()
            ->first(fn ($n): bool => str_contains((string) $n->data['body'], $request->reference) && $n->data['title'] !== 'Request received');
        $this->assertNotNull($notice);
        $this->assertStringContainsString(self::MESSAGE, (string) $notice->data['body'], 'In the notice.');
    }

    private function availableOf(SavingsAccount $account): int
    {
        return app(AvailableBalance::class)->for($account->refresh());
    }
}
