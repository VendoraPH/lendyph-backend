<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerSubmissionToken extends Model
{
    protected $fillable = [
        'borrower_id',
        'token',
        'expires_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
