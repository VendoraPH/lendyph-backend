<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The minute job's watermark for one watched table: rows whose `updated_at`
 * is after it have not been scanned yet (ChangeScanner).
 */
class CreditScoringCursor extends Model
{
    protected $fillable = [
        'source',
        'watermark',
    ];

    protected function casts(): array
    {
        return [
            'watermark' => 'datetime',
        ];
    }
}
