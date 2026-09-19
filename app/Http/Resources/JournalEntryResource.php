<?php

namespace App\Http\Resources;

use App\Models\AccountingExpense;
use App\Models\AccountingExpensePayment;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ExpenseRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * One journal entry, shaped as `JournalEntry` in `src/types/accounting.ts`.
 *
 * ## `created_by` / `posted_by` / `branch_name` are NAMES, not ids
 *
 * All three are typed `string | null` in the contract, and the register renders
 * them straight into a column. Emitting an integer id would satisfy neither the
 * type nor the screen. The ids are already on the row for anyone who needs to
 * follow them (`branch_id`), and a user id is not something a client can
 * resolve — there is no endpoint that would let it.
 *
 * ## `postable_type` is an ALIAS, not the stored class name
 *
 * Same idea, and for a stronger reason. The column stores a fully-qualified
 * class name — `App\Models\Loan` — because this application has no morph map:
 * `Relation::enforceMorphMap()` is called nowhere, so `getMorphClass()` returns
 * the class itself and that is what every posted row on every live database
 * already holds.
 *
 * Emitting it raw would publish the internal namespace on a read endpoint, and
 * a client that pattern-matched on `App\Models\Loan` would break the day a
 * class moved. Introducing a real morph map to fix that is worse: the map
 * rewrites what `getMorphClass()` RETURNS, so every historical row would keep
 * its FQCN while every new row got the short key, and `postable()` would
 * resolve nothing for either half until a backfill migration ran on all ten
 * deployments. Aliasing on the way out changes no data and no lookup — the
 * relation still resolves off the stored class name, exactly as it does today.
 *
 * So the alias lives HERE, in the read layer, deliberately. {@see
 * self::POSTABLE_ALIASES} is API vocabulary, not a storage decision.
 *
 * ## `postable_label` is `whenLoaded`, so a missing load is visible
 *
 * The label is the only one of the three that needs the related row. It is
 * guarded by `whenLoaded` for the same reason `account_code` is on
 * JournalLineResource: a forgotten load must show up as an absent field in a
 * test rather than as one query per row in production. All three callers
 * resolve it — {@see AccountingJournal::scopeWithRegisterRelations()},
 * `AccountingJournalController::respond()` and
 * `AccountingCashAccountController::transfer()`.
 *
 * That resolution is {@see AccountingJournal::attachPostables()},
 * and deliberately NOT `with('postable')`: a morphTo eager-load instantiates
 * every class name it finds in the column, which is a fatal error rather than a
 * null the day one of those classes is renamed. It costs one query per known
 * type present on the page, flat as the page grows, and nothing at all for the
 * rows that have no postable.
 *
 * Which column supplies the label is
 * {@see AccountingJournal::POSTABLE_LABEL_COLUMNS}, on the model
 * rather than here so the query selecting those columns and the resource
 * reading them cannot drift apart.
 *
 * ## `journal_no` is "" on a draft, not null
 *
 * The contract types it as a non-nullable `string` while documenting that it is
 * assigned on post; the frontend's own draft fixture uses `""`. Null would be
 * the more honest database value and it is what the column holds — but emitting
 * null against a `string` type puts `null` into a template that expects text.
 * Empty string is the shape the client already handles (`entry.journal_no ||
 * "this entry"` in `buildReversal`).
 */
class JournalEntryResource extends JsonResource
{
    /**
     * The stable public name for each class that reaches `postable_type`.
     *
     * These four are the only ones any production path writes:
     * {@see AutomaticPoster::loanRelease()} posts a
     * Loan (`loan_release`), `loanCollection()` a Repayment
     * (`loan_collection`), and {@see ExpenseRecorder}
     * an AccountingExpense (`expense`/`payable`) and an AccountingExpensePayment
     * (`payable`).
     *
     * AutomaticPoster also exposes `loanFee()`, `creditLossProvision()`,
     * `fundTransfer()` and `walletCharge()`, which each take an untyped
     * `Model $postable` and so can put ANY class in this column. No lending
     * flow calls them yet — but they are public, so an unmapped class is a
     * case that has to answer something rather than throw. See
     * {@see self::postableAlias()}.
     *
     * Adding an entry here is a contract change on both sides, which is why it
     * is a curated constant and not `class_basename()` for everything.
     */
    private const POSTABLE_ALIASES = [
        Loan::class => 'loan',
        Repayment::class => 'repayment',
        AccountingExpense::class => 'expense',
        AccountingExpensePayment::class => 'expense_payment',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'journal_no' => (string) ($this->journal_no ?? ''),
            'date' => $this->date?->toDateString(),
            'source' => $this->source,
            'reference' => $this->reference,
            'description' => $this->description,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'status' => $this->status,
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            // Centavos, server-computed from the persisted lines on post. Equal
            // on anything that is not a draft.
            'total_debit' => (int) $this->total_debit,
            'total_credit' => (int) $this->total_credit,
            'reverses_journal_id' => $this->reverses_journal_id,
            'reversed_by_journal_id' => $this->reversed_by_journal_id,
            // The document that caused this entry. All three are null together
            // and legitimately so: a fund transfer IS the document rather than
            // being raised by one (FundTransferRecorder), a reversal points at
            // the entry it undoes through `reverses_journal_id` and is refused
            // a postable on purpose (JournalPoster::reverse()), and a manual
            // entry never had one.
            'postable_type' => $this->postableAlias(),
            'postable_id' => $this->postable_id,
            'postable_label' => $this->whenLoaded('postable', fn () => $this->postableLabel()),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->full_name),
            'created_at' => $this->created_at,
            'posted_by' => $this->whenLoaded('poster', fn () => $this->poster?->full_name),
            'posted_at' => $this->posted_at,
        ];
    }

    /**
     * The public name for the stored class, read off the COLUMN rather than the
     * relation — so it is still answered when the row it points at has since
     * been deleted, and it costs no query when nothing was eager-loaded.
     *
     * An unmapped class falls back to the snake-cased basename. That is a best
     * effort and explicitly not part of the contract: it keeps a future
     * posting readable instead of anonymous, it still never emits the
     * namespace, and the fix when one appears is to curate it into
     * {@see self::POSTABLE_ALIASES}.
     */
    private function postableAlias(): ?string
    {
        $type = $this->postable_type;

        if (! is_string($type) || $type === '') {
            return null;
        }

        return self::POSTABLE_ALIASES[$type] ?? Str::snake(class_basename($type));
    }

    /**
     * The source document's own identifier — a loan number, a receipt number, a
     * payee.
     *
     * Null on four different footings, all of them ordinary: no postable at
     * all, a class with no naming column, a class nobody has mapped, and a
     * postable whose row no longer exists. `postable_type` and `postable_id`
     * still answer in every one of those cases.
     */
    private function postableLabel(): ?string
    {
        $postable = $this->postable;

        if ($postable === null) {
            return null;
        }

        foreach (AccountingJournal::POSTABLE_LABEL_COLUMNS[$postable::class] ?? [] as $column) {
            $value = $postable->getAttribute($column);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
