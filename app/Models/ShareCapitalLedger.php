<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareCapitalLedger extends Model
{
    use HasFactory;

    protected $table = 'share_capital_ledger';

    protected $fillable = [
        'borrower_id',
        'date',
        'description',
        'reference',
        'debit',
        'credit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShareCapitalLedger $entry) {
            if (empty($entry->reference)) {
                $dateStr = Carbon::parse($entry->date ?? now())->format('Ymd');
                $lastRef = static::where('reference', 'like', "SC-{$dateStr}-%")
                    ->orderByDesc('id')
                    ->value('reference');
                $nextNum = $lastRef ? (int) substr($lastRef, -6) + 1 : 1;
                $entry->reference = 'SC-'.$dateStr.'-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
