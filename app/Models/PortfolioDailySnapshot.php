<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The portfolio's arrears figures as they stood at the end of one day,
 * written by `portfolio:snapshot` (App\Console\Commands\SnapshotPortfolio).
 * One row per `snapshot_date`. A re-run of today overwrites its figures; a
 * past day that already has a row is only rewritten with `--force`.
 * `created_at` keeps the first write. PAR ratio for a day =
 * `par_n_amount` ÷ `outstanding_principal`.
 *
 * Since Phase 6 EB-B the row also holds the collectible and active loan
 * counts, PAR 60 and the PAR loan counts, and `line_count`, how many
 * PortfolioSnapshotLine rows add up to it (null on a day recorded before the
 * lines, or whose lines didn't add up). Those columns are null on older days.
 */
class PortfolioDailySnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_date',
        'loan_count',
        'active_loan_count',
        'overdue_loans_count',
        'overdue_amount',
        'total_outstanding',
        'outstanding_principal',
        'par_1_amount',
        'par_1_loans',
        'par_30_amount',
        'par_30_loans',
        'par_60_amount',
        'par_60_loans',
        'par_90_amount',
        'par_90_loans',
        'line_count',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'loan_count' => 'integer',
            'active_loan_count' => 'integer',
            'overdue_loans_count' => 'integer',
            'overdue_amount' => 'decimal:2',
            'total_outstanding' => 'decimal:2',
            'outstanding_principal' => 'decimal:2',
            'par_1_amount' => 'decimal:2',
            'par_1_loans' => 'integer',
            'par_30_amount' => 'decimal:2',
            'par_30_loans' => 'integer',
            'par_60_amount' => 'decimal:2',
            'par_60_loans' => 'integer',
            'par_90_amount' => 'decimal:2',
            'par_90_loans' => 'integer',
            'line_count' => 'integer',
        ];
    }
}
