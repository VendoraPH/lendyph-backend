<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The once-only marker for a reminders staff notification: unique on kind and
 * subject, written with insertOrIgnore in the same transaction as the
 * notification (as NotifyPastDueLoans does), so a concurrent run notifies
 * nobody twice and a failed send leaves no marker. The subject never holds a
 * contact value in clear.
 */
class ReminderStaffNotice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'subject'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
