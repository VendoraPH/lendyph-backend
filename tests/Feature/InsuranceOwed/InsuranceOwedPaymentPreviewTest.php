<?php

namespace Tests\Feature\InsuranceOwed;

use Illuminate\Testing\TestResponse;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/{id}/repayments/preview shows the premium part of a payment
 * (`allocated_to_insurance`, alias `total_insurance`), and its badge counts
 * the premium owed as part of what is owed. The preview writes nothing.
 */
class InsuranceOwedPaymentPreviewTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function preview(int $loanId, float $amount): TestResponse
    {
        return $this->postJson("/api/loans/{$loanId}/repayments/preview", [
            'amount_paid' => $amount,
            'payment_date' => now()->toDateString(),
        ])->assertOk();
    }

    public function test_the_preview_shows_the_premium_part_and_leaves_it_owed(): void
    {
        $loan = $this->loanOwingPremium();

        $data = $this->preview($loan->id, 1000)->json('data');

        $this->assertEqualsWithDelta(self::OWED, (float) ($data['allocated_to_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) ($data['total_insurance'] ?? 0), 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $data['total_interest'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $data['total_principal'], 0.001);

        // The per-period rows carry only what went on periods.
        $this->assertEqualsWithDelta(600.00, (float) collect($data['allocations'])->sum('amount_applied'), 0.001);

        // Rolled back: still owed.
        $this->assertEqualsWithDelta(self::OWED, $this->owed($loan), 0.001);
    }

    public function test_the_badge_counts_the_premium_as_owed(): void
    {
        $loan = $this->loanOwingPremium();

        // One instalment exactly: the premium takes ₱400 first, so period 1
        // is ₱400 short.
        $this->preview($loan->id, self::PERIOD_DUE)
            ->assertJsonPath('data.payment_badge.type', 'partial');

        // Premium plus the instalment: exactly what is owed.
        $this->preview($loan->id, self::PERIOD_DUE + self::OWED)
            ->assertJsonPath('data.payment_badge.type', 'exact');
    }
}
