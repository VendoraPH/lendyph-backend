<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The member page's Ledger (GET /borrowers/{id}/ledger): the rows of each
 * loan's own Ledger (LoanLedgerService) in one list, in date order, each with
 * the member's running balance after it, all worked out here so the browser
 * only displays them. Reads only.
 *
 * The balance is what the loan Ledger's balances add up to: principal, interest
 * and penalty. It leaves out the insurance premium owed, as the loan Ledger
 * does, and share capital, so a release's debit is its principal plus the
 * schedule's interest, and the closing can differ from the Member Overview's
 * `total_outstanding` by the premium owed.
 *
 * A row's debit or credit is what its loan Ledger row moved that loan's balance
 * by, read from the balances themselves, so a signed adjustment and a balance
 * moved to a restructured loan need no rule of their own. A payment's credit is
 * the principal, interest and penalty it applied; its `amount_paid`, `insurance`
 * and `scb` say what else it took.
 *
 * Which loans: every ever-released loan (Loan::EVER_RELEASED_STATUSES) the
 * viewer may see. A member's older loan stays in the branch it was booked in, so
 * seeing the member doesn't mean seeing all their loans.
 *
 * A voided payment and its void are left out as a pair. Between the payment's
 * date and the void's date the member balance therefore reads as if the payment
 * was never made, the one place it differs from the loan Ledger. A voided
 * payment that older data recorded with no void date has no void row, so it
 * stays, or the closing would miss the loan's.
 *
 * Within a day the rows run loan by loan, in release order, each in its own
 * Ledger order, which leaves the day's closing balance the same.
 */
final class MemberLedgerService
{
    /** The loan Ledger's kinds that are a payment. */
    private const PAYMENT_KINDS = ['repayment', 'extension_payment'];

    public function __construct(private LoanLedgerService $loanLedger) {}

    /**
     * @return array{rows: list<array<string, mixed>>, closing: array{principal: float|int, interest: float|int, penalty: float|int, balance: float|int}}
     */
    public function forMember(Borrower $borrower, User $viewer): array
    {
        $loans = $borrower->loans()
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES)
            ->whereNotNull('loans.released_at')
            ->visibleTo($viewer)
            ->orderBy('loans.released_at')
            ->orderBy('loans.id')
            ->get();

        $repayments = Repayment::whereIn('loan_id', $loans->pluck('id'))
            ->get(['id', 'receipt_number', 'amount_paid'])
            ->keyBy('id');

        $entries = [];
        $closing = ['principal' => 0, 'interest' => 0, 'penalty' => 0];

        foreach ($loans as $order => $loan) {
            $ledger = $this->loanLedger->forLoan($loan);

            foreach ($closing as $column => $centavos) {
                $closing[$column] = $centavos + LoanService::toCentavos($ledger['closing'][$column]);
            }

            array_push($entries, ...$this->entries($loan, $order, $ledger['rows'], $repayments));
        }

        usort($entries, fn (array $a, array $b): int => [$a['date'], $a['order'], $a['index']] <=> [$b['date'], $b['order'], $b['index']]);

        return [
            'rows' => $this->walk($entries),
            'closing' => [
                'principal' => $closing['principal'] / 100,
                'interest' => $closing['interest'] / 100,
                'penalty' => $closing['penalty'] / 100,
                'balance' => array_sum($closing) / 100,
            ],
        ];
    }

    /**
     * One loan's rows, each with what it moved the loan's balance by, less the
     * voided payments and their voids.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  EloquentCollection<int, Repayment>  $repayments  by id
     * @return list<array<string, mixed>>
     */
    private function entries(Loan $loan, int $order, array $rows, EloquentCollection $repayments): array
    {
        $voided = array_column(array_filter($rows, fn (array $row): bool => $row['kind'] === 'void'), 'repayment_id');
        $reference = $loan->loan_account_number ?? $loan->application_number;
        $before = 0;
        $entries = [];

        foreach ($rows as $index => $row) {
            $after = LoanService::toCentavos($row['balances']['principal'])
                + LoanService::toCentavos($row['balances']['interest'])
                + LoanService::toCentavos($row['balances']['penalty']);
            $moved = $after - $before;
            $before = $after;

            if (in_array($row['repayment_id'], $voided, true)) {
                continue;
            }

            $repayment = $row['repayment_id'] === null ? null : $repayments->get($row['repayment_id']);

            $entries[] = [
                'date' => $row['date'],
                'order' => $order,
                'index' => $index,
                'moved' => $moved,
                'description' => $row['description'],
                'reference' => $repayment->receipt_number ?? $reference,
                'loan_id' => $loan->id,
                'loan_account_number' => $loan->loan_account_number,
                'kind' => $row['kind'],
                'repayment_id' => $row['repayment_id'],
                'payment' => in_array($row['kind'], self::PAYMENT_KINDS, true)
                    ? [
                        'amount_paid' => LoanService::toCentavos((float) $repayment?->amount_paid) / 100,
                        'insurance' => $row['insurance'],
                        'scb' => $row['scb'],
                    ]
                    : [],
            ];
        }

        return $entries;
    }

    /**
     * The member's running balance after each row, in whole centavos.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function walk(array $entries): array
    {
        $balance = 0;
        $rows = [];

        foreach ($entries as $position => $entry) {
            $balance += $entry['moved'];

            $rows[] = [
                'id' => $position + 1,
                'date' => $entry['date'],
                'description' => $entry['description'],
                'reference' => $entry['reference'],
                'debit' => max(0, $entry['moved']) / 100,
                'credit' => max(0, -$entry['moved']) / 100,
                'balance' => $balance / 100,
                'loan_id' => $entry['loan_id'],
                'loan_account_number' => $entry['loan_account_number'],
                'kind' => $entry['kind'],
                'repayment_id' => $entry['repayment_id'],
                ...$entry['payment'],
            ];
        }

        return $rows;
    }
}
