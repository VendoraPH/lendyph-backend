<?php

namespace App\Models;

use App\Services\Accounting\YearEndCloser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One close of a calendar year: the closing entries it posted (one per
 * branch, plus one for journals with no branch) and the months it closed.
 *
 * WRITE THROUGH {@see YearEndCloser}, which posts the entries and closes the
 * months in the same transaction. A reopened row stays as `reopened`, and a
 * later close of the same year is a new row; `closed_year` (unique) is set
 * only while the close stands.
 *
 * Not Auditable: the closer writes ONE audit row on close and one on reopen,
 * with the figures (AuditLogModules files this class under `accounting`).
 *
 * @property int $id
 * @property int $year
 * @property string $status
 * @property int|null $closed_year
 * @property int $net_surplus_account_id
 * @property int $net_surplus
 * @property list<int> $periods_closed
 * @property int|null $closed_by
 * @property int|null $reopened_by
 * @property string|null $reopen_reason
 */
class AccountingYearClose extends Model
{
    public const STATUS_CLOSED = 'closed';

    public const STATUS_REOPENED = 'reopened';

    protected $table = 'accounting_year_closes';

    protected $fillable = [
        'year',
        'status',
        'closed_year',
        'net_surplus_account_id',
        'net_surplus',
        'periods_closed',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
        'reopen_reason',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'closed_year' => 'integer',
            'net_surplus_account_id' => 'integer',
            'net_surplus' => 'integer',
            'periods_closed' => 'array',
            'closed_by' => 'integer',
            'reopened_by' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccountingYearCloseEntry::class, 'accounting_year_close_id');
    }

    public function netSurplusAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'net_surplus_account_id');
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
        return $this->status === self::STATUS_CLOSED;
    }
}
