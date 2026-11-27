<?php

namespace Tests\Feature\MenuVisibility;

use App\Models\PortalRequest;
use App\Models\PortalSetting;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\MenuVisibility\MenuVisibility;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * While Savings is hidden in Menu Visibility (finish run round 6), the member
 * portal neither offers nor accepts savings: its config reads the savings parts
 * as off, a new savings request answers 404 like the rest of the Savings API,
 * and a savings request already open waits as it is, for staff to decide once
 * Savings is shown again. The stored portal switches never change, and the
 * background sweep runs as before.
 */
class PortalSavingsWhileHiddenTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    private const SAVINGS_PARTS = ['savings', 'time_deposits', 'account_requests', 'withdrawal_requests'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    public function test_the_portal_reads_its_savings_parts_as_off_and_keeps_its_stored_switches(): void
    {
        $this->hideSavings();

        $config = $this->member('GET', '/api/portal/config')->assertOk()->json('data.features');
        $me = $this->member('GET', '/api/portal/auth/me')->assertOk()->json('data.features');

        foreach ([$config, $me] as $features) {
            foreach (self::SAVINGS_PARTS as $part) {
                $this->assertFalse($features[$part], "{$part} still reads on.");
            }

            $this->assertTrue($features['loans']);
            $this->assertTrue($features['share_capital']);
            $this->assertTrue($features['profile_requests']);
        }

        // Settings → Member Portal shows, and saves back, the stored switches.
        $this->freshRequest();
        $this->actingAs($this->admin)->getJson('/api/portal-admin/settings')->assertOk()
            ->assertJsonPath('data.features.savings', true)
            ->assertJsonPath('data.features.withdrawal_requests', true);
        $this->assertTrue(PortalSetting::query()->firstOrFail()->features['savings'] ?? true);

        $this->showSavings();
        $this->member('GET', '/api/portal/config')->assertOk()
            ->assertJsonPath('data.features.savings', true)
            ->assertJsonPath('data.features.withdrawal_requests', true);
    }

    public function test_the_dashboard_holds_no_savings_while_savings_is_hidden(): void
    {
        $this->fund($this->account(), 500_000);

        $shown = $this->member('GET', '/api/portal/dashboard')->assertOk()->json('data');
        $this->assertNotNull($shown['savings']);
        $this->assertContains('savings', array_column($shown['recent_activity'] ?? [], 'kind'));

        $this->hideSavings();

        $hidden = $this->member('GET', '/api/portal/dashboard')->assertOk()->json('data');
        $this->assertNull($hidden['savings']);
        $this->assertNull($hidden['time_deposits']);
        $this->assertNotContains('savings', array_column($hidden['recent_activity'] ?? [], 'kind'));
    }

    public function test_a_new_savings_request_answers_404_while_savings_is_hidden(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->hideSavings();

        $this->member('GET', '/api/portal/requests/options')->assertOk()
            ->assertJsonPath('data.types.savings_withdrawal', false)
            ->assertJsonPath('data.types.savings_opening', false)
            ->assertJsonPath('data.types.profile_update', true)
            ->assertJsonPath('data.withdrawal_accounts', [])
            ->assertJsonPath('data.opening_products', []);

        $this->submit($this->withdrawalBody($account, '1200.00'))->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->submit(['type' => PortalRequest::TYPE_SAVINGS_OPENING, 'details' => ['product_id' => $this->product->id, 'amount' => '500.00']])
            ->assertNotFound()->assertExactJson(['message' => 'Not found.']);

        $this->assertSame(0, PortalRequest::query()->count());
        $this->assertSame(0, SavingsHold::query()->count());

        $this->showSavings();
        $this->submit($this->withdrawalBody($account, '1200.00'))->assertCreated();
    }

    public function test_an_open_savings_request_waits_as_it_is_until_savings_is_shown_again(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1200.00'))->assertCreated());
        $this->decide($request, ['decision' => 'need_info', 'message' => 'Which branch will you pick it up from?'])->assertOk();
        $this->hideSavings();

        $this->member('POST', "/api/portal/requests/{$request->id}/reply", ['message' => 'Main branch'])
            ->assertStatus(422)
            ->assertJsonPath('errors.request.0', "Savings requests can't be changed right now. Your branch will decide this one once savings is available again.");
        $this->member('POST', "/api/portal/requests/{$request->id}/cancel")->assertStatus(422);

        foreach ([['decision' => 'approve', 'payout' => $this->payout()], ['decision' => 'reject', 'message' => 'No.'], ['decision' => 'under_review']] as $decision) {
            $this->decide($request, $decision)
                ->assertStatus(422)
                ->assertJsonPath('errors.decision.0', 'Savings is turned off in Menu Visibility. Turn it on to decide this request.');
        }

        // Still visible to the member, and untouched.
        $this->member('GET', "/api/portal/requests/{$request->id}")->assertOk()->assertJsonPath('data.status', 'need_info');
        $hold = SavingsHold::query()->findOrFail($request->savings_hold_id);
        $this->assertSame(['need_info', 'active'], [$request->fresh()->status, $hold->status]);
        $this->assertSame(0, SavingsTransaction::query()->where('type', 'withdrawal')->count());

        $this->showSavings();
        $this->decide($request, ['decision' => 'reject', 'message' => 'Please visit the branch.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('released', $hold->fresh()->status);
    }

    public function test_the_background_sweep_still_expires_an_open_savings_request_while_savings_is_hidden(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $request = $this->submitted($this->submit($this->withdrawalBody($account, '1200.00'))->assertCreated());
        $this->hideSavings();

        $this->travelTo($request->expires_on->copy()->addDay()->setTime(10, 0));
        Artisan::call('portal:requests-sweep');

        $this->assertSame(PortalRequest::STATUS_EXPIRED, $request->fresh()->status);
        $this->assertSame(SavingsHold::STATUS_EXPIRED, SavingsHold::query()->findOrFail($request->savings_hold_id)->status);
    }

    private function hideSavings(): void
    {
        app(MenuVisibility::class)->save(['savings'], $this->admin);
    }

    private function showSavings(): void
    {
        app(MenuVisibility::class)->save([], $this->admin);
    }
}
