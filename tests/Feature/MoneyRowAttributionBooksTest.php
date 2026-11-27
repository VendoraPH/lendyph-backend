<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ShareCapitalLedger;
use App\Services\BranchAttribution;
use App\Services\LoanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE3: every kind of share capital row (a member's own entry, a
 * release credit, a payment's build-up and its void, a transfer from
 * savings) counts in the branch its journal posted in, so the by-branch
 * figures equal the books' 3060 by branch, also after the member moves.
 */
class MoneyRowAttributionBooksTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private Branch $north;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->north = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NTH']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_row_kind_matches_the_books_by_branch_after_the_member_moves(): void
    {
        // In the main branch: an own entry, a release credit, a build-up, its
        // void, and a transfer from savings.
        $this->payIn(1000);
        $loan = $this->releasedLoanWithShareCapital(500);
        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $loan->amortizationSchedules()->orderBy('period_number')->first()->total_due + 300,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/repayments/{$repaymentId}/void", ['void_reason' => 'Wrong amount'])->assertOk();

        $account = $this->account();
        $this->fund($account, 2_000_000);
        $this->actingAs($this->userWith(['savings:view', 'savings:post', 'share_capital:create', 'share_capital:view']));
        $this->money("/api/savings/accounts/{$account->id}/share-capital-transfers", ['amount' => '200.00', 'remarks' => 'Build up'])->assertCreated();
        $this->actingAs($this->admin);

        // The member moves; a later entry is the new branch's.
        DB::table('borrowers')->where('id', $this->member->id)->update(['branch_id' => $this->north->id]);
        $this->payIn(400);

        $void = ShareCapitalLedger::query()->where('repayment_id', $repaymentId)->where('debit', '>', 0)->sole();
        $this->assertSame($loan->branch_id, $void->branch_id, 'A build-up void is stamped with the loan\'s branch.');
        $this->assertSame(
            [$this->branch->id => 1000 + 500 + 300 - 300 + 200, $this->north->id => 400],
            $this->ledgerByBranch(),
        );

        $books = $this->booksByBranch();
        $this->assertSame($books, $this->ledgerByBranch());

        foreach ($books as $branchId => $pesos) {
            $report = $this->getJson("/api/reports/share-capital?date_to=2026-11-10&branch_id={$branchId}")->assertOk()->json('data');
            $this->assertEqualsWithDelta($pesos, $report['closing_balance'], 0.001);
        }
    }

    private function payIn(float $amount): void
    {
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->member->id,
            'date' => now()->toDateString(),
            'description' => 'Share capital',
            'type' => 'credit',
            'amount' => $amount,
        ])->assertCreated();
    }

    private function releasedLoanWithShareCapital(float $amount): Loan
    {
        $product = LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);
        $service = app(LoanService::class);
        $loan = $service->createLoan([
            'borrower_id' => $this->member->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'deductions' => [['name' => 'Share Capital', 'amount' => $amount, 'type' => 'fixed']],
        ], $this->admin);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $released = $service->release($loan->fresh(), $this->admin);
        $released->update(['scb_amount' => 300]);

        return $released->fresh();
    }

    /** @return array<int, int> 3060 credit less debit in whole pesos, by journal branch */
    private function booksByBranch(): array
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->where('a.code', '3060')
            ->whereIn('j.status', ['posted', 'reversed'])
            ->groupBy('j.branch_id')
            ->orderBy('j.branch_id')
            ->selectRaw('j.branch_id, SUM(l.credit) - SUM(l.debit) as net')
            ->pluck('net', 'branch_id')
            ->map(fn ($centavos): int => intdiv((int) $centavos, 100))
            ->all();
    }

    /** @return array<int, int> share capital credit less debit in whole pesos, by the branch each row counts in */
    private function ledgerByBranch(): array
    {
        $branch = BranchAttribution::shareCapitalBranchSql();

        return DB::table('share_capital_ledger')
            ->leftJoin('borrowers', 'borrowers.id', '=', 'share_capital_ledger.borrower_id')
            ->groupByRaw($branch)
            ->orderByRaw($branch)
            ->selectRaw("{$branch} as branch_id, SUM(share_capital_ledger.credit) - SUM(share_capital_ledger.debit) as net")
            ->pluck('net', 'branch_id')
            ->map(fn ($pesos): int => (int) round((float) $pesos))
            ->all();
    }
}
