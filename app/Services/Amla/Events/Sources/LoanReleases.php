<?php

namespace App\Services\Amla\Events\Sources;

use App\Models\Loan;
use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventCannotBeBuilt;
use App\Services\Amla\Events\EventSource;
use App\Services\Amla\Events\PesoAmount;
use App\Services\BranchAttribution;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Loan releases (AMLA design §2 "Loan releases", §4.4): one event per release,
 * recorded at `released_at`.
 * - A release pays out its `net_proceeds`, by `release_method`. What is
 *   withheld at release is non-cash and no event.
 * - A restructure (`source_loan_id`) moves no new funds: its principal is an
 *   internal event linked to the loan it restructures. The deductions it
 *   collects from the member at release (`total_deductions`, posted to the
 *   release method's account) are money in.
 * - An imported loan (`external_loan_no` or `imported_arrears_baseline`) was
 *   released before Lendy and is no event.
 */
final class LoanReleases extends EventSource
{
    public function name(): string
    {
        return 'loans';
    }

    public function words(): string
    {
        return 'Loan releases';
    }

    public function eventSource(): string
    {
        return 'loan';
    }

    public function recordedColumn(): string
    {
        return 'loans.released_at';
    }

    public function idColumn(): string
    {
        return 'loans.id';
    }

    public function rows(): Builder
    {
        return DB::table('loans')
            ->whereNotNull('loans.released_at')
            ->whereNull('loans.external_loan_no')
            ->whereNull('loans.imported_arrears_baseline')
            ->select([
                'loans.id', 'loans.released_at as recorded', 'loans.borrower_id', 'loans.source_loan_id', 'loans.loan_account_number',
                'loans.application_number',
                'loans.release_method', 'loans.release_reference', 'loans.principal_amount', 'loans.total_deductions', 'loans.net_proceeds',
                DB::raw(BranchAttribution::loanBranchSql().' as branch_id'),
            ]);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        // The loan by its display number (round 9), "Loan #id" only when it has
        // none. No file Loan No.: imported loans are never a release event.
        $number = Loan::displayNumberOf($row->loan_account_number, null, $row->application_number);
        $common = [
            'borrower_id' => self::intOrNull($row->borrower_id),
            'gcash_non_member_id' => null,
            'branch_id' => self::intOrNull($row->branch_id),
            'occurred_on' => substr((string) $row->recorded, 0, 10),
            'reference' => $row->loan_account_number,
            'channel' => $row->release_method,
            'channel_reference' => $row->release_reference,
        ];
        $loan = $number ?? "Loan #{$row->id}";

        if ($row->source_loan_id === null) {
            if ($row->net_proceeds === null || PesoAmount::centavos((string) $row->net_proceeds) < 0) {
                throw new EventCannotBeBuilt("The release of {$loan} has no net proceeds of ₱0.00 or more.");
            }

            return new BuiltRow([$this->event($row, 'loan_release', $harvestedBy, [
                ...$common, 'direction' => 'out', 'is_internal' => false, 'amount' => PesoAmount::normal((string) $row->net_proceeds),
            ])]);
        }

        $events = [$this->event($row, 'loan_restructure', $harvestedBy, [
            ...$common,
            'direction' => 'out',
            'is_internal' => true,
            'amount' => $this->positive(self::text($row->principal_amount), "The restructure {$loan}"),
            'linked_source' => 'loan',
            'linked_source_id' => (int) $row->source_loan_id,
        ])];

        if ($row->total_deductions !== null && PesoAmount::centavos((string) $row->total_deductions) > 0) {
            $events[] = $this->event($row, 'restructure_deductions_collected', $harvestedBy, [
                ...$common,
                'direction' => 'in',
                'is_internal' => false,
                'amount' => PesoAmount::normal((string) $row->total_deductions),
                'linked_source' => 'loan',
                'linked_source_id' => (int) $row->source_loan_id,
            ]);
        }

        return new BuiltRow($events);
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
