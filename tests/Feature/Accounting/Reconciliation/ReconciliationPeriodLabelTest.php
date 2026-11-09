<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingReconciliation;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A reconciliation period that isn't a whole calendar month is labelled with
 * its dates in words ("Sep 3 – Sep 20, 2026"), never raw dates. Stored labels
 * keep their text, and a range already reconciled under its old label is still
 * refused as a duplicate.
 */
class ReconciliationPeriodLabelTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function create(string $start, string $end): array
    {
        return $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => $start,
            'end_date' => $end,
            'statement_balance' => 0,
        ])->assertCreated()->json('data');
    }

    public function test_a_part_month_reads_in_words(): void
    {
        $this->assertSame('Sep 3 – Sep 20, 2026', $this->create('2026-09-03', '2026-09-20')['period']);
        $this->assertSame('Sep 21 – Oct 5, 2026', $this->create('2026-09-21', '2026-10-05')['period']);
        $this->assertSame('Dec 15, 2026 – Jan 10, 2027', $this->create('2026-12-15', '2027-01-10')['period']);
        $this->assertSame('November 2026', $this->create('2026-11-01', '2026-11-30')['period']);
    }

    public function test_the_same_period_twice_is_refused_in_words(): void
    {
        $this->create('2026-09-03', '2026-09-20');

        $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-09-03',
            'end_date' => '2026-09-20',
            'statement_balance' => 0,
        ])->assertStatus(422)->assertJsonPath(
            'errors.period.0',
            fn (string $message): bool => str_contains($message, 'already has a reconciliation for Sep 3 – Sep 20, 2026.'),
        );
    }

    public function test_a_range_stored_under_its_old_label_is_still_a_duplicate(): void
    {
        $stored = AccountingReconciliation::create([
            'accounting_account_id' => $this->account('1040'),
            'period' => '2026-09-03 – 2026-09-20',
            'start_date' => '2026-09-03',
            'end_date' => '2026-09-20',
            'statement_balance' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1040'),
            'start_date' => '2026-09-03',
            'end_date' => '2026-09-20',
            'statement_balance' => 0,
        ])->assertStatus(422)->assertJsonPath(
            'errors.period.0',
            fn (string $message): bool => str_contains($message, 'already has a reconciliation for 2026-09-03 – 2026-09-20.'),
        );

        $this->assertSame(1, AccountingReconciliation::query()->count());
        $this->assertSame('2026-09-03 – 2026-09-20', $stored->fresh()->period);
    }
}
