<?php

namespace App\Services\Accounting;

/**
 * The entry form's totals, worked out on the server (Phase 2, Accounting 10).
 *
 * Summed with Money::sum() over each side's column, exactly as
 * {@see JournalPoster::post()} sums the stored lines, so the preview of a
 * draft's lines equals what saving the draft stores and what posting it
 * checks. Pure: it reads and writes nothing.
 */
final class JournalPreview
{
    /**
     * @param  list<array{debit:int, credit:int}>  $lines  integer centavos
     * @return array{total_debit: int, total_credit: int, difference: int, out_by: int, heavier_side: 'debit'|'credit'|null, is_balanced: bool, line_count: int}
     */
    public static function summarise(array $lines): array
    {
        $totalDebit = Money::sum(array_column($lines, 'debit'));
        $totalCredit = Money::sum(array_column($lines, 'credit'));
        $difference = $totalDebit - $totalCredit;

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => $difference,
            'out_by' => abs($difference),
            'heavier_side' => match (true) {
                $difference > 0 => 'debit',
                $difference < 0 => 'credit',
                default => null,
            },
            'is_balanced' => Money::isBalanced($totalDebit, $totalCredit),
            'line_count' => count($lines),
        ];
    }
}
