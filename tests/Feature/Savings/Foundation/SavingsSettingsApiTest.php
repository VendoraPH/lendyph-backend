<?php

namespace Tests\Feature\Savings\Foundation;

use App\Exceptions\SavingsRecordIsAppendOnlyException;
use App\Models\AuditLog;
use App\Models\SavingsSetting;
use App\Models\SavingsTaxRule;
use App\Services\Savings\TaxBook;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET/PUT /savings/settings and POST /savings/tax-rules (design §1, §2):
 * the seeded defaults, the tier and dormancy settings audited on save, and
 * an append-only tax history whose new rules start today or later.
 */
class SavingsSettingsApiTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_seeded_defaults_and_the_tax_rules_in_force(): void
    {
        $this->getJson('/api/savings/settings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tier1_limit', '10000.00')
            ->assertJsonPath('data.tier2_limit', '50000.00')
            ->assertJsonPath('data.tier3_checkers', 2)
            ->assertJsonPath('data.dormancy_months', 24)
            ->assertJsonPath('data.large_transaction_alert', '500000.00')
            ->assertJsonPath('data.current_tax_rates.member', '0.0000')
            ->assertJsonPath('data.current_tax_rates.non_member', '20.0000')
            ->assertJsonCount(2, 'data.tax_rules');

        $this->assertSame(1, SavingsSetting::query()->count());
    }

    public function test_a_save_updates_the_settings_and_writes_an_audit_row(): void
    {
        $before = AuditLog::query()->max('id') ?? 0;

        $this->putJson('/api/savings/settings', [
            'tier1_limit' => '15000.50',
            'tier2_limit' => '60000',
            'tier3_checkers' => 3,
            'dormancy_months' => 36,
            'large_transaction_alert' => '750000.00',
        ])->assertOk()
            ->assertJsonPath('data.tier1_limit', '15000.50')
            ->assertJsonPath('data.tier2_limit', '60000.00')
            ->assertJsonPath('data.tier3_checkers', 3)
            ->assertJsonPath('data.dormancy_months', 36);

        $setting = SavingsSetting::current();
        $this->assertSame($this->admin->id, $setting->updated_by);

        $row = AuditLog::query()->where('id', '>', $before)->where('auditable_type', SavingsSetting::class)->sole();
        $this->assertSame('updated', $row->action);
        $this->assertSame('15000.50', $row->new_values['tier1_limit']);
    }

    public function test_settings_outside_their_limits_are_refused_in_words(): void
    {
        $this->putJson('/api/savings/settings', ['tier1_limit' => '50000.00', 'tier2_limit' => '50000.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('tier2_limit');
        $this->putJson('/api/savings/settings', ['tier2_limit' => '5000.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('tier2_limit');
        $this->putJson('/api/savings/settings', ['tier1_limit' => '100.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('tier1_limit');
        $response = $this->putJson('/api/savings/settings', ['tier3_checkers' => 1, 'dormancy_months' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['tier3_checkers', 'dormancy_months']);

        $this->assertStringNotContainsString('_', $response->json('errors.tier3_checkers.0'));
        $this->assertSame('10000.00', SavingsSetting::current()->tier1_limit);
    }

    public function test_a_tax_rule_is_appended_from_today_and_takes_effect_on_its_date(): void
    {
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '5.1250', 'effective_from' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.holder_type', 'member')
            ->assertJsonPath('data.rate', '5.1250')
            ->assertJsonPath('data.effective_from', '2026-12-01');

        $this->assertSame('0.0000', app(TaxBook::class)->rateOn('member', '2026-11-30'));
        $this->assertSame('5.1250', app(TaxBook::class)->rateOn('member', '2026-12-01'));
        $this->assertSame('20.0000', app(TaxBook::class)->rateOn('non_member', '2026-12-01'));
    }

    public function test_a_tax_rule_dated_before_today_or_out_of_range_is_refused(): void
    {
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '1', 'effective_from' => '2026-11-09'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'company', 'rate' => '1', 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('holder_type');
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '100.0001', 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('rate');
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '1.12345', 'effective_from' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('rate');
    }

    public function test_a_same_day_rule_wins_by_its_higher_id(): void
    {
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '1', 'effective_from' => '2026-11-10'])->assertCreated();
        $this->postJson('/api/savings/tax-rules', ['holder_type' => 'member', 'rate' => '2', 'effective_from' => '2026-11-10'])->assertCreated();

        $this->assertSame('2.0000', app(TaxBook::class)->rateOn('member', '2026-11-10'));
    }

    public function test_a_tax_rule_can_never_be_changed_or_removed(): void
    {
        $rule = SavingsTaxRule::query()->firstOrFail();

        $this->expectException(SavingsRecordIsAppendOnlyException::class);
        $rule->update(['rate' => '9.0000']);
    }
}
