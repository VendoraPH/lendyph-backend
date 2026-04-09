<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareCapitalPledge extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrower_id',
        'amount',
        'schedule',
        'auto_credit',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'auto_credit' => 'boolean',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
