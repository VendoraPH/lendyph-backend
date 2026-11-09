<?php

namespace Tests\Feature;

use App\Models\Loan;
use Illuminate\Support\Collection;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GET /dashboard/recent-transactions reads in words.
 *
 * A restructure's release pays nothing out: it collects its deductions from
 * the member (Phase 2, Accounting). So its row reads "Loan restructured" with
 * the amount collected, never "Loan released" with a payout. A payment's
 * method reads as the statement prints it ("GCash"), never title-cased from
 * its stored key ("Gcash").
 */
class DashboardRecentTransactionsTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function recent(): Collection
    {
        return collect($this->getJson('/api/dashboard/recent-transactions?limit=100')->assertOk()->json('data'));
    }

    public function test_a_restructures_release_reads_loan_restructured_with_what_was_collected(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();
        $new->refresh();
        $collected = (float) $new->total_deductions;
        $this->assertGreaterThan(0, $collected, 'the product charges processing and service fees');

        $rows = $this->recent();
        $restructured = $rows->where('description', 'Loan restructured')->values();
        $released = $rows->where('description', 'Loan released')->values();

        $this->assertCount(1, $restructured);
        $this->assertSame('restructure', $restructured[0]['type']);
        $this->assertEquals($collected, $restructured[0]['amount']);
        $this->assertSame($new->borrower->full_name, $restructured[0]['borrower_name']);

        // Only the source's own release reads as a payout, its net proceeds.
        $this->assertCount(1, $released);
        $this->assertSame('release', $released[0]['type']);
        $this->assertEquals((float) Loan::findOrFail($source->id)->net_proceeds, $released[0]['amount']);
    }

    public function test_payment_methods_read_in_words(): void
    {
        $gcash = $this->createReleasedLoan();
        $bank = $this->createReleasedLoan();
        $cash = $this->createReleasedLoan();

        foreach ([[$gcash, 'gcash', 'GC-REF-1'], [$bank, 'bank_transfer', 'BT-REF-1'], [$cash, 'cash', null]] as [$loan, $method, $reference]) {
            $this->postJson("/api/loans/{$loan->id}/repayments", array_filter([
                'payment_date' => now()->toDateString(),
                'amount_paid' => 11800,
                'method' => $method,
                'reference_number' => $reference,
            ]))->assertCreated();
        }

        $payments = $this->recent()->where('type', 'payment')->pluck('description')->sort()->values()->all();

        $this->assertSame(['Payment via Bank Transfer', 'Payment via Cash', 'Payment via GCash'], $payments);
    }
}
