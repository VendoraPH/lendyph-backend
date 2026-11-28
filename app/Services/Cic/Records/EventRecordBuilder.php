<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;

/**
 * The NE (Negative Event) record: only a reported member recorded as
 * deceased (event U, status AC), with the end date and reason staff entered.
 * No other Lendy record backs an event code: overdue and default stay in the
 * contract fields, and AMLA data never feeds NE (prompt §8).
 */
final class EventRecordBuilder
{
    public const TYPE = 'NE';

    public const DECEASED = 'deceased';

    public function __construct(private readonly CicBuildContext $context) {}

    public function applies(Borrower $member): bool
    {
        return $member->status === self::DECEASED;
    }

    public function build(Borrower $member): CicRecord
    {
        $endedOn = CicFormat::date($member->membership_ended_on);

        return new CicRecord(self::TYPE, CicFormat::text($member->borrower_code), CicFormat::compact([
            'NE1' => self::TYPE,
            'NE2' => $this->context->providerCode,
            'NE3' => $this->context->branchCode($member->branch_id),
            'NE4' => $this->context->referenceDate,
            'NE5' => CicFormat::text($member->borrower_code),
            'NE6' => 'U',
            'NE7' => CicFormat::text($member->membership_end_reason),
            'NE8' => $endedOn,
            'NE9' => 'AC',
            'NE10' => $endedOn,
        ]), borrowerId: $member->id);
    }
}
