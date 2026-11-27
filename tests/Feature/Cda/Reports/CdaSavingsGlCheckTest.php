<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Cda\Reports\NamedChecks;
use App\Services\Cda\Reports\ReportDefinition;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\TemplateDefinitionValidator;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `savings.gl_matches` (gap round B4): a warning when the savings subledger
 * differs from the general ledger at the period's end (GlControl, every
 * branch), skipped when the cooperative keeps no savings.
 */
class CdaSavingsGlCheckTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function check(): ?string
    {
        $period = $this->openYear();

        return app(NamedChecks::class)->run('savings.gl_matches', [], new SourceContext($period), new ReportDefinition(['sections' => []]));
    }

    public function test_it_is_a_named_check_the_validator_accepts(): void
    {
        $this->assertContains('savings.gl_matches', TemplateDefinitionValidator::CHECKS);
    }

    public function test_it_passes_when_no_savings_are_kept(): void
    {
        $this->assertNull($this->check());
    }

    public function test_it_passes_when_the_subledger_equals_the_books(): void
    {
        SavingsAccount::factory()->create(['branch_id' => $this->branch->id]);

        $this->assertNull($this->check());
    }

    public function test_it_warns_with_the_difference_when_the_subledger_differs(): void
    {
        $account = SavingsAccount::factory()->create(['branch_id' => $this->branch->id]);
        SavingsTransaction::factory()->create(['account_id' => $account->id, 'date' => '2026-06-30', 'value_date' => '2026-06-30', 'credit' => '1500.50', 'balance_after' => '1500.50']);
        SavingsTransaction::factory()->create(['account_id' => $account->id, 'date' => '2027-01-05', 'value_date' => '2027-01-05', 'credit' => '99.00', 'balance_after' => '1599.50']);

        $this->assertSame(
            'The savings subledger differs from the general ledger as of Dec 31, 2026 by ₱1,500.50 (1 line differs). Check the Savings GL Control report before filing.',
            $this->check(),
        );
    }
}
