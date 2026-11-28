<?php

namespace App\Services\Cic\Capture;

use App\Services\Cic\Records\CicFormat;
use App\Services\Cic\Records\CicRecord;

/**
 * A correction's contract record (money review M-H1): the month-end capture's
 * frozen figures, with the contract's classification and key re-read from
 * today's data, so a month whose file couldn't be made (an unmapped product,
 * a loan number CIC can't take) can still be filed.
 *
 * - **Re-read from today:** the record type, the record key and Provider
 *   Contract No (CI7/CN7), the contract type (CI8/CN8), the transaction type
 *   (CI22/CN21) and the purpose of credit (CI23), with the builder's issues
 *   about them.
 * - **Kept from the month end:** every money and date field, the guarantees
 *   and linked subjects, and every other build issue.
 * - **A changed record type** moves the frozen fields to the other layout:
 *   positions 1–20 are shared, the guarantee and linked-subject blocks sit 16
 *   positions later on CI, and outstanding and overdue amount move between
 *   CI32/CI34 and CN23/CN24. CN holds no instalment figures, so a CN contract
 *   that becomes CI takes them from today, with a warning.
 */
final class CicCorrectionOverlay
{
    /** The builder's issues about the re-read fields: taken from today's build. */
    public const REREAD_ISSUES = [
        'CLASSIFICATION_MISMATCH', 'CONTRACT_TYPE_FOR_OTHER_RECORD', 'UNMAPPED_PRODUCT',
        'OVERRIDE_AGAINST_STRUCTURE', 'CONTRACT_NO_TOO_LONG', 'DUPLICATE_CONTRACT',
    ];

    /** CI's guarantee (42) and linked-subject (126) blocks start 16 positions after CN's (26, 110). */
    private const BLOCK_OFFSET = 16;

    /** Positions shared by both layouts. */
    private const SHARED = 20;

    /** @var array<string, list<int>> the type-specific positions re-read from today */
    private const REREAD = ['CI' => [22, 23], 'CN' => [21]];

    /**
     * @param  array<string, string>  $frozen  the month-end record's values
     * @param  list<array{field_id: string|null, severity: string, code: string, message: string}>  $frozenIssues
     */
    public static function merge(string $frozenType, array $frozen, array $frozenIssues, CicRecord $live): CicRecord
    {
        $type = $live->type;
        $issues = [];
        $values = $type === $frozenType ? $frozen : self::moved($frozenType, $frozen, $type);

        if ($frozenType === 'CN' && $type === 'CI') {
            foreach (range(21, 35) as $n) {
                if (! in_array($n, [32, 34], true) && isset($live->values["CI{$n}"])) {
                    $values["CI{$n}"] = $live->values["CI{$n}"];
                }
            }

            $issues[] = ['field_id' => 'CI1', 'severity' => 'warning', 'code' => 'CORRECTION_RECORD_TYPE_CHANGED',
                'message' => 'This contract is now reported as an instalment (CI) contract, not CN as at the month end, so its instalment figures (number, amounts and dates of instalments) are read from today\'s data. Its balances are kept from the month end.'];
        }

        foreach ([1, 7, 8, ...self::REREAD[$type]] as $n) {
            $id = "{$type}{$n}";
            unset($values[$id]);

            if (($live->values[$id] ?? '') !== '') {
                $values[$id] = $live->values[$id];
            }
        }

        $kept = array_values(array_filter($frozenIssues, fn (array $i) => ! in_array($i['code'], self::REREAD_ISSUES, true)));
        $reread = array_values(array_filter($live->buildIssues, fn (array $i) => in_array($i['code'], self::REREAD_ISSUES, true)));

        return new CicRecord($type, $live->key, CicFormat::compact($values), [...$reread, ...$kept, ...$issues], $live->borrowerId, $live->loanId, $live->structure);
    }

    /**
     * The frozen fields placed in the other layout.
     *
     * @param  array<string, string>  $frozen
     * @return array<string, string>
     */
    private static function moved(string $from, array $frozen, string $to): array
    {
        $values = [];

        foreach ($frozen as $id => $value) {
            $n = (int) substr($id, 2);
            $target = match (true) {
                $n <= self::SHARED => $n,
                $from === 'CI' && $n >= 42 => $n - self::BLOCK_OFFSET,
                $from === 'CN' && $n >= 26 => $n + self::BLOCK_OFFSET,
                default => null,
            };

            if ($target !== null) {
                $values["{$to}{$target}"] = $value;
            }
        }

        if ($to === 'CN') {
            $values['CN23'] = $frozen['CI32'] ?? '0';
            $values['CN24'] = $frozen['CI34'] ?? '0';
        } else {
            if (isset($frozen['CN23']) && ($frozen['CN9'] ?? '') === 'AC') {
                $values['CI32'] = $frozen['CN23'];
            }

            if (isset($frozen['CN24'])) {
                $values['CI34'] = $frozen['CN24'];
            }
        }

        return $values;
    }
}
