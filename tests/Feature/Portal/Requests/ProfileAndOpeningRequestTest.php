<?php

namespace Tests\Feature\Portal\Requests;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Profile change and new-account requests (portal design §6): nothing
 * changes until staff approve; approval writes through the member update
 * rules or the savings account opener, and needs the type's own permission.
 */
class ProfileAndOpeningRequestTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    private function profileBody(array $changes, ?string $remarks = null): array
    {
        return ['type' => PortalRequest::TYPE_PROFILE_UPDATE, 'details' => $changes, 'remarks' => $remarks];
    }

    public function test_a_profile_change_is_checked_by_the_member_rules_and_one_is_open_at_a_time(): void
    {
        $this->submit($this->profileBody(['city' => '']))->assertUnprocessable()->assertJsonValidationErrors('details.city');
        $this->submit($this->profileBody(['email' => 'not-an-email']))->assertUnprocessable()->assertJsonValidationErrors('details.email');
        $this->submit($this->profileBody(['monthly_income' => '99999']))->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->submit($this->profileBody([]))->assertUnprocessable()->assertJsonValidationErrors('details');

        $response = $this->submit($this->profileBody(['city' => 'Quezon City', 'contact_number' => '09189876543'], 'Moved last month'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.details.changes.0.field', 'city')
            ->assertJsonPath('data.details.changes.0.current', 'Manila')
            ->assertJsonPath('data.details.changes.0.requested', 'Quezon City')
            ->assertJsonPath('data.details.changes.1.current', '63917*****67')
            ->assertJsonPath('data.details.changes.1.requested', '63918*****43');

        $request = $this->submitted($response);
        $this->assertSame(['city' => 'Manila', 'contact_number' => '09171234567'], $request->current_values);
        $this->assertSame('Manila', $this->member->fresh()->city, 'Nothing changes before staff decide.');

        $this->submit($this->profileBody(['province' => 'Rizal']))->assertStatus(409);
    }

    public function test_staff_approval_writes_the_member_with_its_audit_row_and_needs_borrowers_update(): void
    {
        $request = $this->submitted($this->submit($this->profileBody(['city' => 'Quezon City', 'email' => 'maria.new@example.com']))->assertCreated());

        $this->decide($request, ['decision' => 'approve'], $this->queueStaff())->assertForbidden();

        $staff = $this->queueStaff(['borrowers:update', 'borrowers:view']);
        $this->decide($request, ['decision' => 'under_review'], $staff)->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->decide($request, ['decision' => 'approve'], $staff)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.details.changes.1.requested', 'maria.new@example.com');

        $member = $this->member->fresh();
        $this->assertSame(['Quezon City', 'maria.new@example.com'], [$member->city, $member->email]);
        $this->assertTrue(AuditLog::query()->where('auditable_type', Borrower::class)->where('auditable_id', $member->id)
            ->where('user_id', $staff->id)->where('action', 'updated')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'portal_request_decided')->where('user_id', $staff->id)->exists());

        $this->decide($request, ['decision' => 'approve'], $staff)->assertStatus(409);
    }

    public function test_need_info_takes_a_member_reply_back_to_review(): void
    {
        $request = $this->submitted($this->submit($this->profileBody(['barangay' => 'Poblacion']))->assertCreated());

        $this->member('POST', "/api/portal/requests/{$request->id}/reply", ['message' => 'Too early'])->assertStatus(409);
        $this->decide($request, ['decision' => 'need_info', 'message' => 'Send a barangay certificate at the branch.'])->assertOk()
            ->assertJsonPath('data.status', 'need_info');
        $this->member('GET', "/api/portal/requests/{$request->id}")->assertOk()->assertJsonPath('data.can_reply', true);
        $this->member('POST', "/api/portal/requests/{$request->id}/reply", ['message' => 'Dropped it off today.'])->assertOk()
            ->assertJsonPath('data.status', 'under_review');

        $timeline = $this->member('GET', "/api/portal/requests/{$request->id}")->json('data.timeline');
        $this->assertSame(['submitted', 'need_info', 'under_review'], array_column($timeline, 'to_status'));
        $this->assertSame('Dropped it off today.', $timeline[2]['message']);
        $this->assertArrayNotHasKey('user_id', $timeline[1]);
    }

    public function test_a_new_account_request_needs_a_product_open_for_requests_and_the_minimum_deposit(): void
    {
        $body = ['type' => PortalRequest::TYPE_SAVINGS_OPENING, 'details' => ['product_id' => $this->product->id, 'initial_deposit' => '500.00', 'terms_accepted' => true]];

        $this->submit($body)->assertUnprocessable()->assertJsonValidationErrors('details.product_id');

        $this->product->forceFill(['portal_account_requests' => true])->save();
        $this->submit([...$body, 'details' => [...$body['details'], 'initial_deposit' => '499.99']])->assertUnprocessable()->assertJsonValidationErrors('details.initial_deposit');
        $this->submit([...$body, 'details' => [...$body['details'], 'terms_accepted' => false]])->assertUnprocessable()->assertJsonValidationErrors('details.terms_accepted');

        $this->member('GET', '/api/portal/requests/options')->assertOk()->assertJsonPath('data.opening_products.0.id', $this->product->id);

        $request = $this->submitted($this->submit($body)->assertCreated());
        $this->assertSame('o:'.$this->member->id.':'.$this->product->id, $request->live_key);
        $this->submit($body)->assertStatus(409);

        $this->decide($request, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'cash']], $this->queueStaff(['savings:transact']))->assertForbidden();

        $this->decide($request, ['decision' => 'approve', 'deposit' => ['date' => '2026-11-10', 'method' => 'cash']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $request->refresh();
        $account = SavingsAccount::query()->findOrFail($request->opened_account_id);
        $this->assertSame([$this->member->id, $this->product->id, '500.00'], [$account->borrower_id, $account->product_id, (string) $account->ledger_balance]);
        $deposit = SavingsTransaction::query()->where('account_id', $account->id)->sole();
        $this->assertSame([['1010', 50_000, 0], ['2050', 0, 50_000]], $this->journalLines($this->journalFor($deposit)));
    }

    public function test_a_member_with_an_open_account_on_the_product_cannot_ask_for_another(): void
    {
        $this->product->forceFill(['portal_account_requests' => true])->save();
        $this->account();

        $this->submit(['type' => PortalRequest::TYPE_SAVINGS_OPENING, 'details' => ['product_id' => $this->product->id, 'initial_deposit' => '500.00', 'terms_accepted' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('details.product_id');

        $this->member('GET', '/api/portal/requests/options')->assertOk()->assertJsonCount(0, 'data.opening_products');
        $this->assertSame(0, SavingsProduct::query()->whereKey($this->product->id)->firstOrFail()->accounts()->where('status', 'closed')->count());
    }

    public function test_a_switched_off_request_type_is_403(): void
    {
        $this->enablePortal(['features' => ['profile_requests' => false]]);

        $this->submit($this->profileBody(['city' => 'Pasig']))->assertForbidden()->assertJsonPath('code', 'feature_off');
        $this->member('GET', '/api/portal/requests/options')->assertOk()->assertJsonPath('data.types.profile_update', false);
    }

    public function test_the_staff_queue_lists_the_callers_branches_with_counts(): void
    {
        $this->submit($this->profileBody(['city' => 'Pasig']))->assertCreated();

        $this->freshRequest();
        $this->actingAs($this->queueStaff())->getJson('/api/portal-admin/requests?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.name', 'Maria Santos')
            ->assertJsonPath('meta.counts.submitted', 1);

        $this->freshRequest();
        $this->actingAs($this->userWith(['borrowers:update']))->getJson('/api/portal-admin/requests')->assertForbidden();
    }
}
