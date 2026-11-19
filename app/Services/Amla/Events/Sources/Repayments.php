<?php

namespace App\Services\Amla\Events\Sources;

use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventSource;
use App\Services\BranchAttribution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Loan payments (AMLA design §2 "Repayments", §4.4): one event per receipt,
 * its `amount_paid` as Payment History shows it (build-up, premium and
 * overpayment are inside it), by its `method`, in the loan's branch, dated
 * `payment_date` and recorded at `created_at`. An extension's "pay" is a
 * receipt like any other. A receipt already voided when it is harvested is
 * harvested marked reversed.
 */
final class Repayments extends EventSource
{
    public function name(): string
    {
        return 'repayments';
    }

    public function words(): string
    {
        return 'Loan payments';
    }

    public function eventSource(): string
    {
        return 'repayment';
    }

    public function recordedColumn(): string
    {
        return 'repayments.created_at';
    }

    public function idColumn(): string
    {
        return 'repayments.id';
    }

    public function rows(): Builder
    {
        return DB::table('repayments')
            ->join('loans', 'loans.id', '=', 'repayments.loan_id')
            ->select([
                'repayments.id', 'repayments.created_at as recorded', 'repayments.receipt_number', 'repayments.payment_date',
                'repayments.method', 'repayments.reference_number', 'repayments.amount_paid', 'repayments.status',
                'repayments.voided_at', 'loans.borrower_id',
                DB::raw(BranchAttribution::loanBranchSql().' as branch_id'),
            ]);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        $voided = $row->status === 'voided';

        return new BuiltRow([$this->event($row, 'loan_payment', $harvestedBy, [
            'direction' => 'in',
            'is_internal' => false,
            'channel' => $row->method,
            'amount' => $this->positive((string) $row->amount_paid, 'Receipt '.($row->receipt_number ?? "#{$row->id}")),
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => (string) $row->payment_date,
            'reference' => $row->receipt_number,
            'channel_reference' => $row->reference_number,
            'reversed_at' => $voided ? ($row->voided_at ?? $row->recorded) : null,
            'reversal_source' => $voided ? 'repayment_void' : null,
            'reversal_source_id' => $voided ? (int) $row->id : null,
        ])]);
    }
}
