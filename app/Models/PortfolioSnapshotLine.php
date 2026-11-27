<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One branch × loan product's share of a day's portfolio snapshot (Phase 6
 * EB-B), written by `portfolio:snapshot` with the day's
 * PortfolioDailySnapshot row. A day's lines add up to that row, figure by
 * figure; a pair with no collectible loan that day has no line. Figures are
 * the snapshot's own rules: collectible loans (released, ongoing, defaulted),
 * overdue by the grace rule, PAR from the bare due date (PortfolioRisk).
 */
class PortfolioSnapshotLine extends Model
{
    public const UPDATED_AT = null;

    /** The money and count figures a line holds, in the order they're stored. */
    public const FIGURES = [
        'loan_count', 'active_loan_count', 'overdue_loans_count', 'overdue_amount', 'total_outstanding',
        'outstanding_principal', 'par_1_amount', 'par_1_loans', 'par_30_amount', 'par_30_loans',
        'par_60_amount', 'par_60_loans', 'par_90_amount', 'par_90_loans',
    ];

    /** The figures that are pesos; the rest are counts. */
    public const MONEY_FIGURES = [
        'overdue_amount', 'total_outstanding', 'outstanding_principal',
        'par_1_amount', 'par_30_amount', 'par_60_amount', 'par_90_amount',
    ];

    protected $fillable = ['snapshot_date', 'branch_id', 'loan_product_id', ...self::FIGURES];

    protected function casts(): array
    {
        $casts = ['snapshot_date' => 'date', 'branch_id' => 'integer', 'loan_product_id' => 'integer'];

        foreach (self::FIGURES as $figure) {
            $casts[$figure] = in_array($figure, self::MONEY_FIGURES, true) ? 'decimal:2' : 'integer';
        }

        return $casts;
    }
}
