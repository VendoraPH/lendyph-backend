<?php

namespace App\Models;

use App\Services\Accounting\ReconciliationMatcher;
use App\Services\BranchAccess;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One money account proved against its statement for one period.
 *
 * `book_balance` and `difference` are deliberately absent as columns — see the
 * migration. They are computed by {@see ReconciliationMatcher}
 * from the ledger on every read, so that finding and posting a missing entry
 * CLOSES the difference instead of leaving a stale gap on the screen.
 *
 * @property int $id
 * @property int $accounting_account_id
 * @property string $period
 * @property string $start_date
 * @property string $end_date
 * @property int $statement_balance
 * @property string|null $notes
 * @property int|null $created_by
 */
class AccountingReconciliation extends Model
{
    /** Who declared a bank statement said what. */
    use Auditable, HasFactory;

    protected $table = 'accounting_reconciliations';

    protected $fillable = [
        'accounting_account_id',
        'period',
        'start_date',
        'end_date',
        'statement_balance',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // Calendar dates, emitted as such — never as ISO instants that
            // `formatDate()` could render a day early.
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            // INTEGER centavos, signed. Without the cast MySQL hands BIGINT
            // back as a string and the screen's `formatCentavos` gets text.
            'statement_balance' => 'integer',
            'accounting_account_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }

    /** The lines off the external statement. The ledger side is read live. */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingReconciliationLine::class, 'accounting_reconciliation_id');
    }

    /**
     * Newest period first, with `id` as the tiebreaker.
     *
     * The tiebreaker is not cosmetic: the Reconciliation screen DRAINS this
     * list, and a partial order lets one row be served on two pages while
     * another is served on none.
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('end_date')->orderByDesc('id');
    }

    /**
     * The reconciliations $user may see: those on a money account of one of
     * $user's branches (owner answer 9, 2026-11-11). One on an account with no
     * branch is for `branches:view_all` only, which sees them all.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $branchIds = app(BranchAccess::class)->for($user);

        if ($branchIds === null) {
            return $query;
        }

        return $query->whereIn(
            $this->qualifyColumn('accounting_account_id'),
            AccountingAccount::query()->select('id')->whereIn('branch_id', $branchIds),
        );
    }

    /**
     * Every `{reconciliation}` route parameter resolves only to one $user may
     * see ({@see self::scopeVisibleTo()}): another branch's answers 404 before
     * any validation runs, like a missing one. Without a signed-in user the
     * binding is unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = request()->user();

        return $user instanceof User ? $this->scopeVisibleTo($query, $user) : $query;
    }
}
