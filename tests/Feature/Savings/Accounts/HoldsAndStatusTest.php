<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AuditLog;
use App\Models\SavingsAccount;
use App\Models\SavingsHold;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Holds (design §2): placed up to the balance less other holds, taken off
 * the available balance, released once (409 after); and the account status
 * changes (freeze, unfreeze, reactivate), each audited with its reason.
 */
class HoldsAndStatusTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account();
        $this->fund($this->savings, 100_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_hold_is_placed_shown_and_released_once(): void
    {
        $this->actingAs($teller = $this->teller());

        $hold = $this->money("/api/savings/accounts/{$this->savings->id}/holds", [
            'type' => 'legal', 'amount' => '600.00', 'reason' => 'Court order 12-345', 'expires_on' => '2026-12-31',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'legal')
            ->assertJsonPath('data.amount', '600.00')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.placed_on', '2026-11-10')
            ->assertJsonPath('data.expires_on', '2026-12-31')
            ->assertJsonPath('data.created_by', ['id' => $teller->id, 'name' => $teller->full_name])
            ->assertJsonPath('data.released_by', null)
            ->json('data');

        $this->getJson("/api/savings/accounts/{$this->savings->id}")
            ->assertJsonPath('data.hold_amount', '600.00')->assertJsonPath('data.available_balance', '400.00');
        $this->getJson("/api/savings/accounts/{$this->savings->id}/holds")->assertOk()->assertJsonCount(1, 'data');

        // Only ₱400.00 is not held yet.
        $this->money("/api/savings/accounts/{$this->savings->id}/holds", ['type' => 'manual', 'amount' => '400.01', 'reason' => 'Check'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson("/api/savings/holds/{$hold['id']}/release", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/savings/holds/{$hold['id']}/release", ['reason' => 'Order lifted'])->assertOk()
            ->assertJsonPath('data.status', 'released')
            ->assertJsonPath('data.released_on', '2026-11-10')
            ->assertJsonPath('data.release_reason', 'Order lifted')
            ->assertJsonPath('data.released_by', ['id' => $teller->id, 'name' => $teller->full_name]);
        $this->postJson("/api/savings/holds/{$hold['id']}/release", ['reason' => 'Again'])->assertStatus(409);

        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertJsonPath('data.available_balance', '1000.00');
    }

    public function test_hold_refusals(): void
    {
        $this->money("/api/savings/accounts/{$this->savings->id}/holds", ['type' => 'loan_retention', 'amount' => '1', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->money("/api/savings/accounts/{$this->savings->id}/holds", ['type' => 'manual', 'amount' => '1', 'reason' => 'x', 'expires_on' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        $this->money("/api/savings/accounts/{$this->savings->id}/holds", ['type' => 'manual', 'amount' => '0', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->savings->forceFill(['status' => 'closed', 'closed_on' => '2026-11-10', 'open_key' => null])->save();
        $this->money("/api/savings/accounts/{$this->savings->id}/holds", ['type' => 'manual', 'amount' => '1', 'reason' => 'x'])
            ->assertUnprocessable();

        $this->assertSame(0, SavingsHold::query()->count());
    }

    public function test_freeze_unfreeze_and_reactivate_are_audited_with_their_reason(): void
    {
        $this->actingAs($this->userWith(['savings:view', 'savings:close']));

        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'frozen', 'reason' => 'Disputed signature'])
            ->assertOk()->assertJsonPath('data.status', 'frozen');
        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'frozen', 'reason' => 'Again'])->assertUnprocessable();
        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'active', 'reason' => 'Resolved'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->savings->forceFill(['status' => 'dormant', 'dormant_since' => '2026-11-01', 'last_member_activity_on' => '2024-01-01'])->save();
        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'active', 'reason' => 'Member came in with ID'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.dormant_since', null)
            ->assertJsonPath('data.last_member_activity_on', '2026-11-10');

        $this->assertSame(
            ['savings_account_frozen', 'savings_account_unfrozen', 'savings_account_reactivated'],
            AuditLog::query()->where('auditable_type', SavingsAccount::class)->where('auditable_id', $this->savings->id)
                ->where('action', 'like', 'savings_account_%')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertStringContainsString('Disputed signature', (string) AuditLog::query()->where('action', 'savings_account_frozen')->value('description'));

        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'dormant', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'frozen'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_closed_account_keeps_its_status(): void
    {
        $this->savings->forceFill(['status' => 'closed', 'closed_on' => '2026-11-10', 'open_key' => null])->save();

        $this->postJson("/api/savings/accounts/{$this->savings->id}/status", ['status' => 'active', 'reason' => 'x'])->assertUnprocessable();
        $this->assertSame('closed', $this->savings->refresh()->status);
    }
}
