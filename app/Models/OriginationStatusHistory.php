<?php

namespace App\Models;

use App\Services\LoanOrigination\Staff\Observers\OriginationStatusHistoryObserver;
use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of an application's status, written by ApplicationTransitions
 * only: from, to, who (`actor_type` staff, applicant, system, handoff or
 * loan; `user_id` for staff) and the note. Append-only.
 *
 * Each new row tells staff about the move (LO-B3, OriginationStaffNotices).
 */
#[ObservedBy(OriginationStatusHistoryObserver::class)]
class OriginationStatusHistory extends Model
{
    use OriginationAppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'origination_status_history';

    protected $fillable = ['application_id', 'from_status', 'to_status', 'actor_type', 'user_id', 'note'];

    protected function casts(): array
    {
        return ['application_id' => 'integer', 'user_id' => 'integer'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
