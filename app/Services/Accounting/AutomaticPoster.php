<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Models\Repayment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The automatic posting engine. Lending services call this; nothing else does.
 *
 * {@see PostingRules} decides WHAT the entry looks like. This decides when one
 * is due, converts pesos to centavos, and hands the result to
 * {@see JournalPoster::postImmediately()} — the only writer of journals.
 *
 * ## Called explicitly, inside the caller's transaction. NEVER an observer
 *
 * A model observer on `Loan` or `Repayment` would be shorter and would be
 * wrong, in three ways that are all live in this codebase:
 *
 * 1. `RepaymentService::previewAllocation()` runs a REAL
 *    `processRepayment()` inside a transaction it then rolls back, so the
 *    preview matches the real allocation exactly. A `Repayment::created`
 *    observer fires during that preview. The journal would be rolled back with
 *    it — but only because the rollback happens to reach it; the engine would
 *    be posting entries for transactions that never occurred, and the moment
 *    anything about that rollback changed, so would the books.
 * 2. `LoanService::release()` has a documented statement order: the collateral
 *    lock must be the first statement in the transaction and
 *    `CollateralPledgeGuard::assertNoDoublePledge()` the last. An observer
 *    fires on `Loan::updated`, which is in the middle, and would post from a
 *    loan whose `net_proceeds` `applyInsuranceOnRelease()` has not yet
 *    adjusted — the exact figure the release rule depends on.
 * 3. `CsvImportProcessor` bulk-creates historical loans with `released` and
 *    `ongoing` statuses, on purpose, so the coop's existing portfolio appears
 *    in every report. Those releases happened years ago and under someone
 *    else's books. An observer would post a journal for every one of them, at
 *    today's date, the first time an organisation imported its history.
 *
 * Being explicit is what makes all three a non-issue: the call sits where the
 * figures are final, and the importer simply does not make it.
 *
 * ## Fails closed, except for organisations with no books
 *
 * Every posting either completes or throws, and a throw rolls back the lending
 * transaction that wrapped it — the release does not happen, the payment is not
 * recorded. That is the trade this module is built on: a loud, recoverable
 * refusal beats a silent, permanent gap in the books.
 *
 * The one thing that is NOT an error is an organisation that has never opened
 * the accounting module. See {@see AccountMap::chartExists()}.
 *
 * ## Idempotent, by the database
 *
 * Every posting carries a `postable` and a `source`, which
 * `accounting_journals_postable_source_unique` keys on. A retry — a redelivered
 * job, a double-clicked button, a timeout the caller resolved as a failure —
 * gets the journal that already exists rather than a second balanced one that
 * nothing would ever flag. {@see JournalPoster::postImmediately()} owns that
 * resolution; this class's job is to always supply the pair.
 */
final class AutomaticPoster
{
    public function __construct(private readonly JournalPoster $poster = new JournalPoster) {}

    /**
     * The journal for a release, or null when this organisation keeps no books.
     *
     * ## Call it AFTER `applyInsuranceOnRelease()`
     *
     * That method withholds the insurance premium by REWRITING
     * `net_proceeds` and `total_deductions` on the loan. Post before it and the
     * entry credits cash with money that never left the drawer, and omits the
     * premium from income — and it balances, so nothing reports it.
     *
     * ## The settlement account
     *
     * `loans` records no disbursement method — there is no column for it — so a
     * release settles through `cash`, which is how the great majority of these
     * organisations disburse. The parameter exists so that the day a
     * `released_via` column lands, the only change is at the call site. It is a
     * default, not an assumption the rule makes.
     */
    public function loanRelease(Loan $loan, int $userId, string $method = 'cash'): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        $map = AccountMap::resolve();

        $posting = PostingRules::loanRelease(
            gross: $this->centavos($loan->principal_amount, 'principal amount', $loan),
            net: $this->centavos($loan->net_proceeds, 'net proceeds', $loan),
            deductions: $this->centavos($loan->total_deductions, 'total deductions', $loan),
            method: $method,
            map: $map,
        );

