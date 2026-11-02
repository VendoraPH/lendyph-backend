<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The portfolio's arrears figures as they stood at the end of one day,
 * written by `portfolio:snapshot` (App\Console\Commands\SnapshotPortfolio).
 * One row per `snapshot_date`; a re-run of the same day overwrites its
 * figures, and `created_at` keeps the first write.
 */
class PortfolioDailySnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'snapshot_date',
        'overdue_loans_count',
        'overdue_amount',
        'total_outstanding',
        'par_1_amount',
        'par_30_amount',
        'par_90_amount',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'overdue_loans_count' => 'integer',
            'overdue_amount' => 'decimal:2',
            'total_outstanding' => 'decimal:2',
            'par_1_amount' => 'decimal:2',
            'par_30_amount' => 'decimal:2',
            'par_90_amount' => 'decimal:2',
        ];
    }
}
