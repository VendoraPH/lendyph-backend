<?php

namespace Tests\Feature\Ledger\Release;

use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared set-up for the release journal tests: approved loans and approved
 * restructures built through the API a cashier uses, and the journal reads
 * the assertions need. Amounts the journals hold are centavos.
 *
 * Needs SetupLendyPH and PostsJournals on the test class.
 */
trait ReleasesLoans
{
    private ?User $secondApprover = null;

    /** An approved ordinary loan of ₱60,000.00 over 6 months. */
    private function approvedLoan(): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
        ]);

        $service = app(LoanService::class);

        $loan = $service->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        return $loan->fresh();
    }

    /**
     * An approved restructure of `$source` at `$principal`, through the API.
     * createReleasedLoan() leaves ₱60,000.00 principal and ₱10,800.00 interest
     * owed, so ₱70,800.00 restructures everything.
     */
    private function approvedRestructureOf(Loan $source, float $principal, ?string $remarks = null): Loan
    {
        $response = $this->postJson("/api/loans/{$source->id}/restructure", array_filter([
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'remarks' => $remarks,
        ], fn (mixed $value): bool => $value !== null))->assertCreated();

        $restructure = Loan::findOrFail($response->json('data.id'));

        // Restructure approval is dual control, so the sign-off is a second user.
        $this->patchJson("/api/loans/{$restructure->id}/submit")->assertOk();
        $this->actingAs($this->secondApprover ??= tap(
            User::factory()->create(),
            fn (User $user) => $user->assignRole(Role::where('name', 'admin')->first()),
        ));
        $this->patchJson("/api/loans/{$restructure->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);

        return $restructure->fresh();
    }

    /** Record a cash payment on a released loan, through the API. */
    private function payOn(Loan $loan, float $pesos): void
    {
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $pesos,
            'method' => 'cash',
        ])->assertCreated();
    }

    private function journalFor(Model $postable, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();
    }

    private function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round(((float) $pesos) * 100);
    }

    /** Assert a journal balances to the centavo, and return its debit total. */
    private function assertBalances(AccountingJournal $journal): int
    {
        $debit = (int) $journal->lines()->sum('debit');
        $this->assertSame($debit, (int) $journal->lines()->sum('credit'), "journal {$journal->id} does not balance");

        return $debit;
    }

    /**
     * The four split columns stored on a restructure, as stored.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
     */
    private function storedSplit(Loan $loan): array
    {
        $loan = $loan->fresh();

        return [
            $loan->restructure_carried_principal,
            $loan->restructure_capitalised_interest,
            $loan->restructure_capitalised_penalty,
            $loan->restructure_written_off_principal,
        ];
    }

    /**
     * Every row a release could write, to show a refused one wrote nothing.
     *
     * @return array<string, mixed>
     */
    private function releaseState(): array
    {
        $rows = fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        return [
            'loans' => $rows('loans'),
            'schedules' => $rows('amortization_schedules'),
            'steps' => $rows('loan_approval_steps'),
            'journals' => $rows('accounting_journals'),
            'journal_lines' => $rows('accounting_journal_lines'),
            'share_capital' => $rows('share_capital_ledger'),
            'audit' => AuditLog::count(),
        ];
    }
}
