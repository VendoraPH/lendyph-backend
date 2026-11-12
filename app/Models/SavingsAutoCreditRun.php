<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One processed savings auto-credit run (gap round S3): its cycle (a 15th or
 * a month's last day), the date its deposits posted (today: a late run still
 * records its cycle), the settlement method, and its figures. Written once.
 *
 * @property int $id
 * @property string $method
 */
class SavingsAutoCreditRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cycle_date',
        'posted_on',
        'method',
        'reference_number',
        'posted_count',
        'posted_total',
        'skipped_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cycle_date' => 'date',
            'posted_on' => 'date',
            'posted_total' => 'decimal:2',
            'posted_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function postings(): HasMany
    {
        return $this->hasMany(SavingsAutoCreditPosting::class, 'run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
