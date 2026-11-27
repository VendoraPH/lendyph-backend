<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member's standing instruction to credit an amount to one of their savings
 * accounts on the 15th, the 30th (a month's last day) or both (gap round S3).
 * `schedule` holds the share capital pledges' keys: `15`, `30` or `15/30`.
 * Who authorised it (`authorised_by`, in words) and when (`authorised_on`)
 * are the member's authorisation; `created_by` is the staff user who entered
 * it. Audited on create, update and delete (Auditable).
 *
 * @property int $id
 * @property int $account_id
 * @property string $amount
 * @property string $schedule
 * @property bool $active
 */
class SavingsAutoCreditInstruction extends Model
{
    use Auditable;

    public const SCHEDULE_15 = '15';

    public const SCHEDULE_30 = '30';

    public const SCHEDULE_BOTH = '15/30';

    /** Each schedule in words, as the pledge screens read them. */
    public const SCHEDULE_WORDS = [
        self::SCHEDULE_15 => '15th',
        self::SCHEDULE_30 => '30th (month end)',
        self::SCHEDULE_BOTH => '15th and 30th',
    ];

    public const SCHEDULES = [self::SCHEDULE_15, self::SCHEDULE_30, self::SCHEDULE_BOTH];

    protected $fillable = [
        'account_id',
        'amount',
        'schedule',
        'active',
        'authorised_on',
        'authorised_by',
        'remarks',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'active' => 'boolean',
            'authorised_on' => 'date',
        ];
    }

    /**
     * The cycle slots a schedule covers: `15` and/or `30`.
     *
     * @return list<string>
     */
    public static function slotsOf(string $schedule): array
    {
        return $schedule === self::SCHEDULE_BOTH ? [self::SCHEDULE_15, self::SCHEDULE_30] : [$schedule];
    }

    /**
     * The schedules that cover a slot (`15` or `30`).
     *
     * @return list<string>
     */
    public static function schedulesCovering(string $slot): array
    {
        return [$slot, self::SCHEDULE_BOTH];
    }

    /** Words for an updated row's audit entry. */
    public function describeAuditEvent(string $action): ?string
    {
        $number = SavingsAccount::query()->whereKey($this->account_id)->value('account_number');

        return $action === 'updated' ? "Auto-credit instruction on savings account {$number} changed." : null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(SavingsAutoCreditPosting::class, 'instruction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
