<?php

namespace Tests\Feature\Ledger\Deferral;

use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\PostingRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared steps for the deferral tests: a restructure set up the way A1 stores
 * it, payments through the real endpoint, and readings of the books.
 *
 * Loans are released before the chart is seeded, so the ordinary release
 * path posts nothing and each test posts only what it is about.
 */
trait DeferralScenarios
{
    protected function poster(): AutomaticPoster
    {
        return app(AutomaticPoster::class);
    }

    /** Pesos as the decimal string a `decimal:2` column stores. */
    protected function pesos(int $centavos): string
    {
        return sprintf('%s%d.%02d', $centavos < 0 ? '-' : '', intdiv(abs($centavos), 100), abs($centavos) % 100);
    }

    /** Make `$new` a restructure of `$source` with the split A1 stores at release. */
    protected function restructureOf(Loan $new, Loan $source, int $carried, int $interest, int $penalty, int $writtenOff): Loan
    {
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => $this->pesos($carried),
            'restructure_capitalised_interest' => $this->pesos($interest),
            'restructure_capitalised_penalty' => $this->pesos($penalty),
            'restructure_written_off_principal' => $this->pesos($writtenOff),
        ]);

        return $new->fresh();
    }

    /** Post a restructure's J1 and J2, as A1's release does (inside the test's transaction). */
    protected function release(Loan $new, Loan $source): array
    {
        return $this->poster()->restructureRelease($new->fresh(), $source->fresh(), $this->admin->id, 'cash');
    }

    protected function journalFor(Model $postable, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();
    }

    protected function sumOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    protected function described(AccountingJournal $journal, string $description, string $side): int
    {
        return (int) $journal->lines()->where('description', $description)->sum($side);
    }

    protected function pay(Loan $loan, int $pesos = 11800): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $pesos,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    /** Pay ₱11,800.00 at a time until the loan completes; the repayments, in order. */
    protected function payUntilCompleted(Loan $loan): array
    {
        $repayments = [];

        for ($k = 0; $k < 12 && $loan->fresh()->status !== 'completed'; $k++) {
            $repayments[] = $this->pay($loan);
        }

        $this->assertSame('completed', $loan->fresh()->status, 'the loan never completed');

        return $repayments;
    }

    /**
     * Request, approve and apply a balance adjustment by amount through the
     * real endpoints, which restate the periods and post its journal; the
     * adjustment, as applied.
     */
    protected function applyReduction(Loan $loan, int $signedCentavos): LoanAdjustment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/adjustments", [
            'adjustment_type' => 'balance_adjustment',
            'new_values' => ['adjustment_amount' => $signedCentavos / 100],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/loan-adjustments/{$id}/approve")->assertOk();
        $this->patchJson("/api/loan-adjustments/{$id}/apply")->assertOk();

        return LoanAdjustment::findOrFail($id);
    }

    /** The principal still unpaid on a loan's open periods, in centavos. */
    protected function unpaidPrincipalOf(Loan $loan): int
    {
        $pesos = AmortizationSchedule::query()
            ->where('loan_id', $loan->id)
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->selectRaw('COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) AS unpaid')
            ->value('unpaid');

        return (int) round(((float) $pesos) * 100);
    }

    /** What a loan's collections recognised of its deferral, by part, in centavos. */
    protected function recognisedOn(Loan $loan): array
    {
        $journals = AccountingJournal::query()
            ->where('status', 'posted')
            ->where('source', 'loan_collection')
            ->where('postable_type', (new Repayment)->getMorphClass())
            ->whereIn('postable_id', Repayment::query()->where('loan_id', $loan->id)->select('id'))
            ->pluck('id');

        $sum = fn (string $marker): int => (int) DB::table('accounting_journal_lines')
            ->whereIn('accounting_journal_id', $journals)
            ->where('description', $marker)
            ->sum('credit');

        return ['interest' => $sum(PostingRules::DEFERRED_INTEREST_LINE), 'penalty' => $sum(PostingRules::DEFERRED_PENALTY_LINE)];
    }

    /** 1210's credit balance across every journal on the books, in centavos. */
    protected function unearnedBalance(): int
    {
        return (int) DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('l.accounting_account_id', $this->account('1210'))
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS balance')
            ->value('balance');
    }

    /** @param  list<Loan>  $loans */
    protected function unrecognisedTotal(array $loans): int
    {
        return array_sum(array_map(
            fn (Loan $loan): int => array_sum($this->poster()->unrecognisedDeferral($loan->fresh())),
            $loans,
        ));
    }

    protected function assertEveryJournalBalances(): void
    {
        $journals = AccountingJournal::query()->get();
        $this->assertNotEmpty($journals);

        foreach ($journals as $journal) {
            $debit = (int) $journal->lines()->sum('debit');
            $credit = (int) $journal->lines()->sum('credit');
            $this->assertSame($debit, $credit, "{$journal->journal_no} does not balance");
            $this->assertSame($journal->total_debit, $debit, "{$journal->journal_no}'s header disagrees with its lines");
        }
    }

    /**
     * A journal's lines as a posting builder returns them.
     *
     * @return list<array{account_id: int, debit: int, credit: int, description: ?string}>
     */
    protected function postedLines(AccountingJournal $journal): array
    {
        return $journal->lines()->orderBy('line_no')->get()
            ->map(fn ($line): array => [
                'account_id' => (int) $line->accounting_account_id,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
                'description' => $line->description,
            ])->all();
    }

    /**
     * @param  array{lines: list<array<string, mixed>>}  $posting
     * @return list<array{account_id: int, debit: int, credit: int, description: ?string}>
     */
    protected function builtLines(array $posting): array
    {
        return array_map(static fn (array $line): array => [
            'account_id' => (int) $line['account_id'],
            'debit' => (int) $line['debit'],
            'credit' => (int) $line['credit'],
            'description' => $line['description'] ?? null,
        ], $posting['lines']);
    }
}
