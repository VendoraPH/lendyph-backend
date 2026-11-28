<?php

namespace App\Services\Cic\Capture;

use App\Services\Cic\Records\CicFormat;
use App\Services\Cic\Records\CicRecord;

/**
 * A correction's contract record (money review M-H1): the month-end capture's
 * frozen record, with its branch, key and classification re-read from today's
 * data, so a month whose file couldn't be made (an unmapped product, a loan
 * number CIC can't take, a branch code CIC can't take) can still be filed.
 *
 * - **Re-read from today:** the branch code (CI3/CN3), the record key and
 *   Provider Contract No (CI7/CN7), the contract type (CI8/CN8), the
 *   transaction type (CI22/CN21) and the purpose of credit (CI23), with the
 *   builder's issues about them.
 * - **Kept from the month end:** the record type, every money and date field,
 *   the guarantees and linked subjects, and every other build issue.
 * - **A record type that changed since** (re-review MM2) is a warning: the
 *   record keeps the month end's type and codes, never mixing today's figures
 *   with frozen ones; only its branch and key are re-read.
 */
final class CicCorrectionOverlay
{
    /** The builder's issues about the re-read classification: taken from today's build. */
    public const REREAD_ISSUES = [
        'CLASSIFICATION_MISMATCH', 'CONTRACT_TYPE_FOR_OTHER_RECORD', 'UNMAPPED_PRODUCT', 'OVERRIDE_AGAINST_STRUCTURE',
        ...self::KEY_ISSUES,
    ];

    /** The builder's issues about the key: taken from today's build whatever the record type. */
    public const KEY_ISSUES = ['CONTRACT_NO_TOO_LONG', 'DUPLICATE_CONTRACT'];

    /** @var array<string, list<int>> each layout's re-read classification positions */
    private const CLASSIFICATION = ['CI' => [8, 22, 23], 'CN' => [8, 21]];

    /**
     * @param  array<string, string>  $frozen  the month-end record's values
     * @param  list<array{field_id: string|null, severity: string, code: string, message: string}>  $frozenIssues
     */
    public static function merge(string $frozenType, array $frozen, array $frozenIssues, CicRecord $live): CicRecord
    {
        $sameType = $live->type === $frozenType;
        $values = $frozen;
        $reread = $sameType ? self::REREAD_ISSUES : self::KEY_ISSUES;

        // Positions 3 and 7 mean the same in both layouts.
        foreach ([3, 7, ...($sameType ? self::CLASSIFICATION[$frozenType] : [])] as $n) {
            $values["{$frozenType}{$n}"] = $live->values["{$live->type}{$n}"] ?? '';
        }

        $issues = [
            ...array_values(array_filter($live->buildIssues, fn (array $i) => in_array($i['code'], $reread, true))),
            ...array_values(array_filter($frozenIssues, fn (array $i) => ! in_array($i['code'], $reread, true))),
        ];

        if (! $sameType) {
            $issues[] = ['field_id' => "{$frozenType}1", 'severity' => 'warning', 'code' => 'CORRECTION_RECORD_TYPE_CHANGED', 'message' => sprintf(
                'This contract is now classified as a %s contract, but a correction keeps the month end\'s record type (%s), so its codes and figures are kept as they were. The new record type goes out from the next month\'s file.',
                $live->type,
                $frozenType,
            )];
        }

        return new CicRecord($frozenType, $live->key, CicFormat::compact($values), $issues, $live->borrowerId, $live->loanId, $live->structure);
    }
}