        return $this->write($posting, $loan, [
            'date' => $this->dateOf($loan->released_at),
            'reference' => $loan->loan_account_number ?? $loan->application_number,
            'description' => $this->describe('Loan release', $loan->loan_account_number ?? $loan->application_number),
            'branch_id' => $loan->branch_id,
        ], $userId);
    }

    /**
     * The journal for a collection.
     *
     * ## The postable is the REPAYMENT, not the loan
     *
     * `accounting_journals_postable_source_unique` is on
     * `(postable_type, postable_id, source)`. Key a collection on the Loan and
     * that index permits exactly ONE collection journal per loan for the life
     * of the loan: the second payment finds the first journal and returns it,
     * and every payment after the first is silently absent from the books while
     * the portfolio keeps moving. The document a collection records is the
     * receipt, and the receipt is the Repayment row.
     *
     * (The migration's docblock gives "a loan is released, collected against,
     * and charged a fee" as three journals against one Loan. That is the right
     * argument for keying on `source` and the wrong document for this event.)
     */
    public function loanCollection(Repayment $repayment, int $userId): ?AccountingJournal
    {
        $posting = $this->buildCollection($repayment);

        if ($posting === null) {
            return null;
        }

        return $this->write($posting, $repayment, [
            'date' => $this->dateOf($repayment->payment_date),
            'reference' => $repayment->receipt_number,
            'description' => $this->describe('Loan collection', $repayment->receipt_number),
            'branch_id' => $repayment->loan?->branch_id,
        ], $userId);
    }

    /**
     * Everything {@see self::loanCollection()} does EXCEPT write the journal.
     *
     * For `RepaymentService::previewAllocation()`, which runs a real
     * `processRepayment()` inside a transaction it rolls back. Posting there
     * worked — the rollback took the journal with it — but it was the wrong
     * thing to do twice over:
     *
     * - `JournalPoster::allocateJournalNo()` takes `lockForUpdate()` on the
     *   highest-numbered journal row, so every preview SERIALISED against every
     *   real release, collection and manual post on the deployment. A preview is
     *   a read-only affordance, called far more often than a payment.
     * - The preview is reachable with `payments:view`, which the `viewer` role
     *   holds. A read-only principal was causing inserts into the ledger and
     *   consuming journal auto-increment ids, which a rollback does not give
     *   back.
     *
     * The mapping is still resolved and every rule still runs, so a preview
     * fails exactly where a real payment would — the point is to do that
     * WITHOUT touching the books.
     */
    public function assertCollectionIsPostable(Repayment $repayment): void
    {
        $this->buildCollection($repayment);
    }

    /**
     * The collection posting, or null when this organisation keeps no books.
     *
     * @return array{source: string, description: string, lines: list<array{account_id: int, debit: int, credit: int}>}|null
     */
    private function buildCollection(Repayment $repayment): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return PostingRules::loanCollection(
            received: $this->centavos($repayment->amount_paid, 'amount paid', $repayment),
            principal: $this->centavos($repayment->principal_applied, 'principal applied', $repayment),
            interest: $this->centavos($repayment->interest_applied, 'interest applied', $repayment),
            penalty: $this->centavos($repayment->penalty_applied, 'penalty applied', $repayment),
            fees: 0,
            overpayment: $this->centavos($repayment->overpayment, 'overpayment', $repayment),
            method: SettlementMethod::fromLendingMethod((string) $repayment->method),
            map: AccountMap::resolve(),
        );
    }

    /**
     * Undoes a posting, leaving both halves on the record.
     *
     * Called when the lending event itself is undone — a voided repayment. A
     * reversal rather than a delete because a posted entry is never edited or
     * removed: what was believed, and when, stays on the books beside the entry
     * that corrects it. That is what makes the trail auditable rather than
     * merely current.
     *
     * Silent when there is nothing to reverse. A payment recorded before this
     * organisation adopted accounting has no journal, and refusing to void it
     * would strand the operator over books that never mentioned it.
     * Already-reversed entries are skipped for the same reason: a second void
     * attempt must not fail on the state the first one left.
     */
    public function reverseFor(
        Model $postable,
        string $source,
        int $userId,
        ?string $reason = null,
    ): ?AccountingJournal {
        $journal = AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $source)
            ->first();

        if ($journal === null || $journal->status !== 'posted') {
            return null;
        }

        return $this->poster->reverse($journal, $this->dateOf(null), $reason, $userId);
    }

    /**
     * A fee collected on its own, outside a release and outside a repayment.
     *
     * No lending flow raises this yet — fees withheld at release are part of
     * {@see self::loanRelease()}. It exists because the rule does, and because
     * the moment a standalone fee receipt lands it must post through the same
     * engine rather than a second one written next to it.
     *
     * ## `$postable` MUST be the row for THIS event, not its parent
     *
     * The idempotency index is on (postable_type, postable_id, source), so the
     * postable decides how many of these a document may ever raise. Pass a Loan
     * and that loan gets ONE fee posting for its whole life: the second fee
     * silently returns the first journal, the money moves, and the books do not
     * mention it. Pass the fee receipt.
     *
     * The same applies to every method below. {@see self::loanCollection()}
     * documents the live case — collections key on the Repayment, never the
     * Loan, for exactly this reason.
     */
    public function loanFee(Model $postable, string|int|float $pesos, string $method, int $userId, array $context = []): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->write(
            PostingRules::loanFee($this->centavos($pesos, 'fee amount', $postable), $method, AccountMap::resolve()),
            $postable,
            $context,
            $userId,
        );
    }

    /**
     * Recognises expected losses against the contra-asset allowance.
     *
     * `$postable` must be the provision row, not the loan it covers — a loan is
     * provisioned against repeatedly as its risk changes, and keying on the
     * loan would let it happen exactly once. See {@see self::loanFee()}.
     */
    public function creditLossProvision(Model $postable, string|int|float $pesos, int $userId, array $context = []): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->write(
            PostingRules::creditLossProvision($this->centavos($pesos, 'provision amount', $postable), AccountMap::resolve()),
            $postable,
            $context,
            $userId,
        );
    }

    /**
     * Company money moving between its own accounts. Never income.
     *
     * `$postable` must be the transfer row. See {@see self::loanFee()}.
     */
    public function fundTransfer(Model $postable, string|int|float $pesos, string $from, string $to, int $userId, array $context = []): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->write(
            PostingRules::fundTransfer($this->centavos($pesos, 'transfer amount', $postable), $from, $to, AccountMap::resolve()),
            $postable,
            $context,
            $userId,
        );
    }

    /**
     * A wallet's own fee, paid out of that wallet.
     *
     * `$postable` must be the charge row. See {@see self::loanFee()}.
     *
     * ## Why the account id is re-checked here
     *
     * Every other rule names a ROLE and gets an id from the mapping, which an
     * administrator curated. This one takes an account id straight from its
     * caller, because which expense a wallet charge belongs to is an operator's
     * choice rather than a fixed role — and that makes it the one posting in the
     * module where an arbitrary id could reach a journal line.
     *
     * JournalPoster refuses inactive accounts and group headings, so the gap it
     * does not close is TYPE: a charge debited to equity, or to the credit-loss
     * allowance, posts and balances and is quietly wrong on the balance sheet
     * instead of the income statement. Checked here rather than in
     * {@see PostingRules}, which is pure and must not query.
     */
    public function walletCharge(Model $postable, string|int|float $pesos, string $method, int $expenseAccountId, int $userId, array $context = []): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->write(
            PostingRules::walletCharge(
                $this->centavos($pesos, 'charge amount', $postable),
                $method,
                $this->assertIsPostableExpenseAccount($expenseAccountId),
                AccountMap::resolve(),
            ),
            $postable,
            $context,
            $userId,
        );
    }

    /**
     * An account id supplied by a caller, confirmed to be an expense account
     * that may actually take a posting.
     *
     * Public because the expenses stream's `expense_cash` and `expense_accrual`
     * endpoints take the same caller-chosen id and need the same gate — the
     * rules for those live in {@see PostingRules} already, and this is the
     * check that has to sit in front of them.
     */
    public function assertIsPostableExpenseAccount(int $accountId): int
    {
        $account = AccountingAccount::query()->find($accountId);

        if ($account === null) {
            throw CannotPostToTheBooksException::because(
                'That expense account no longer exists, so this charge cannot be posted to the books. '
                .'Nothing has been saved.'
            );
        }

        if ($account->type !== 'expense' || $account->is_contra) {
            throw CannotPostToTheBooksException::because(
                "{$account->code} {$account->name} is not an expense account, so a charge cannot be posted "
                .'against it. The entry would still balance and would simply report the cost on the wrong '
                .'statement, which is why this is refused rather than corrected.'
            );
        }

        if (! AccountRules::isPostable($account)) {
            throw CannotPostToTheBooksException::because(
                $account->is_group
                    ? "{$account->code} {$account->name} is a heading — choose one of its sub-accounts instead."
                    : "{$account->code} {$account->name} is inactive and cannot take new entries."
            );
        }

        return $accountId;
    }

    /** Whether this organisation keeps books. See {@see AccountMap::chartExists()}. */
    public function enabled(): bool
    {
        return AccountMap::chartExists();
    }

    /**
     * Hands a built posting to the only writer of journals.
     *
     * @param  array{source: string, description: string, lines: list<array{account_id: int, debit: int, credit: int}>}  $posting
     * @param  array<string, mixed>  $context  date / reference / description / branch_id overrides
     */
    private function write(array $posting, Model $postable, array $context, ?int $userId): AccountingJournal
    {
        return $this->poster->postImmediately(
            [
                'date' => $this->dateOf($context['date'] ?? null),
                'source' => $posting['source'],
                'reference' => $context['reference'] ?? null,
                'description' => $context['description'] ?? $posting['description'],
                'branch_id' => $context['branch_id'] ?? null,
                // The pair the idempotency index keys on. Both, always — a
                // posting without them is a posting that can be written twice.
                'postable_type' => $postable->getMorphClass(),
                'postable_id' => $postable->getKey(),
            ],
            $posting['lines'],
            $userId,
        );
    }

    /**
     * THE PESO → CENTAVO BOUNDARY. The only place it is crossed.
     *
     * The lending tables are `decimal:2` pesos; accounting is integer centavos.
     * Getting this wrong by a factor of 100 produces a journal that balances
     * perfectly and misstates every figure on every statement, which is why it
     * happens exactly here and why the failure is loud.
     *
     * ## Why not `(int) round($pesos * 100)`
     *
     * Because `$pesos` is a `decimal:2` cast, which Eloquent hands back as a
     * STRING ("1234.56"). Multiplying a float 1234.56 by 100 gives
     * 123455.99999999999, and `(int)` truncates that to 123455 — a centavo
     * missing, on the one field where "close" has no meaning.
     * {@see Money::toCentavos()} parses the string digit by digit instead, so
     * the arithmetic is exact and a large amount cannot lose its last centavo
     * on the way in.
     *
     * ## Null is a refusal, not a zero
     *
     * `toCentavos()` answers null for anything that is not a usable amount —
     * blank, text, negative, or past the ceiling. Treating that as 0 would post
     * an entry that silently omits the amount; a nullable money column that has
     * never been filled in is exactly how that happens. So null throws and names
     * the row and the field.
     */
    private function centavos(string|int|float|null $pesos, string $field, Model $owner): int
    {
        $centavos = Money::toCentavos($pesos);

        if ($centavos === null) {
            // The identifier the operator already has on paper, not the model
            // class and primary key — and deliberately no dump of the stored
            // value, which is an internal detail of a row they cannot see.
            $label = $this->businessIdentifier($owner);

            throw CannotPostToTheBooksException::because(
                "{$label} has no usable {$field}, so it cannot be posted to the books. Nothing has been saved.");
        }

        return $centavos;
    }

    /**
     * The accounting date: a calendar day in Asia/Manila, never an instant.
     *
     * `accounting_journals.date` is a DATE column and is deliberately absent
     * from `TimezoneShift::COLUMNS` — it is the day a transaction belongs to,
     * chosen by the event, not derived from a clock. Formatting a Carbon with
     * `format('Y-m-d')` reads it in the app timezone (Asia/Manila), which is
     * what keeps a release at 07:00 Manila on the 18th from being filed on the
     * 17th.
     */
    private function dateOf(mixed $moment): string
    {
        if ($moment === null) {
            return now()->format('Y-m-d');
        }

        return $moment instanceof DateTimeInterface
            ? Carbon::instance($moment)->timezone(config('app.timezone'))->format('Y-m-d')
            : Carbon::parse((string) $moment)->format('Y-m-d');
    }

    /**
     * What an operator calls this record: "LN-000154", "RCP-000031".
     *
     * Falls back through the other issued codes, then to the model name, so a
     * record with no code yet still produces a message someone can act on.
     */
    private function businessIdentifier(Model $owner): string
    {
        foreach (['loan_account_number', 'receipt_number', 'application_number', 'reference_no'] as $column) {
            $value = $owner->getAttribute($column);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($owner);
    }

    /** "Loan release — LN-000154", clamped to the column's 500 characters. */
    private function describe(string $what, ?string $reference): string
    {
        $text = $reference === null || $reference === '' ? $what : "{$what} — {$reference}";

        return mb_substr($text, 0, 500);
    }
}
