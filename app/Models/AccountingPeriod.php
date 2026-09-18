<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accounting month, and whether it still accepts entries.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $start_date
 * @property string $end_date
 * @property string $status
 * @property int|null $closed_by
 * @property string|null $closed_at
 * @property int|null $reopened_by
 * @property string|null $reopened_at
 */
class AccountingPeriod extends Model
{
    /**
     * Closing a month is a sign-off and reopening one undoes it. The dialog on
     * the Period Closing screen promises in as many words that reopening is
     * recorded; this trait is where that promise is kept.
     */
    use Auditable, HasFactory;

    protected $table = 'accounting_periods';

    public const STATUSES = ['open', 'closed'];

    /**
     * How far back provisioning will walk from today.
     *
     * A guard against a single bad date, not a policy. The months are derived
     * from the earliest journal in the books, and one entry mis-keyed as 0201
     * instead of 2026 would otherwise generate twenty-two thousand rows on the
     * next page load. Ten years is longer than any co-op this serves has been
     * on the system, and a period genuinely older than that can be closed from
     * the ledger it was posted in.
     */
    public const MAX_MONTHS_BACK = 120;

    protected $fillable = [
        'code',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            // Calendar dates, emitted as such. `formatDate()` on the Period
            // Closing screen renders these directly.
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            // Real instants. `formatDateTime()` renders `closed_at`.
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'closed_by' => 'integer',
            'reopened_by' => 'integer',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Chronological, oldest first.
     *
     * Oldest first rather than newest, and that is the screen's own logic: the
     * comment on `periodsListAll` says the periods past the first page are the
     * OLD ones, "exactly the periods someone opens this screen to close". Codes
     * are fixed-width so lexical order is chronological order, and `id` makes
     * the order total — which a drained list needs, or a row can be served on
     * two pages while another is served on none.
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('code')->orderBy('id');
    }

    /** The period a given calendar date falls in, if one has been set up. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->where('start_date', '<=', $date)->where('end_date', '>=', $date);
    }

    /**
     * The row for a calendar month, as it would be created.
     *
     * @return array{code: string, name: string, start_date: string, end_date: string}
     */
    public static function attributesForMonth(CarbonImmutable $month): array
    {
        return [
            'code' => $month->format('Y-m'),
            'name' => $month->format('F Y'),
            'start_date' => $month->startOfMonth()->toDateString(),
            'end_date' => $month->endOfMonth()->toDateString(),
        ];
    }
}
