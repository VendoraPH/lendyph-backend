<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An official order in the freeze-order register (AMLA design §3.5, decision
 * 5; task §11): who issued it, when it came in, the member, the required
 * action, the responsible officer and the return deadline, and the savings
 * holds placed for it. The order's details are encrypted. The only change
 * ever written is its release, once, by `amla:sanctions`, which lifts its
 * holds. Never deleted.
 *
 * @property int $id
 * @property string $reference
 * @property string $authority amlc|court|other
 * @property Carbon $received_on
 * @property int $borrower_id
 * @property string $required_action
 * @property string|null $details
 * @property int|null $responsible_officer_id
 * @property Carbon|null $return_due_on
 * @property string $status active|released
 * @property int $placed_by
 * @property int|null $released_by
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 * @property string|null $release_reference
 */
class AmlaOrder extends Model
{
    use AmlaKeptForever;

    public const ACTIVE = 'active';

    public const RELEASED = 'released';

    /** @var array<string, string> */
    public const AUTHORITIES = ['amlc' => 'AMLC', 'court' => 'Court', 'other' => 'Other authority'];

    /** @var array<string, string> */
    public const STATUSES = [self::ACTIVE => 'In force', self::RELEASED => 'Released'];

    private const RELEASE_COLUMNS = ['status', 'released_by', 'released_at', 'release_reason', 'release_reference', 'updated_at'];

    protected $fillable = [
        'reference', 'authority', 'received_on', 'borrower_id', 'required_action', 'details', 'responsible_officer_id',
        'return_due_on', 'status', 'placed_by', 'released_by', 'released_at', 'release_reason', 'release_reference',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'borrower_id' => 'integer',
            'required_action' => 'encrypted',
            'details' => 'encrypted',
            'responsible_officer_id' => 'integer',
            'return_due_on' => 'date',
            'placed_by' => 'integer',
            'released_by' => 'integer',
            'released_at' => 'datetime',
            'release_reason' => 'encrypted',
        ];
    }

    /** Only its one release. */
    protected function amlaAllowsUpdate(): bool
    {
        return $this->getOriginal('status') === self::ACTIVE
            && $this->status === self::RELEASED
            && $this->released_by !== null
            && array_diff(array_keys($this->getDirty()), self::RELEASE_COLUMNS) === [];
    }

    /** @return array<string, mixed> */
    protected function amlaUpdateConditions(): array
    {
        return ['status' => self::ACTIVE];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(AmlaOrderHold::class, 'order_id');
    }

    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_officer_id');
    }
}
