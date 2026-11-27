<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoCreditRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'total_amount',
        'member_count',
        // How many pledges it skipped (finish run round 7); its skipped list is
        // AutoCreditRunSkip.
        'skipped_count',
        // True once the run links its rows (share_capital_ledger.auto_credit_run_id).
        'links_rows',
        'processed_at',
        'processed_by',
        'status',
        // The cycle the run is for, a month's 15th or its 30th (finish run
        // round 8). Null on runs made before: their rows count by their date.
        'cycle_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'skipped_count' => 'integer',
            'links_rows' => 'boolean',
            'processed_at' => 'datetime',
            'cycle_date' => 'date',
        ];
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function skips(): HasMany
    {
        return $this->hasMany(AutoCreditRunSkip::class, 'run_id');
    }

    /**
     * The branches the run covered (round 9): its caller's branches at run
     * time, every branch for `branches:view_all`. None on runs made before.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'auto_credit_run_branches', 'auto_credit_run_id', 'branch_id');
    }
}
