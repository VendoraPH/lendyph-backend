<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an AMLA alert's workflow, in AMLA's own history (task §12):
 * who assigned, reviewed, escalated or closed it, from and to which status,
 * and any note or closing reason (encrypted). Never the main audit trail.
 * Kept as written.
 *
 * @property int $id
 * @property int $alert_id
 * @property string $action
 * @property string $from_status
 * @property string $to_status
 * @property int $user_id
 * @property int|null $assignee_id
 * @property int|null $case_id
 * @property string|null $note
 */
class AmlaAlertAction extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const WORDS = [
        'assigned' => 'Assigned',
        'review_started' => 'Review started',
        'escalated' => 'Escalated to a case',
        'closed' => 'Closed with a documented reason',
    ];

    protected $fillable = ['alert_id', 'action', 'from_status', 'to_status', 'user_id', 'assignee_id', 'case_id', 'note'];

    protected function casts(): array
    {
        return ['alert_id' => 'integer', 'user_id' => 'integer', 'assignee_id' => 'integer', 'case_id' => 'integer', 'note' => 'encrypted', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
