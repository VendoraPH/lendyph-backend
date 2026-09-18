<?php

namespace App\Models;

use App\Exceptions\PostedJournalIsImmutableException;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One double-entry transaction: a header plus at least two lines.
 *
 * WRITE THROUGH App\Services\Accounting\JournalPoster, ALWAYS. This model is
 * the shape of a journal and the guard on its immutability; the poster is the
 * only thing that knows how to produce a valid one — allocating the number,
 * recomputing the totals from the persisted lines, and checking every account
 * is still postable at the moment of posting rather than at the moment of
 * drafting.
 *
 * @property int $id
 * @property string|null $journal_no
 * @property string $date
 * @property string $source
 * @property string|null $reference
 * @property string $description
 * @property int|null $branch_id
 * @property string $status
 * @property int $total_debit
 * @property int $total_credit
 * @property int|null $reverses_journal_id
 * @property int|null $reversed_by_journal_id
 * @property string|null $postable_type
 * @property int|null $postable_id
 * @property int|null $created_by
 * @property int|null $posted_by
 */
class AccountingJournal extends Model
{
    /**
     * Who drafted, posted and reversed. The journal itself already records
     * `created_by`/`posted_by`, so this is for the third case the columns
     * cannot hold: the narrow status write a reversal makes on the ORIGINAL
     * entry, which changes a posted row without changing who posted it.
     */
    use Auditable, HasFactory;

    /** The `JE` in `JE-000154`. Allocated by JournalPoster on post. */
    public const CODE_PREFIX = 'JE';

    /**
     * Every `JournalSource` in `src/types/accounting.ts`, plus `share_capital`
     * — which the TypeScript union does not have and needs; see the migration.
     *
     * Mirrors the column's enum exactly. A value absent here is a value no
     * posting rule can ask for.
     */
    public const SOURCES = [
        'loan_release',
        'loan_collection',
        'penalty',
        'loan_fee',
        'gcash',
        'cash_transaction',
        'bank_transaction',
        'cash_in',
        'cash_out',
        'expense',
        'payable',
        'transfer',
        'manual',
        'adjustment',
        'reversal',
        'opening_balance',
        'credit_loss',
        'share_capital',
    ];

    public const STATUSES = ['draft', 'posted', 'reversed'];

    /**
     * Statuses that count as real history.
     *
     * A REVERSED entry is still a posted fact: it happened, it was believed,
     * and its reversal is a second entry that nets it to zero. Reporting must
     * include both or neither — including only the reversal would leave every
     * reversed account showing the mirror image of a transaction it no longer
     * has, and the trial balance would report an imbalance that does not exist.
     * This is the single easiest thing in the module to get wrong.
     */
    public const HISTORICAL_STATUSES = ['posted', 'reversed'];

    protected $table = 'accounting_journals';

    /**
     * `journal_no`, `status`, `total_debit`, `total_credit`, `posted_by` and
     * `posted_at` are deliberately absent.
     *
     * All six are decided by JournalPoster and written on the model directly,
     * so a request body carrying any of them is dropped at mass assignment
     * rather than trusted. A client that could set `status` could put an
     * unbalanced entry into the books with a number of its choosing; a client
     * that could set `total_debit` could make the header disagree with its own
     * lines, which is the one thing no report would ever catch.
     */
    protected $fillable = [
        'date',
        'source',
        'reference',
        'description',
        'branch_id',
        'reverses_journal_id',
        'postable_type',
        'postable_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'posted_at' => 'datetime',
            'branch_id' => 'integer',
            'total_debit' => 'integer',
            'total_credit' => 'integer',
            'reverses_journal_id' => 'integer',
            'reversed_by_journal_id' => 'integer',
            'postable_id' => 'integer',
            'created_by' => 'integer',
            'posted_by' => 'integer',
        ];
    }

    /**
     * Immutability, enforced on the model rather than trusted to callers.
     *
     * Everything that reaches the database goes through an Eloquent save
     * somewhere, so this is the narrowest place that catches every writer —
     * a controller, a console command, a future importer, a tinker session
     * during an incident. The API refuses these attempts with a 422 well
     * before they get here; this exists for the paths that do not go through
     * the API at all.
     */
    protected static function booted(): void
    {
        static::updating(function (self $journal): void {
            $wasStatus = (string) $journal->getOriginal('status');

            // A draft is a work in progress and may change freely.
            if ($wasStatus === 'draft') {
                return;
            }

            $changing = array_keys($journal->getDirty());

            // The ONE write a posted entry may take: a reversal marking it
            // reversed and linking to its mirror. Both columns move together
            // and only in that direction — `updated_at` rides along because
            // Eloquent stamps it on every save.
            $narrow = ['status', 'reversed_by_journal_id', 'updated_at'];
            $isNarrowWrite = $wasStatus === 'posted'
                && array_diff($changing, $narrow) === []
                && (! $journal->isDirty('status') || $journal->status === 'reversed');

            if ($isNarrowWrite) {
                return;
            }

            throw PostedJournalIsImmutableException::update(
                (int) $journal->id,
                $wasStatus,
                array_values(array_diff($changing, ['updated_at'])),
            );
        });

        static::deleting(function (self $journal): void {
            if ($journal->status !== 'draft') {
                throw PostedJournalIsImmutableException::delete((int) $journal->id, (string) $journal->status);
            }
        });
    }

    /** @return HasMany<AccountingJournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'accounting_journal_id')->orderBy('line_no');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** The entry this one undoes. Set on a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /** The entry that undid this one. Set when this one is reversed. */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    /** The lending event that caused this entry — a Loan, a Repayment. */
    public function postable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Entries the trial balance and the general ledger count. */
    public function scopeHistorical(Builder $query): Builder
    {
        return $query->whereIn('status', self::HISTORICAL_STATUSES);
    }

    /** Everything a journal screen needs, without going N+1 across a page. */
    public function scopeWithRegisterRelations(Builder $query): Builder
    {
        return $query->with([
            'lines.account:id,code,name',
            'branch:id,name',
            'creator:id,first_name,last_name',
            'poster:id,first_name,last_name',
        ]);
    }
}
