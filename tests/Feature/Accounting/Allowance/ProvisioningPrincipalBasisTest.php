<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\Branch;
use App\Services\Accounting\AllowanceCalculator;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /reports/provisioning adds `principal_basis`: the allowance
 * calculator's figures, in pesos, with 91–120 and over 120 days folded into
 * over 90. Every existing key and figure stays as it was.
 */
class ProvisioningPrincipalBasisTest extends TestCase
{
    use AllowanceTestHelpers, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'is_active' => true]);

        $this->period($this->agedLoan(), 10, principal: 10000.10);
        $this->period($this->agedLoan(), 45, principal: 333.33);
        $this->period($this->agedLoan(['branch_id' => $this->far->id]), 75, principal: 3.30);
        $this->period($this->agedLoan(['branch_id' => $this->far->id]), 100, principal: 2000);
        $this->period($this->agedLoan(), 200, principal: 1000);
        $this->period($this->agedLoan(), -10, principal: 5000);
    }

    /** @return array<string, mixed> */
    private function report(string $query = ''): array
    {
        return $this->getJson('/api/reports/provisioning?'.$query)->assertOk()->json('data');
    }

    public function test_principal_basis_is_the_calculators_figures_in_pesos(): void
    {
        $report = $this->report();
        $basis = $report['principal_basis'];

        $this->assertSame(['buckets', 'totals', 'basis_note'], array_keys($basis));
        $this->assertSame('Outstanding principal of each loan, in the bucket of its oldest unpaid instalment', $basis['basis_note']);
        $this->assertSame(['1_30', '31_60', '61_90', 'over_90'], array_keys($basis['buckets']));
        $this->assertSame(['principal', 'count', 'rate_percent', 'required_allowance'], array_keys($basis['buckets']['1_30']));

        // ₱10,000.10 × 5% = ₱500.005, half up ₱500.01.
        $this->assertEquals(['principal' => 10000.10, 'count' => 1, 'rate_percent' => '5', 'required_allowance' => 500.01], $basis['buckets']['1_30']);
        $this->assertEquals(['principal' => 333.33, 'count' => 1, 'rate_percent' => '15', 'required_allowance' => 50.00], $basis['buckets']['31_60']);
        $this->assertEquals(['principal' => 3.30, 'count' => 1, 'rate_percent' => '25', 'required_allowance' => 0.83], $basis['buckets']['61_90']);
        // 91–120 (₱2,000.00, Far) and over 120 (₱1,000.00, Main), both at 50%.
        $this->assertEquals(['principal' => 3000.00, 'count' => 2, 'rate_percent' => '50', 'required_allowance' => 1500.00], $basis['buckets']['over_90']);

        $base = app(AllowanceCalculator::class)->forDate($this->today());
        $this->assertEquals([
            'principal' => $base['totals']['principal'] / 100,
            'count' => $base['totals']['loan_count'],
            'required_allowance' => $base['totals']['required'] / 100,
        ], $basis['totals']);
        $this->assertEquals(['principal' => 13336.73, 'count' => 5, 'required_allowance' => 2050.84], $basis['totals']);
    }

    public function test_the_branch_filter_applies_to_the_principal_basis(): void
    {
        $basis = $this->report('branch_id='.$this->far->id)['principal_basis'];

        $this->assertEquals(['principal' => 2003.30, 'count' => 2, 'required_allowance' => 1000.83], $basis['totals']);
        $this->assertSame(0, $basis['buckets']['1_30']['count']);
    }

    public function test_the_existing_keys_and_figures_are_unchanged(): void
    {
        $report = $this->report();

        $this->assertSame(
            ['as_of_date', 'buckets', 'totals', 'rates', 'policy_note', 'generated_at', 'principal_basis'],
            array_keys($report),
        );
        $this->assertSame(['amount', 'count', 'rate', 'rate_percent', 'required_allowance'], array_keys($report['buckets']['1_30']));
        $this->assertSame(['amount', 'count', 'required_allowance', 'effective_rate'], array_keys($report['totals']));
        $this->assertStringContainsString('principal_basis', $report['policy_note']);
        $this->assertStringContainsString('POLICY', $report['policy_note']);
    }

    public function test_the_report_date_is_the_basis_date(): void
    {
        // As of 50 days ago, the 75-, 100- and 200-day-old periods were 25, 50
        // and 150 days late; the others weren't due yet.
        $asOf = now()->subDays(50)->toDateString();
        $basis = $this->report('date_to='.$asOf)['principal_basis'];

        $this->assertSame(1, $basis['buckets']['1_30']['count']);
        $this->assertSame(1, $basis['buckets']['31_60']['count']);
        $this->assertSame(0, $basis['buckets']['61_90']['count']);
        $this->assertSame(1, $basis['buckets']['over_90']['count']);
        $this->assertSame(3, $basis['totals']['count']);
    }
}
