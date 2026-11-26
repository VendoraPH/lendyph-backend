<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'skipped_count' => 'integer',
            'links_rows' => 'boolean',
            'processed_at' => 'datetime',
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
}
