<?php

namespace App\Models;

use App\Services\Accounting\AccountRules;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the chart of accounts.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_balance
 * @property bool $is_contra
 * @property int|null $parent_id
 * @property bool $is_group
 * @property bool $is_active
 * @property string|null $cash_kind
 * @property string|null $description
 * @property int|null $created_by
 * @property int|null $journal_lines_count
 */
class AccountingAccount extends Model
{
    /**
     * Who added, renamed, reparented or deactivated an account, and when.
     *
     * The same trait `CollateralType`, `Fee` and `LoanProduct` carry, and for a
     * stronger reason: every figure on every statement is keyed to these rows,
     * so a silent edit here re-reports history that was already published.
     * Seeding suppresses the per-row entries in favour of one summary row —
     * see ChartOfAccountsSeeder::seed().
     */
    use Auditable, HasFactory;

    protected $table = 'accounting_accounts';

    /**
     * `normal_balance` is deliberately absent.
     *
     * It is derived from `type` + `is_contra` in the saving hook below, so
     * leaving it unfillable means a request body carrying it is dropped at mass
     * assignment rather than trusted. The hook is the guarantee; this is the
     * first of the two locks.
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'is_contra',
        'parent_id',
        'is_group',
        'is_active',
        'cash_kind',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_contra' => 'boolean',
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'parent_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Derive `normal_balance` on every write, from whatever `type` and
     * `is_contra` ended up being.
     *
     * Not a default and not a convenience: an account whose stored normal
     * balance disagreed with its type would invert its sign on the trial
     * balance and the balance sheet at once, and nothing about the two figures
     * would say which was wrong. Deriving on save means the column cannot hold
     * a value that contradicts the row it sits in — whether it was set by a
     * seeder, a controller, a console command or a future import.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->normal_balance = AccountRules::normalBalanceFor(
                (string) $account->type,
                (bool) $account->is_contra,
            );
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The posting roles resolving to this account. Blocks deletion when any. */
    public function mappings(): HasMany
    {
        return $this->hasMany(AccountingAccountMapping::class, 'accounting_account_id');
    }

    /**
     * Every journal line that references this account.
     *
     * The relation behind `has_transactions`, and behind the guard that stops
     * an account's `type` or `is_contra` changing once it has history.
     *
     * DRAFT lines count, deliberately, and it matters for both readers. The
     * foreign key is `restrictOnDelete`, so a line in an unposted draft blocks
     * the delete exactly as a posted one does — a `has_transactions` that
     * ignored drafts would tell the UI deletion is safe and then 500 on it. And
     * a draft is a posting waiting to happen: re-signing an account the
     * afternoon before its pending entries post is the same mistake as
     * re-signing it afterwards, found a day later.
     *
     * @return HasMany<AccountingJournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'accounting_account_id');
    }

    /**
     * Whether any journal line references this account.
     *
     * An account with history can only be DEACTIVATED, never deleted: removing
     * it would destroy one half of entries that still exist, so the books would
     * stop balancing with the missing side unrecoverable. This is the read side
     * of that rule — the database enforces it with a restricting foreign key,
     * and the controller turns the resulting error into something a screen can
     * render.
     *
     * Prefers a `withCount('journalLines')` the caller already loaded, so a
     * page of sixty accounts costs one extra query rather than sixty.
     */
    public function hasTransactions(): bool
    {
        if ($this->journal_lines_count !== null) {
            return (int) $this->journal_lines_count > 0;
        }

        return $this->journalLines()->exists();
    }

    /** Whether a journal line may reference this account. */
    public function isPostable(): bool
    {
        return AccountRules::isPostable($this);
    }

    /**
     * Statement order.
     *
     * Codes are fixed-width numeric strings, so ordering by `code` alone yields
     * 1010 → 1020 → 1100 → 2010 — the order every statement and the account
     * tree present. Done in SQL rather than after the fact so a paginated page
     * is a page of the same list the whole chart would produce.
     */
    public function scopeInCodeOrder(Builder $query): Builder
    {
        return $query->orderBy('code');
    }

    /** Accounts a journal line may actually reference. */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_group', false);
    }
}
