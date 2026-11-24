<?php

namespace Tests\Feature\ProfitSharing;

use App\Models\AccountingAllowancePosting;
use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ExpenseRecorder;
use App\Services\Accounting\JournalPoster;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures for the Profit Sharing report: members and their share capital,
 * lending income (loans released with a recorded processing fee, and their
 * payments), expenses and allowance postings through the real recorders, and
 * the report itself.
 *
 * Expenses and allowance postings need the books (PostsJournals'
 * seedChartOfAccounts()); members, loans and payments don't.
 */
trait BuildsProfitSharing
{
    protected function member(string $first, string $last, ?Branch $branch = null): Borrower
    {
        return Borrower::factory()->create([
            'first_name' => $first,
            'middle_name' => null,
            'last_name' => $last,
            'suffix' => null,
            'branch_id' => ($branch ?? $this->branch)->id,
        ]);
    }

    /** A share capital entry, in pesos. */
    protected function capital(Borrower $member, string $date, float $credit, float $debit = 0): ShareCapitalLedger
    {
        return ShareCapitalLedger::factory()->create([
            'borrower_id' => $member->id,
            'branch_id' => $member->branch_id,
            'date' => $date,
            'credit' => $credit,
            'debit' => $debit,
        ]);
    }

    /** A loan released at `$releasedAt` with its processing fee recorded as deducted, as LoanService writes it. */
    protected function releasedLoan(string $releasedAt, float $processingFee = 0, ?Branch $branch = null): Loan
    {
        $branch ??= $this->branch;
        $principal = 50000;

        return Loan::factory()->create([
            'borrower_id' => $this->member('Loan', 'Holder '.Str::random(6), $branch)->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'branch_id' => $branch->id,
            'created_by' => $this->admin->id,
            'principal_amount' => $principal,
            'deductions' => $processingFee > 0 ? [[
                'name' => 'Processing Fee',
                'amount' => $processingFee,
                'type' => 'fixed',
                'original_value' => $processingFee,
            ]] : [],
            'total_deductions' => $processingFee,
            'net_proceeds' => $principal - $processingFee,
            'status' => 'ongoing',
            'released_at' => $releasedAt,
        ]);
    }

    /** A payment on `$loan`, in pesos. */
    protected function payment(Loan $loan, string $date, float $interest, float $penalty = 0, float $principal = 0, string $status = 'posted'): Repayment
    {
        return Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $date,
            'amount_paid' => $interest + $penalty + $principal,
            'principal_applied' => $principal,
            'interest_applied' => $interest,
            'penalty_applied' => $penalty,
            'status' => $status,
            'received_by' => $this->admin->id,
        ]);
    }

    /** An expense paid in cash, through the real recorder (expense and journal together). Centavos. */
    protected function expense(string $date, int $centavos, ?Branch $branch = null, bool $withBranch = true): AccountingExpense
    {
        return app(ExpenseRecorder::class)->record([
            'date' => $date,
            'payee' => 'Supplier '.Str::random(4),
            'expense_account_id' => $this->account('5030'),
            'amount' => $centavos,
            'payment_account_id' => $this->account('1010'),
            'branch_id' => $withBranch ? ($branch ?? $this->branch)->id : null,
        ], $this->admin->id);
    }

    /** One branch's allowance posting, as AllowancePoster writes it: the row, then its journal. Signed centavos. */
    protected function allowancePosting(string $asOf, int $centavos, ?Branch $branch = null): AccountingAllowancePosting
    {
        $branch ??= $this->branch;

        $row = AccountingAllowancePosting::query()->create([
            'run_id' => (string) Str::uuid(),
            'as_of' => $asOf,
            'branch_id' => $branch->id,
            'required' => max(0, $centavos),
            'previous_balance' => 0,
            'adjustment' => $centavos,
            'created_by' => $this->admin->id,
        ]);

        $journal = app(AutomaticPoster::class)->allowanceAdjustment($row, $centavos, $branch->id, $asOf, $this->admin->id);
        $row->forceFill(['accounting_journal_id' => $journal->id])->save();

        return $row;
    }

    protected function reverseJournal(int $journalId, string $date): AccountingJournal
    {
        return app(JournalPoster::class)->reverse(AccountingJournal::findOrFail($journalId), $date, 'Entered in error', $this->admin->id);
    }

    protected function profitSharing(string $cutoff, ?int $branchId = null): TestResponse
    {
        $query = http_build_query(array_filter(['cutoff_date' => $cutoff, 'branch_id' => $branchId]));

        return $this->getJson('/api/reports/profit-sharing?'.$query);
    }
}
