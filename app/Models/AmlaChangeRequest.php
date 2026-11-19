<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A maker-checker request to change the AMLA settings or calendar (AMLA
 * design §4.13): nothing applies until a second `amla:settings` holder
 * approves it. Its type, payload, reason and maker never change. The only
 * change ever written is its one decision, from pending to approved,
 * rejected or withdrawn, and it's written only while the stored row is still
 * pending. Never deleted.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property string|null $pending_key
 * @property array<string, mixed> $payload
 * @property string $reason
 * @property int $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 */
class AmlaChangeRequest extends Model
{
    use AmlaKeptForever;

    public const SETTINGS = 'settings';

    public const CALENDAR_ADD = 'calendar_add';

    public const CALENDAR_REMOVE = 'calendar_remove';

    public const CALENDAR_CONFIRM_YEAR = 'calendar_confirm_year';

    /** @var list<string> */
    public const TYPES = [self::SETTINGS, self::CALENDAR_ADD, self::CALENDAR_REMOVE, self::CALENDAR_CONFIRM_YEAR];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::WITHDRAWN];

    /** The columns a decision fills in. */
    private const DECISION_COLUMNS = ['status', 'pending_key', 'decided_by', 'decided_at', 'decision_note', 'updated_at'];

    protected $fillable = ['type', 'status', 'pending_key', 'payload', 'reason', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Only the decision of a request that is still pending, and nothing else. */
    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('status') === self::PENDING
            && in_array($this->status, [self::APPROVED, self::REJECTED, self::WITHDRAWN], true)
            && array_diff(array_keys($this->getDirty()), self::DECISION_COLUMNS) === [];
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::PENDING];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
