<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The opening balances screen's entry: one per deployment, with no branch.
 *
 * A `draft` until it is finalised, when OpeningBalancePoster posts it once as
 * the `opening_balance` journal and links it here. Every save replaces the
 * lines wholesale and bumps `edit_version`, which a save or a finalise
 * carrying an older version is refused on (409).
 *
 * `status`, `edit_version` and the finalise columns are decided by the
 * server and written on the model directly, never mass assigned.
 */
class AccountingOpeningBalance extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALISED = 'finalised';

    protected $fillable = [
        'as_of',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'singleton' => 'integer',
            'as_of' => 'date',
            'accounting_journal_id' => 'integer',
            'edit_version' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
            'finalised_by' => 'integer',
            'finalised_at' => 'datetime',
        ];
    }

    /** @return HasMany<AccountingOpeningBalanceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingOpeningBalanceLine::class);
    }

    /** @return BelongsTo<AccountingJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function finaliser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function isFinalised(): bool
    {
        return $this->status === self::STATUS_FINALISED;
    }
}
