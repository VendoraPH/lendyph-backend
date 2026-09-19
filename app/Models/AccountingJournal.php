<?php

namespace App\Models;

use App\Exceptions\PostedJournalIsImmutableException;
use App\Http\Resources\JournalEntryResource;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     *
     * ## Ten of these are written. Eight are not — and one of those is a policy
     *
     * The list is the column's enum, not an inventory of live behaviour, and
     * reading it as one has already misled people. What actually writes each
     * value, as of this commit:
     *
     * | source            | written by                                              |
     * |-------------------|---------------------------------------------------------|
     * | `loan_release`    | PostingRules::loanRelease(), via AutomaticPoster         |
     * | `loan_collection` | PostingRules::loanCollection(), via AutomaticPoster      |
     * | `loan_fee`        | PostingRules::loanFee()                                  |
     * | `gcash`           | PostingRules::walletCharge() — a wallet's own fee        |
     * | `expense`         | ExpenseRecorder, cash expense                            |
     * | `payable`         | ExpenseRecorder, accrual AND its later settlement        |
     * | `transfer`        | FundTransferRecorder, PostingRules::fundTransfer()       |
     * | `credit_loss`     | PostingRules::creditLossProvision()                      |
     * | `manual`          | StoreJournalRequest — forced, a client cannot choose     |
     * | `reversal`        | JournalPoster::reverse()                                 |
     *
     * ### `penalty` is reserved and always will be — it is a decision
     *
     * Not an unfinished feature. This organisation keeps penalties on a CASH
     * basis: a penalty is credited to `penalty_income` as a LINE inside the
     * `loan_collection` journal, at the moment it is collected, and penalty
     * accrual writes no journal at all. {@see
     * \App\Services\Accounting\PostingRules::loanCollection()} carries the full
     * rationale — read it before adding anything that posts with this source,
     * because an accrual event here needs the reversing entry a cash-basis book
     * does not have, and half of that pair is worse than neither.
     *
     * The same decision is why {@see AccountingAccountMapping} maps
     * `penalty_receivable` and nothing ever posts to it.
     *
     * Note `AccountingJournalController::index()` accepts `?source=penalty` as
     * a valid filter, so the API advertises a source that cannot return rows.
     * That is deliberate — see the filter's note there.
     *
     * ### `opening_balance` is being implemented now
     *
     * In flight at the time of writing, not reserved. Expect it to join the
     * table above; do not annotate it as unused on the strength of a grep.
     *
     * ### The remaining six are unclaimed enum surface
     *
     * `cash_transaction`, `bank_transaction`, `cash_in`, `cash_out`,
     * `adjustment` and `share_capital` are written by nothing and no design
     * currently calls for them. `cash_in`/`cash_out` in particular overlap
     * `transfer`, which is what cash movement actually posts as today.
     *
     * They are kept rather than removed because this is a live enum column on
     * ten deployments: narrowing it means a migration, and a migration that
     * cannot fail safely if any row anywhere holds one of these. Annotated is
     * cheaper and just as honest.
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

    /**
     * Which column names each kind of source document to a human. The first one
     * holding a value wins.
     *
     * Read by {@see JournalEntryResource} to emit
     * `postable_label`, and by {@see self::attachPostables()} to decide both
     * WHICH classes are resolved and which columns are selected — all from this
     * one list, so the query and the read cannot drift apart.
     *
     * These four classes are the only ones a production path puts in
     * `postable_type`. Loan comes from AutomaticPoster::loanRelease(),
     * Repayment from loanCollection(), and the two expense rows from
     * ExpenseRecorder. AutomaticPoster's other methods take an untyped
     * `Model $postable` and could add to this list; nothing calls them yet.
     *
     * AccountingExpensePayment is deliberately empty: nothing on the payment
     * row names it. The payee lives on its parent expense, and reaching through
     * for it would mean a nested eager-load across a morphTo for a string the
     * journal's own `description` already carries ("Payable settled — {payee}").
     *
     * @var array<class-string, list<string>>
     */
    public const POSTABLE_LABEL_COLUMNS = [
        Loan::class => ['loan_account_number', 'application_number'],
        Repayment::class => ['receipt_number'],
        AccountingExpense::class => ['payee'],
        AccountingExpensePayment::class => [],
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

    /**
     * Resolves `postable` for a set of entries, one query per KNOWN type.
     *
     * ## Why this is not `with('postable')`
     *
     * Because `with('postable')` takes the journal register down completely
     * the first time a model class is renamed.
     *
     * This application has no morph map, so the column holds a fully-qualified
     * class name. Eloquent's morphTo eager-load resolves each distinct type by
     * doing `new $class` ({@see MorphTo::createModelByType()}), which is a
     * fatal `Error` — not an exception a handler softens — when the class is
     * gone. One such row anywhere in a page answers 500 for the whole page,
     * for every user, on a screen that renders fine today because nothing
     * currently dereferences the relation. Ten deployments hold these strings.
     *
     * Eloquent gives no way to tell a morphTo "load only these types": the
     * dictionary is built in `addEagerConstraints()` before any `with()`
     * closure runs, and `getDictionary()` is a getter. So the load is done
     * here instead, from {@see self::POSTABLE_LABEL_COLUMNS} — a closed list
     * of four classes that are referenced as `::class` and therefore cannot
     * silently stop existing.
     *
     * ## The cost
     *
     * One keyed query per known type PRESENT, so three or four on a mixed
     * register and zero on a page of transfers — and flat as the page grows
     * from 15 rows to 100, which is the property
     * `test_the_register_resolves_postables_without_going_n_plus_1()` pins.
     *
     * Each query selects the key plus the label column and nothing else:
     * `loans` is 54 columns wide, and the register wants one string from it.
     * A type with no label column is skipped entirely rather than queried for
     * nothing.
     *
     * ## Every entry ends up with the relation SET
     *
     * Including the ones with no postable, an unmapped class, or a row that
     * has since been deleted — all three get null. `postable_label` is
     * `whenLoaded`-guarded in JournalEntryResource, so setting it on every
     * entry is what keeps the field present-and-null instead of absent, and
     * absent is a different shape to a typed client.
     *
     * @param  EloquentCollection<int, self>  $journals
     */
    public static function attachPostables(EloquentCollection $journals): void
    {
        if ($journals->isEmpty()) {
            return;
        }

        $journals->each(static fn (self $journal) => $journal->setRelation('postable', null));

        foreach (self::POSTABLE_LABEL_COLUMNS as $class => $columns) {
            if ($columns === []) {
                continue;
            }

            $entries = $journals->where('postable_type', $class)->whereNotNull('postable_id');

            if ($entries->isEmpty()) {
                continue;
            }

            /** @var EloquentCollection<int, Model> $documents */
            $documents = $class::query()
                // `id` always: it is what the entries are matched back on.
                ->select(array_values(array_unique(['id', ...$columns])))
                ->whereIn('id', $entries->pluck('postable_id')->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($entries as $entry) {
                $entry->setRelation('postable', $documents->get($entry->postable_id));
            }
        }
    }

    /** {@see self::attachPostables()}, for one entry already in hand. */
    public function loadPostable(): static
    {
        self::attachPostables(new EloquentCollection([$this]));

        return $this;
    }

    /** Everything a journal screen needs, without going N+1 across a page. */
    public function scopeWithRegisterRelations(Builder $query): Builder
    {
        return $query
            ->with([
                'lines.account:id,code,name',
                'branch:id,name',
                'creator:id,first_name,last_name',
                'poster:id,first_name,last_name',
            ])
            // Runs on the page `paginate()` produces — `paginate()` goes
            // through `get()`, which applies these. Here rather than left to
            // callers so the register cannot forget it and quietly drop
            // `postable_label` from every row.
            ->afterQuery(static function (EloquentCollection $journals): EloquentCollection {
                self::attachPostables($journals);

                return $journals;
            });
    }
}
