<?php

namespace App\Models;

use App\Exceptions\CicRecordIsFrozenException;
use App\Traits\GuardsCicWrites;
use Database\Factories\CicPublicKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of CIC's public keys, the key history: nothing is ever deleted.
 *
 * - A key is uploaded `pending`; a second settings holder verifies its
 *   fingerprint with CIC, which makes it `active`; it is `retired` with a
 *   reason. A retired key never comes back, and a key goes pending → active →
 *   retired (or pending → retired) only.
 * - At most one key is active: activating one while another is active is
 *   refused, here and by the database's unique `active_key`. A verification
 *   retires the previous key first, then activates the new one, in one
 *   transaction.
 * - The key material (fingerprint, key id, algorithm, user ids, dates, armor)
 *   and who uploaded it never change.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $key_id
 * @property string $algorithm
 * @property list<string> $user_ids
 * @property Carbon|null $key_created_at
 * @property Carbon|null $expires_at
 * @property string $armored
 * @property string $status
 * @property int $uploaded_by
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $retired_by
 * @property Carbon|null $retired_at
 * @property string|null $retire_reason
 */
class CicPublicKey extends Model
{
    /** @use HasFactory<CicPublicKeyFactory> */
    use GuardsCicWrites, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETIRED = 'retired';

    /** What never changes once uploaded. */
    private const MATERIAL = [
        'fingerprint', 'key_id', 'algorithm', 'user_ids', 'key_created_at', 'expires_at', 'armored', 'uploaded_by',
    ];

    /** Each status and the statuses it may move to. */
    private const MOVES = [
        self::STATUS_PENDING => [self::STATUS_ACTIVE, self::STATUS_RETIRED],
        self::STATUS_ACTIVE => [self::STATUS_RETIRED],
        self::STATUS_RETIRED => [],
    ];

    /** The upload's own fields; the status and who verified or retired it are set by the key service. */
    protected $fillable = [
        'fingerprint', 'key_id', 'algorithm', 'user_ids', 'key_created_at', 'expires_at', 'armored',
    ];

    /** `active_key` is the database's own column (one active key); never written. */
    protected $hidden = ['active_key'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected function casts(): array
    {
        return [
            'user_ids' => 'array',
            'key_created_at' => 'datetime',
            'expires_at' => 'datetime',
            'uploaded_by' => 'integer',
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'retired_by' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** The active key, or null when none is. */
    public static function activeKey(): ?self
    {
        return static::query()->where('status', self::STATUS_ACTIVE)->first();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Whether the key has expired by now (a key with no expiry never does). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected function cicGuardInsert(): void
    {
        $this->attributes['status'] ??= self::STATUS_PENDING;
        unset($this->attributes['active_key']);

        if ($this->status === self::STATUS_ACTIVE) {
            $this->assertNoOtherActiveKey();
        }
    }

    protected function cicGuardUpdate(): void
    {
        unset($this->attributes['active_key']);
        $dirty = $this->cicDirtyColumns();

        if (array_intersect($dirty, self::MATERIAL) !== []) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a public key\'s fingerprint, material and uploader never change; upload a new key instead.');
        }

        if (in_array('status', $dirty, true)) {
            $from = (string) $this->getOriginal('status');

            if (! in_array($this->status, self::MOVES[$from] ?? [], true)) {
                throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), "a {$from} key can't become {$this->status}.");
            }

            if ($this->status === self::STATUS_ACTIVE) {
                $this->assertNoOtherActiveKey();
            }
        }
    }

    protected function cicGuardDelete(): void
    {
        throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'public keys are kept as the key history and are never deleted; retire the key instead.');
    }

    private function assertNoOtherActiveKey(): void
    {
        $other = static::query()->where('status', self::STATUS_ACTIVE)
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($other) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'another key is already active; retire it first, in the same transaction.');
        }
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function retirer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }
}
