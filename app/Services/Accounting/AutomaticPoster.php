<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Services\LoanReleaseFeeService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

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
    /**
     * No default. `= new JournalPoster` used to sit here, and it was fine right
     * up until JournalPoster gained a constructor dependency (PeriodGuard, so a
     * closed period actually refuses postings). A defaulted parameter lets the
     * container skip resolution and hand over the default instead, so every
     * caller got a JournalPoster built with zero arguments and every loan
     * release, collection and void answered 500.
     *
     * Nothing constructs this class directly — LoanService and RepaymentService
     * both reach it through `app(AutomaticPoster::class)` — so there is no
     * reason to carry a default, and carrying one silently opts out of exactly
     * the dependency wiring that keeps the period lock honest.
     */
    public function __construct(private readonly JournalPoster $poster) {}

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
     * `$method` is how the money left, in the lending vocabulary Record Payment
     * uses (`cash`, `gcash`, `maya`, `bank_transfer`, `online`, `auto_pay`) and
     * `loans.release_method` stores; {@see SettlementMethod::fromLendingMethod()}
     * turns it into the settlement account, and an unknown method is refused.
     * It defaults to `cash`, how releases posted before the method was
     * recorded, so a caller that passes none posts exactly as before.
     *
     * ## The deduction items go with it
     *
     * The rule books each withholding by its type, so it is handed the loan's
     * `deductions` list as well as the total — every item converted on its own,
     * never summed in pesos first. See {@see self::deductionItems()} and
     * {@see PostingRules::loanRelease()}.
     */
    public function loanRelease(Loan $loan, int $userId, string $method = 'cash'): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        $map = AccountMap::resolve();
        $posting = $this->releasePosting($loan, $map, $method);
        $this->warnAboutTaxFallbacks($loan, $map);

        return $this->write($posting, $loan, [
            'date' => $this->releaseDate($loan),
            'reference' => $loan->loan_account_number ?? $loan->application_number,
            'description' => $this->describe('Loan release', $loan->loan_account_number ?? $loan->application_number),
            'branch_id' => $loan->branch_id,
        ], $userId);
    }

    /**
     * Everything {@see self::loanRelease()} does EXCEPT write the journal: the
     * posting the release rule produces from the loan's current figures.
     *
     * For `accounting:loan-release-diff`, which compares that posting with the
     * journal already on the books and must never touch them — so nothing
     * here reaches {@see JournalPoster}. The same conversion and the same rule
     * as a real release, so the comparison is against what a release would
     * actually post, not against a second copy of the arithmetic.
     *
     * @return array{source: string, description: string, lines: list<array{account_id: int, debit: int, credit: int}>}
     *
     * @throws CannotPostToTheBooksException when the loan cannot be posted, exactly as a release would refuse
     */
    public function releasePosting(Loan $loan, AccountMap $map, string $method = 'cash'): array
    {
        return PostingRules::loanRelease(
            gross: $this->centavos($loan->principal_amount, 'principal amount', $loan),
            net: $this->centavos($loan->net_proceeds, 'net proceeds', $loan),
            deductions: $this->centavos($loan->total_deductions, 'total deductions', $loan),
            method: SettlementMethod::fromLendingMethod($method),
            map: $map,
            items: $this->deductionItems($loan),
            loan: $this->releaseLabel($loan),
        );
    }

    /**
     * The accounting date of a loan's release: the day its release journal is
     * filed under, read from `released_at` as {@see self::dateOf()} reads any
     * moment.
     *
     * Public so that the other record a release writes about the same money,
     * the member's share capital credit (ShareCapitalReleaseCredit), is dated
     * by this same code rather than by a second reading of the clock that
     * could put the two on different days.
     */
    public function releaseDate(Loan $loan): string
    {
        return $this->dateOf($loan->released_at);
    }

    /**
     * Log a warning when a tax this release withholds has no account of its
     * own in this chart and is booked to its fallback instead
     * ({@see PostingRules::taxAccountFallbacks()}). Only the real release
     * logs it; `accounting:loan-release-diff` reads the same posting through
     * {@see self::releasePosting()} and logs nothing.
     */
    private function warnAboutTaxFallbacks(Loan $loan, AccountMap $map): void
    {
        $fallbacks = PostingRules::taxAccountFallbacks($this->deductionItems($loan), $map);

        if ($fallbacks === []) {
            return;
        }

        $label = $this->releaseLabel($loan);

        Log::warning(
            "{$label}: a tax withheld at release was booked to a fallback account, because its own role has no account. "
            .'Set it in Accounting → Settings → Default Accounts so later releases book it to its own payable account.',
            ['loan' => $label, 'fallbacks' => $fallbacks],
        );
    }

    /**
     * The loan's deductions sorted by type, as the release rule books them.
     * Read only. See {@see PostingRules::classifyDeductions()}.
     *
     * Classified against this organisation's account mapping when it keeps
     * books, so a tax whose role has no account takes the same fallback the
     * release posting does, and ShareCapitalReleaseCredit and the diff command
     * read the posting's own answer.
     *
     * @return array{
     *     types: array<string, array{amount: int, role: string|null}>,
     *     mapped: array<string, int>,
     *     unmapped: array<string, int>,
     *     unusable: list<array{type: string, reason: string}>,
     *     remainder: int,
     *     credits: array<string, int>,
     * }
     */
    public function releaseDeductions(Loan $loan): array
    {
        return PostingRules::classifyDeductions(
            $this->centavos($loan->total_deductions, 'total deductions', $loan),
            $this->deductionItems($loan),
            $this->releaseLabel($loan),
            $this->enabled() ? AccountMap::resolve() : null,
        );
    }

    /**
     * The loan's deduction items exactly as the release rule reads them, each
     * amount converted to centavos by {@see self::deductionItems()}. Read only.
     *
     * For ShareCapitalReleaseCredit in an organisation that keeps no books,
     * which sums its share capital items with this same conversion but has no
     * journal for {@see PostingRules::classifyDeductions()}' guard to protect.
     *
     * @return list<array{name: string, amount: int|null, catalog_fee: bool}|mixed>
     */
    public function releaseDeductionItems(Loan $loan): array
    {
        return $this->deductionItems($loan);
    }

    /**
     * `loans.deductions` as the release rule takes it: each item's name, its
     * peso `amount` in centavos — or null when that amount is unusable — and
     * whether it came from the Settings fee catalog (it carries a `fee_id`).
     *
     * Only a LIST is a list of items. `null`, a JSON scalar or string, and a
     * JSON object (`{"processing_fee": 500}`) all mean "no items": the whole
     * total is then the unitemised remainder, booked as it always was. An entry
     * of the list that is not an array is passed through as it is, for the
     * rule to set aside.
     *
     * Each amount is converted on its own by {@see Money::toCentavos()}, so no
     * float sum of pesos ever reaches the books. A NEGATIVE amount keeps its
     * sign rather than becoming null: the rule refuses it on a mapped type and
     * names the figure, which it cannot do with a null.
     *
     * @return list<array{name: string, amount: int|null, catalog_fee: bool}|mixed>
     */
    private function deductionItems(Loan $loan): array
    {
        $deductions = $loan->deductions;

        if (! is_array($deductions) || ! array_is_list($deductions)) {
            return [];
        }

        return array_map(fn (mixed $item): mixed => is_array($item)
            ? [
                'name' => is_string($item['name'] ?? null) ? $item['name'] : '',
                'amount' => $this->itemCentavos($item['amount'] ?? null),
                'catalog_fee' => isset($item[LoanReleaseFeeService::ITEM_FEE_KEY]),
            ]
            : $item, $deductions);
    }

    /**
     * One item's peso amount in centavos, signed; null when it is not an
     * amount at all — missing, text, a list, or past what this module records.
     */
    private function itemCentavos(mixed $pesos): ?int
    {
        if (! is_int($pesos) && ! is_float($pesos) && ! is_string($pesos)) {
            return null;
        }

        $centavos = Money::toCentavos($pesos);

        if ($centavos !== null || ! is_numeric($pesos) || (float) $pesos >= 0) {
            return $centavos;
        }

        $magnitude = Money::toCentavos(is_string($pesos) ? ltrim(trim($pesos), '-') : -$pesos);

        return $magnitude === null ? null : -$magnitude;
    }

    /**
     * What a release refusal calls the loan. The application number, because
     * a refused release rolls back the loan account number it was issued.
     */
    private function releaseLabel(Loan $loan): string
    {
        $application = $loan->application_number;

        return is_string($application) && $application !== '' ? $application : $this->businessIdentifier($loan);
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
     * Two parts of it are read from rows the payment wrote just before:
     *
     * - the share capital build-up, the `share_capital_ledger` credit carrying
     *   this `repayment_id` ({@see self::buildUpCredited()}), which the
     *   journal credits to share capital rather than holding as an
     *   overpayment;
     * - on a loan a restructure created, the capitalised interest and penalty
     *   this payment recognises ({@see self::deferralFor()}).
     *
     * @return array{source: string, description: string, lines: list<array<string, mixed>>}|null
     */
    private function buildCollection(Repayment $repayment): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $overpayment = $this->centavos($repayment->overpayment, 'overpayment', $repayment);
        $loan = $repayment->loan;
        $deferral = $loan === null ? ['interest' => 0, 'penalty' => 0] : $this->deferralFor($loan, $repayment);

        return PostingRules::loanCollection(
            received: $this->centavos($repayment->amount_paid, 'amount paid', $repayment),
            principal: $this->centavos($repayment->principal_applied, 'principal applied', $repayment),
            interest: $this->centavos($repayment->interest_applied, 'interest applied', $repayment),
            penalty: $this->centavos($repayment->penalty_applied, 'penalty applied', $repayment),
            fees: 0,
            overpayment: $overpayment,
            method: SettlementMethod::fromLendingMethod((string) $repayment->method),
            map: AccountMap::resolve(),
            insurance: $this->centavos($repayment->insurance_applied ?? 0, 'insurance applied', $repayment),
            shareCapital: $overpayment > 0 ? $this->buildUpCredited($repayment) : 0,
            deferredInterest: $deferral['interest'],
            deferredPenalty: $deferral['penalty'],
        );
    }

    /**
     * The share capital this payment credited to the member's ledger, in
     * centavos: its `share_capital_ledger` credit rows. A void's reversing
     * debit carries the same `repayment_id` and is not counted; the void
     * reverses the whole journal instead.
     */
    private function buildUpCredited(Repayment $repayment): int
    {
        return (int) ShareCapitalLedger::query()
            ->where('repayment_id', $repayment->getKey())
            ->where('credit', '>', 0)
            ->pluck('credit')
            ->sum(fn (mixed $credit): int => $this->centavos($credit, 'share capital credit', $repayment));
    }

    /**
     * What this payment recognises of the interest and penalty the loan's
     * restructure capitalised ({@see PostingRules::deferralRecognition()}):
     * in proportion to the principal collected to date, this payment
     * included, less what the loan's other collection journals already
     * recognised; all of the rest once the loan has no open period.
     *
     * @return array{interest: int, penalty: int}
     */
    private function deferralFor(Loan $loan, Repayment $repayment): array
    {
        $state = $this->deferralState($loan, (int) $repayment->getKey());
        $capitalised = $state['capitalised'];

        if ($capitalised['interest'] + $capitalised['penalty'] === 0) {
            return ['interest' => 0, 'penalty' => 0];
        }

        $paid = Repayment::query()
            ->where('loan_id', $loan->getKey())
            ->where('status', 'posted')
            ->pluck('principal_applied')
            ->sum(fn (mixed $principal): int => $this->centavos($principal, 'principal applied', $repayment));

        return PostingRules::deferralRecognition(
            capitalisedInterest: $capitalised['interest'],
            capitalisedPenalty: $capitalised['penalty'],
            principal: $this->centavos($loan->principal_amount, 'principal amount', $loan),
            principalPaid: (int) $paid,
            complete: ! $this->hasOpenPeriod($loan),
            recognisedInterest: $state['recognised']['interest'],
            recognisedPenalty: $state['recognised']['penalty'],
            released: $state['released'],
            takenInterest: $state['taken']['interest'],
            takenPenalty: $state['taken']['penalty'],
        );
    }

    /**
     * The interest and penalty a restructure capitalised into this loan (or
     * carried to it from its source's own deferral) that the books have not
     * recognised yet, in centavos: the split stored on the loan plus what its
     * J1 carried in, less what its collections recognised, less what a
     * partial balance reduction cleared, less what was carried on to the loan
     * that restructured it, less what a write-off (J2, when this loan is
     * itself restructured) or a completing balance reduction took. Both zero
     * for a loan no restructure created, or one released before the split
     * was stored. Without books nothing has been recognised or carried, so it
     * is the whole stored split.
     *
     * Read from the posted journals by their marked lines
     * ({@see PostingRules::DEFERRAL_RECOGNISED_LINE}), so a reversed (voided)
     * collection counts for nothing.
     *
     * @return array{interest: int, penalty: int}
     */
    public function unrecognisedDeferral(Loan $loan): array
    {
        return $this->remainingOf($this->deferralState($loan));
    }

    /**
     * {@see self::unrecognisedDeferral()} of a restructure's source as it
     * stood before that restructure: without the write-off (J2) on it and
     * without what was carried off it to the new loan. A restructured loan
     * takes no other posting, so this is the same before the release and
     * after it, which is what lets the builders that write nothing produce
     * exactly the J1 and J2 that were posted.
     *
     * @return array{interest: int, penalty: int}
     */
    private function deferralBeforeItsRestructure(Loan $source): array
    {
        return $this->remainingOf($this->deferralState($source, beforeItsRestructure: true));
    }

    /**
     * @param  array{capitalised: array{interest: int, penalty: int}, recognised: array{interest: int, penalty: int}, taken: array{interest: int, penalty: int}, released: int}  $state
     * @return array{interest: int, penalty: int}
     */
    private function remainingOf(array $state): array
    {
        return PostingRules::deferralRemaining(
            $state['capitalised']['interest'],
            $state['capitalised']['penalty'],
            $state['recognised']['interest'],
            $state['recognised']['penalty'],
            $state['released'],
            $state['taken']['interest'],
            $state['taken']['penalty'],
        );
    }

    /**
     * Everything the books hold about a loan's deferral, in centavos:
     *
     * - `capitalised`: the split stored on the loan, plus what its J1
     *   carried in from its source;
     * - `recognised`: what its collections recognised (`$exceptRepaymentId`'s
     *   left out);
     * - `taken`: what was taken off by part, a partial reduction's share and
     *   what was carried on to the loan that restructured it;
     * - `released`: what a write-off (J2) or a completing reduction took.
     *
     * Without books, or on a loan no restructure created, only the stored
     * split, untouched.
     *
     * @return array{capitalised: array{interest: int, penalty: int}, recognised: array{interest: int, penalty: int}, taken: array{interest: int, penalty: int}, released: int}
     */
    private function deferralState(Loan $loan, ?int $exceptRepaymentId = null, bool $beforeItsRestructure = false): array
    {
        $own = $this->capitalised($loan);
        $none = ['interest' => 0, 'penalty' => 0];

        if (! $this->enabled() || ($loan->source_loan_id === null && $own['interest'] + $own['penalty'] === 0)) {
            return ['capitalised' => $own, 'recognised' => $none, 'taken' => $none, 'released' => 0];
        }

        $movements = $this->deferralMovements($loan, $exceptRepaymentId, $beforeItsRestructure);

        return [
            'capitalised' => [
                'interest' => $own['interest'] + $movements['carried_in_interest'],
                'penalty' => $own['penalty'] + $movements['carried_in_penalty'],
            ],
            'recognised' => ['interest' => $movements['interest'], 'penalty' => $movements['penalty']],
            'taken' => ['interest' => $movements['taken_interest'], 'penalty' => $movements['taken_penalty']],
            'released' => $movements['released'],
        ];
    }

    /**
     * The capitalised interest and penalty stored on the loan, in centavos;
     * zero where none was stored.
     *
     * @return array{interest: int, penalty: int}
     */
    private function capitalised(Loan $loan): array
    {
        $part = fn (mixed $pesos, string $field): int => $pesos === null ? 0 : $this->centavos($pesos, $field, $loan);

        return [
            'interest' => $part($loan->restructure_capitalised_interest, 'capitalised interest'),
            'penalty' => $part($loan->restructure_capitalised_penalty, 'capitalised penalty'),
        ];
    }

    /**
     * What the posted journals did to a loan's deferral, in centavos, read by
     * the marked lines, each from the one kind of document that writes it:
     *
     * - `interest`, `penalty`: recognised by its collections (the Repayment
     *   journals, `$exceptRepaymentId`'s left out);
     * - `released`: taken off by its write-off (J2, keyed on the loan) and by
     *   a completing balance reduction (its LoanAdjustment journals);
     * - `taken_interest`, `taken_penalty`: cleared by part by a partial
     *   balance reduction, and carried off it in the J1 of the loan that
     *   restructured it;
     * - `carried_in_interest`, `carried_in_penalty`: carried onto it from its
     *   source, in its own J1.
     *
     * With `$beforeItsRestructure`, the loan's own restructure is left out
     * (its J2, and what its new loan's J1 carried off it).
     *
     * @return array{interest: int, penalty: int, released: int, taken_interest: int, taken_penalty: int, carried_in_interest: int, carried_in_penalty: int}
     */
    private function deferralMovements(Loan $loan, ?int $exceptRepaymentId = null, bool $beforeItsRestructure = false): array
    {
        $repayments = Repayment::query()->where('loan_id', $loan->getKey())->select('id')->toBase();
        $adjustments = LoanAdjustment::query()->where('loan_id', $loan->getKey())->select('id')->toBase();
        $restructuredInto = Loan::query()->where('source_loan_id', $loan->getKey())->select('id')->toBase();
        $loanType = $loan->getMorphClass();

        $sums = DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->where('j.status', 'posted')
            ->where(function (QueryBuilder $documents) use ($loan, $loanType, $repayments, $adjustments, $restructuredInto, $exceptRepaymentId, $beforeItsRestructure): void {
                $documents
                    ->where(function (QueryBuilder $collections) use ($repayments, $exceptRepaymentId): void {
                        $collections->where('j.source', 'loan_collection')
                            ->where('j.postable_type', (new Repayment)->getMorphClass())
                            ->whereIn('j.postable_id', $repayments)
                            ->when($exceptRepaymentId !== null, fn (QueryBuilder $q) => $q->where('j.postable_id', '!=', $exceptRepaymentId))
                            ->whereIn('l.description', [PostingRules::DEFERRED_INTEREST_LINE, PostingRules::DEFERRED_PENALTY_LINE]);
                    })
                    ->orWhere(function (QueryBuilder $clearing) use ($adjustments): void {
                        $clearing->where('j.source', 'adjustment')
                            ->where('j.postable_type', (new LoanAdjustment)->getMorphClass())
                            ->whereIn('j.postable_id', $adjustments)
                            ->whereIn('l.description', [
                                PostingRules::DEFERRAL_CLEARED_LINE,
                                PostingRules::DEFERRED_INTEREST_CLEARED_LINE,
                                PostingRules::DEFERRED_PENALTY_CLEARED_LINE,
                            ]);
                    })
                    ->orWhere(function (QueryBuilder $carriedIn) use ($loan, $loanType): void {
                        $carriedIn->where('j.source', 'loan_release')
                            ->where('j.postable_type', $loanType)
                            ->where('j.postable_id', $loan->getKey())
                            ->whereIn('l.description', [PostingRules::DEFERRED_INTEREST_CARRIED_IN_LINE, PostingRules::DEFERRED_PENALTY_CARRIED_IN_LINE]);
                    })
                    ->when(! $beforeItsRestructure, fn (QueryBuilder $q) => $q
                        ->orWhere(function (QueryBuilder $writeOff) use ($loan, $loanType): void {
                            $writeOff->where('j.source', 'credit_loss')
                                ->where('j.postable_type', $loanType)
                                ->where('j.postable_id', $loan->getKey())
                                ->where('l.description', PostingRules::DEFERRAL_WRITTEN_OFF_LINE);
                        })
                        ->orWhere(function (QueryBuilder $carriedOut) use ($loanType, $restructuredInto): void {
                            $carriedOut->where('j.source', 'loan_release')
                                ->where('j.postable_type', $loanType)
                                ->whereIn('j.postable_id', $restructuredInto)
                                ->whereIn('l.description', [PostingRules::DEFERRED_INTEREST_CARRIED_OUT_LINE, PostingRules::DEFERRED_PENALTY_CARRIED_OUT_LINE]);
                        }));
            })
            ->groupBy('l.description')
            ->selectRaw('l.description AS marker, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit')
            ->get()
            ->keyBy('marker');

        $credit = fn (string $marker): int => (int) ($sums[$marker]->credit ?? 0);
        $debit = fn (string $marker): int => (int) ($sums[$marker]->debit ?? 0);

        return [
            'interest' => $credit(PostingRules::DEFERRED_INTEREST_LINE),
            'penalty' => $credit(PostingRules::DEFERRED_PENALTY_LINE),
            'released' => $debit(PostingRules::DEFERRAL_WRITTEN_OFF_LINE) + $debit(PostingRules::DEFERRAL_CLEARED_LINE),
            'taken_interest' => $debit(PostingRules::DEFERRED_INTEREST_CLEARED_LINE) + $debit(PostingRules::DEFERRED_INTEREST_CARRIED_OUT_LINE),
            'taken_penalty' => $debit(PostingRules::DEFERRED_PENALTY_CLEARED_LINE) + $debit(PostingRules::DEFERRED_PENALTY_CARRIED_OUT_LINE),
            'carried_in_interest' => $credit(PostingRules::DEFERRED_INTEREST_CARRIED_IN_LINE),
            'carried_in_penalty' => $credit(PostingRules::DEFERRED_PENALTY_CARRIED_IN_LINE),
        ];
    }

    /**
     * The principal still unpaid on the loan's open periods, in centavos,
     * floored per period as every outstanding figure is
     * ({@see AmortizationSchedule::remainingPrincipalSql()}).
     */
    private function unpaidPrincipal(Loan $loan): int
    {
        $pesos = AmortizationSchedule::query()
            ->where('loan_id', $loan->getKey())
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->selectRaw('COALESCE(SUM('.AmortizationSchedule::remainingPrincipalSql().'), 0) AS unpaid')
            ->value('unpaid');

        return $this->centavos(is_numeric($pesos) ? (string) $pesos : '0', 'unpaid principal', $loan);
    }

    /** Whether the loan still has a period with something unpaid on it. */
    private function hasOpenPeriod(Loan $loan): bool
    {
        return AmortizationSchedule::query()
            ->where('loan_id', $loan->getKey())
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->exists();
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
     *
     * Savings reverses through here too (Phase 3): a reversed deposit or
     * withdrawal (SavingsTransaction, `savings`), a reversed interest run
     * (SavingsInterestRun, `savings_interest`) and a time deposit's postings
     * (TimeDeposit, `time_deposit` and `time_deposit_settlement`). A null
     * `$userId` reverses as the system. The reversal is dated today.
     */
    public function reverseFor(
        Model $postable,
        string $source,
        ?int $userId,
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
     * The journals for releasing a loan a restructure created (Phase 2,
     * decisions 2 and 3), or both null when this organisation keeps no books.
     *
     * - `release`, J1, keyed on `$new` with source `loan_release`: the
     *   principal is owed, the source's principal is carried, the capitalised
     *   interest and penalty are deferred, and the deductions are collected
     *   from the member through `$method`. Nothing is paid out. The source's
     *   own unrecognised deferral that J2 does not use moves to `$new`, by
     *   part ({@see PostingRules::restructureCarry()}).
     * - `write_off`, J2, keyed on `$source` with source `credit_loss`, only
     *   when the split writes principal off: against the source's own
     *   unrecognised deferral first, then the allowance, then expense.
     *
     * Reads the split stored on `$new` (`restructure_carried_principal`,
     * `restructure_capitalised_interest`, `restructure_capitalised_penalty`,
     * `restructure_written_off_principal`, from
     * {@see PostingRules::restructureSplit()} of the source's open periods
     * before they were cleared), so call it after those are saved and AFTER
     * `applyInsuranceOnRelease()`, as {@see self::loanRelease()} is. It
     * locks the allowance account row before it posts anything, so call it
     * before anything else in the transaction posts a journal: every posting
     * takes the journal-number lock, and the allowance lock must come first.
     *
     * It must run inside the release's own transaction, and is refused
     * outside one (books or not, so a misplaced call fails in every test).
     * Refused rather than wrapped in a transaction of its own: that would
     * hold J1 and J2 together, but not with the release itself (the loan's
     * status, schedules and stored split), so the books could post a
     * restructure the lending side then fails to save. Outside a transaction
     * the allowance lock would also be released at once.
     *
     * The source's unrecognised deferral is read from this transaction's
     * snapshot. That is sound only while the release locks the source loan
     * row before its first plain read (LoanService::release() takes its
     * collateral and loan locks first), so nothing can post to the source
     * between the snapshot and here. The caller must keep that order.
     *
     * @param  string  $method  how the member paid the deductions, in the lending vocabulary
     * @return array{release: AccountingJournal|null, write_off: AccountingJournal|null}
     *
     * @throws CannotPostToTheBooksException when the split is missing or does not reconcile, the two loans are in different branches, or a role is unmapped
     * @throws LogicException when called outside a transaction
     */
    public function restructureRelease(Loan $new, Loan $source, int $userId, string $method): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'AutomaticPoster::restructureRelease() must run inside the release\'s transaction: its allowance lock '
                .'and its two journals only hold together there.');
        }

        if (! $this->enabled()) {
            return ['release' => null, 'write_off' => null];
        }

        if ((int) $new->source_loan_id !== (int) $source->getKey()) {
            throw CannotPostToTheBooksException::because(
                "{$this->releaseLabel($new)} is not a restructure of {$this->businessIdentifier($source)}, so its release "
                .'cannot be posted against it. Nothing has been saved.');
        }

        // J1 posts in the new loan's branch and J2 in the source's, so the
        // two must be one branch. A member's branch can't change while a loan
        // is collectible, so only an imported or hand-edited row differs.
        if ((int) $new->branch_id !== (int) $source->branch_id) {
            throw CannotPostToTheBooksException::because(
                "{$this->releaseLabel($new)} is in a different branch from {$this->businessIdentifier($source)}, the loan it "
                .'restructures, so its release and write-off would post to two branches. Nothing has been saved.');
        }

        $map = AccountMap::resolve();
        $writeOff = $this->writeOffPosting($new, $source, $map, lock: true);
        $release = $this->releaseOfARestructure($new, $source, $map, $method);
        $this->warnAboutTaxFallbacks($new, $map);

        $date = $this->releaseDate($new);
        $newNumber = $new->loan_account_number ?? $new->application_number;
        $sourceNumber = $source->loan_account_number ?? $source->application_number;

        return [
            'release' => $this->write($release, $new, [
                'date' => $date,
                'reference' => $newNumber,
                'description' => $this->describe('Loan release (restructure)', $newNumber),
                'branch_id' => $new->branch_id,
            ], $userId),
            'write_off' => $writeOff === null ? null : $this->write($writeOff, $source, [
                'date' => $date,
                'reference' => $sourceNumber,
                'description' => $this->describe('Restructure write-off', "{$sourceNumber} into {$newNumber}"),
                'branch_id' => $source->branch_id,
            ], $userId),
        ];
    }

    /**
     * J1 as {@see self::restructureRelease()} would post it, written
     * nowhere: for `accounting:loan-release-diff`, which compares it with the
     * journal on the books. The same conversion and rule as a real release.
     *
     * @return array{source: string, description: string, lines: list<array<string, mixed>>}
     *
     * @throws CannotPostToTheBooksException when the loan cannot be posted, exactly as its release would refuse
     */
    public function restructureReleasePosting(Loan $new, AccountMap $map, string $method): array
    {
        return $this->releaseOfARestructure($new, $new->sourceLoan, $map, $method);
    }

    /**
     * J1, with what the source's own deferral carries to the new loan: all
     * of it less what J2 takes ({@see PostingRules::restructureCarry()}),
     * read as it stood before this restructure, so it is the same before the
     * release and after it.
     *
     * @return array{source: string, description: string, lines: list<array<string, mixed>>}
     */
    private function releaseOfARestructure(Loan $new, ?Loan $source, AccountMap $map, string $method): array
    {
        $split = $this->storedSplit($new);
        $deferral = $source === null || ! $this->enabled()
            ? ['interest' => 0, 'penalty' => 0]
            : $this->deferralBeforeItsRestructure($source);
        $carry = PostingRules::restructureCarry($deferral['interest'], $deferral['penalty'], $split['written_off']);

        return PostingRules::restructureRelease(
            principal: $this->centavos($new->principal_amount, 'principal amount', $new),
            carried: $split['carried'],
            capitalisedInterest: $split['interest'],
            capitalisedPenalty: $split['penalty'],
            deductions: $this->centavos($new->total_deductions, 'total deductions', $new),
            method: SettlementMethod::fromLendingMethod($method),
            map: $map,
            items: $this->deductionItems($new),
            loan: $this->releaseLabel($new),
            source: $this->businessIdentifier($source ?? $new),
            carriedInterest: $carry['interest'],
            carriedPenalty: $carry['penalty'],
        );
    }

    /**
     * J2 as {@see self::restructureRelease()} would post it, or null when
     * the restructure writes no principal off; written nowhere and locking
     * nothing, so the allowance balance it reads can move before a real
     * release reads it again under the lock.
     *
     * @return array{source: string, description: string, lines: list<array<string, mixed>>, parts: array{from_deferral: int, from_allowance: int, expensed: int}}|null
     */
    public function restructureWriteOffPosting(Loan $new, Loan $source, AccountMap $map): ?array
    {
        return $this->writeOffPosting($new, $source, $map, lock: false);
    }

    /**
     * @return array{source: string, description: string, lines: list<array<string, mixed>>, parts: array{from_deferral: int, from_allowance: int, expensed: int}}|null
     */
    private function writeOffPosting(Loan $new, Loan $source, AccountMap $map, bool $lock): ?array
    {
        $writtenOff = $this->storedSplit($new)['written_off'];

        if ($writtenOff === 0) {
            return null;
        }

        $remaining = $this->deferralBeforeItsRestructure($source);
        $deferral = $remaining['interest'] + $remaining['penalty'];
        $allowance = $deferral >= $writtenOff ? 0 : $this->allowanceBalance($map, $lock);

        return PostingRules::restructureWriteOff($writtenOff, $deferral, $allowance, $map);
    }

    /**
     * The restructure split stored on a new loan, in centavos.
     *
     * @return array{carried: int, interest: int, penalty: int, written_off: int}
     *
     * @throws CannotPostToTheBooksException when the loan is no restructure, carries no split, or the split cannot be one
     */
    private function storedSplit(Loan $new): array
    {
        $label = $this->releaseLabel($new);

        if ($new->source_loan_id === null) {
            throw CannotPostToTheBooksException::because(
                "{$label} is not a restructure, so it has no carried principal to post. Nothing has been saved.");
        }

        $columns = [
            'carried' => 'restructure_carried_principal',
            'interest' => 'restructure_capitalised_interest',
            'penalty' => 'restructure_capitalised_penalty',
            'written_off' => 'restructure_written_off_principal',
        ];
        $split = [];

        foreach ($columns as $part => $column) {
            if ($new->getAttribute($column) === null) {
                throw CannotPostToTheBooksException::because(
                    "{$label} is a restructure with no recorded split of carried principal, capitalised interest and "
                    .'penalty, and principal written off, so its release cannot be posted. Nothing has been saved.');
            }

            $split[$part] = $this->centavos($new->getAttribute($column), str_replace('_', ' ', substr($column, 12)), $new);
        }

        if ($split['written_off'] > 0 && $split['interest'] + $split['penalty'] > 0) {
            throw CannotPostToTheBooksException::because(
                "{$label} does not reconcile: a restructure either writes principal off or capitalises interest and "
                .'penalty, never both. Nothing has been saved.');
        }

        return $split;
    }

    /**
     * The allowance account's organisation-wide credit balance, in centavos,
     * signed: every posted and reversed line on it, every branch.
     *
     * With `$lock` (a real write-off) the account row is locked first, so
     * nothing that takes the same lock (another write-off, an allowance
     * adjustment) can move the balance before this transaction commits. Then
     * the balance is read with LOCKING reads, which is what makes the lock
     * sound. Under REPEATABLE READ a plain SELECT answers from the snapshot
     * this transaction's first plain read took, long before a release gets
     * here, and would miss a provision another request committed since: two
     * restructures could then both take the same allowance
     * (CollateralPledgeGuard documents the same trap). A locking read answers
     * from the latest committed rows.
     *
     * Two locking reads, lines by their account index and then their
     * journals by primary key, so InnoDB locks exactly the allowance's lines
     * and their journals, whatever plan a joined query would get. FOR UPDATE,
     * not FOR SHARE, for one reason: the write-off goes on to take the
     * journal-number lock, an exclusive lock on the newest journal, which may
     * be one of these, and holding a shared lock there and then asking for an
     * exclusive one while another posting waits for it is InnoDB's textbook
     * deadlock. FOR UPDATE does NOT stop the other deadlock these reads open:
     * a writer that locks its journal and then reaches for an allowance line
     * would wait on this transaction while this one waits on its journal.
     * What prevents that is the lock order every writer of allowance lines
     * follows, the account row first: JournalPoster's draft, edit, post and
     * reversal ({@see JournalPoster::lockTheAllowanceIfTouched()}), and here
     * {@see self::lockTheAllowance()} in the write-off, allowance adjustments
     * and provisions.
     *
     * Without `$lock` (a preview, which writes nothing) it is one plain read.
     */
    private function allowanceBalance(AccountMap $map, bool $lock): int
    {
        $accountId = $map->accountFor('allowance_credit_losses');

        if ($lock) {
            $this->lockTheAllowance($map);

            $byJournal = DB::table('accounting_journal_lines')
                ->where('accounting_account_id', $accountId)
                ->groupBy('accounting_journal_id')
                ->selectRaw('accounting_journal_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance')
                ->lockForUpdate()
                ->pluck('balance', 'accounting_journal_id');

            if ($byJournal->isEmpty()) {
                return 0;
            }

            $historical = DB::table('accounting_journals')
                ->whereIn('id', $byJournal->keys()->all())
                ->whereIn('status', AccountingJournal::HISTORICAL_STATUSES)
                ->lockForUpdate()
                ->pluck('id');

            return (int) $historical->sum(fn (mixed $journalId): int => (int) $byJournal[(int) $journalId]);
        }

        return (int) DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('l.accounting_account_id', $accountId)
            ->selectRaw('COALESCE(SUM(l.credit) - SUM(l.debit), 0) AS balance')
            ->value('balance');
    }

    /**
     * The journal for an applied balance adjustment (decision 7), keyed on
     * the LoanAdjustment with source `adjustment`, in the loan's branch, or
     * null when this organisation keeps no books.
     *
     * `$signedCentavos` is the change to the principal balance: negative
     * reduces it (Dr loan adjustments / Cr loans receivable), positive
     * increases it (the mirror). It must equal the adjustment's own
     * `adjustment_amount`. A reduction on a restructured loan still holding
     * deferral also clears some of it (Dr unearned restructure / Cr loan
     * adjustments), so call it after the adjustment has restated the
     * periods:
     *
     * - all of it, when the reduction left no open period;
     * - otherwise its share, by part: the deferral × the principal reduced ÷
     *   the principal outstanding before the reduction (the open periods'
     *   unpaid principal now, plus the reduction)
     *   ({@see PostingRules::deferralShare()}).
     *
     * An increase clears nothing.
     *
     * @throws CannotPostToTheBooksException for another adjustment type, a stated amount that is missing or disagrees, or an unmapped role
     */
    public function balanceAdjustment(LoanAdjustment $adjustment, int $signedCentavos, int $userId): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        $number = $adjustment->adjustment_number;

        if ($adjustment->adjustment_type !== 'balance_adjustment') {
            throw CannotPostToTheBooksException::because(
                "{$number} is not a balance adjustment, so it posts no balance adjustment journal.");
        }

        $stated = $adjustment->ownNewValues()['adjustment_amount'] ?? null;

        if (! is_numeric($stated)) {
            throw CannotPostToTheBooksException::because(
                "{$number} records no adjustment amount, so there is nothing to check the "
                .Money::format($signedCentavos).' to be posted against. Nothing has been saved.');
        }

        if ($this->signedCentavos($stated) !== $signedCentavos) {
            throw CannotPostToTheBooksException::because(
                "{$number} states an adjustment of ".Money::format($this->signedCentavos($stated)).', but '
                .Money::format($signedCentavos).' was to be posted. Nothing has been saved.');
        }

        /** @var Loan $loan */
        $loan = $adjustment->loan;
        $cleared = 0;
        $share = ['interest' => 0, 'penalty' => 0];

        if ($signedCentavos < 0 && $loan->source_loan_id !== null) {
            $remaining = $this->unrecognisedDeferral($loan);

            if (! $this->hasOpenPeriod($loan)) {
                $cleared = $remaining['interest'] + $remaining['penalty'];
            } elseif ($remaining['interest'] + $remaining['penalty'] > 0) {
                $reduced = -$signedCentavos;
                $share = PostingRules::deferralShare($remaining['interest'], $remaining['penalty'], $reduced, $this->unpaidPrincipal($loan) + $reduced);
            }
        }

        $posting = PostingRules::balanceAdjustment($signedCentavos, AccountMap::resolve(), $cleared, $share['interest'], $share['penalty']);

        return $this->write($posting, $adjustment, [
            'date' => $adjustment->applied_at,
            'reference' => $number,
            'description' => $this->describe('Balance adjustment', $number),
            'branch_id' => $loan->branch_id,
        ], $userId);
    }

    /**
     * The journal for a share capital entry a member paid in or took out on
     * its own (decision 5), keyed on the ledger row with source
     * `share_capital`, dated the row's date, in the member's branch, or null
     * when this organisation keeps no books. An auto-credit run calls it per
     * row with the method chosen for the run.
     *
     * A row written by a payment's build-up (`repayment_id`) or by a release
     * (`loan_id`) is refused: its money is already in that journal.
     *
     * @param  string  $method  how the money moved, in the lending vocabulary
     */
    public function shareCapitalEntry(ShareCapitalLedger $row, string $method, int $userId): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($row->repayment_id !== null || $row->loan_id !== null) {
            throw CannotPostToTheBooksException::because(
                "{$row->reference} was written by a ".($row->repayment_id !== null ? 'payment' : 'loan release')
                .', and its money is already in that journal. Posting it again would count it twice.');
        }

        $posting = PostingRules::shareCapitalEntry(
            $this->centavos($row->credit ?? 0, 'credit', $row),
            $this->centavos($row->debit ?? 0, 'debit', $row),
            SettlementMethod::fromLendingMethod($method),
            AccountMap::resolve(),
        );

        return $this->write($posting, $row, [
            'date' => $row->date,
            'reference' => $row->reference,
            'description' => $this->describe($posting['description'], $row->reference),
            'branch_id' => Borrower::query()->whereKey($row->borrower_id)->value('branch_id'),
        ], $userId);
    }

    /**
     * The journal for a GCash Cash In or Cash Out (decision 6), keyed on the
     * transaction with source `cash_in` or `cash_out`, or null when this
     * organisation keeps no books. A Cash In posts when it is paid, dated
     * `paid_at`; a Cash Out when it is created, dated `transaction_date`.
     * The branch is the member's; a walk-in's has none.
     *
     * @throws CannotPostToTheBooksException for a Cash In not yet paid, figures that disagree, or an unmapped role
     */
    public function gcashTransaction(GCashTransaction $tx, int $userId): ?AccountingJournal
    {
        if (! $this->enabled()) {
            return null;
        }

        $type = (string) $tx->type;
        $amount = $this->centavos($tx->amount, 'amount', $tx);
        $charge = $this->centavos($tx->charge_amount ?? 0, 'charge', $tx);
        $total = $this->centavos($tx->total_amount, 'total', $tx);
        $posting = PostingRules::gcashTransaction($type, $amount, $charge, AccountMap::resolve());

        [$status, $date, $expected] = $type === 'cash_in'
            ? ['paid', $tx->paid_at, $amount + $charge]
            : ['completed', $tx->transaction_date, $amount - $charge];

        if ($tx->status !== $status || $date === null) {
            throw CannotPostToTheBooksException::because(
                "{$tx->reference_no} is {$tx->status}. A ".($type === 'cash_in' ? 'Cash In posts once it is paid' : 'Cash Out posts once it is completed')
                .'. Nothing has been saved.');
        }

        if ($total !== $expected) {
            throw CannotPostToTheBooksException::because(
                "{$tx->reference_no} does not reconcile: its total is ".Money::format($total).', but its amount and '
                .'charge make '.Money::format($expected).'. Nothing has been saved.');
        }

        return $this->write($posting, $tx, [
            'date' => $date,
            'reference' => $tx->reference_no,
            'description' => $this->describe($posting['description'], $tx->reference_no),
            'branch_id' => $tx->borrower_id === null ? null : Borrower::query()->whereKey($tx->borrower_id)->value('branch_id'),
        ], $userId);
    }

    /**
     * A change to the allowance for credit losses (decision 12), source
     * `credit_loss`, or null when this organisation keeps no books: a
     * positive `$signedCentavos` provides, a negative one releases.
     *
     * `$postable` is the allowance run's own row. Never a Loan: a loan's
     * `credit_loss` slot is its restructure write-off (J2), and the
     * idempotency index would hand one back as the other. The allowance
     * account row is locked first, as the write-off locks it.
     *
     * @param  string  $date  the accounting date, `Y-m-d`
     */
    public function allowanceAdjustment(Model $postable, int $signedCentavos, ?int $branchId, string $date, int $userId): ?AccountingJournal
    {
        $this->refuseTheWriteOffSlot($postable, 'An allowance adjustment', 'the allowance run that produced it');

        if (! $this->enabled()) {
            return null;
        }

        $map = AccountMap::resolve();
        $posting = PostingRules::allowanceAdjustment($signedCentavos, $map);
        $this->lockTheAllowance($map);

        return $this->write($posting, $postable, [
            'date' => $date,
            'description' => $posting['description'],
            'branch_id' => $branchId,
        ], $userId);
    }

    /**
     * The allowance account row lock, before any journal or line lock: the
     * order every writer of allowance lines follows (see
     * {@see self::allowanceBalance()}). Held to the end of the transaction.
     */
    private function lockTheAllowance(AccountMap $map): void
    {
        AccountingAccount::query()->whereKey($map->accountFor('allowance_credit_losses'))->lockForUpdate()->first();
    }

    /**
     * A `credit_loss` journal keyed on a Loan is that loan's restructure
     * write-off (J2). Anything else posted there would take the slot, and
     * the idempotency index would hand it back as the write-off, so J2 would
     * never post. Refused before the books check, so a caller's mistake
     * shows with or without a chart, as a missing transaction does.
     */
    private function refuseTheWriteOffSlot(Model $postable, string $what, string $keyOn): void
    {
        if ($postable instanceof Loan) {
            throw CannotPostToTheBooksException::because(
                "{$what} cannot be keyed on a loan: that slot is the loan's restructure write-off. Key it on {$keyOn}.");
        }
    }

    /**
     * A signed peso figure as signed centavos, by {@see Money::toCentavos()}
     * on its size, so a stored `-2500.5` is exactly -250050.
     */
    private function signedCentavos(int|float|string $pesos): int
    {
        $negative = is_string($pesos) ? str_starts_with(trim($pesos), '-') : $pesos < 0;
        $centavos = Money::toCentavos(is_string($pesos) ? ltrim(trim($pesos), '-') : abs($pesos));

        if ($centavos === null) {
            throw CannotPostToTheBooksException::because('The adjustment states an amount this system cannot post. Nothing has been saved.');
        }

        return $negative ? -$centavos : $centavos;
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
     * loan would let it happen exactly once. See {@see self::loanFee()}. A
     * Loan is refused: its `credit_loss` slot is its restructure write-off.
     */
    public function creditLossProvision(Model $postable, string|int|float $pesos, int $userId, array $context = []): ?AccountingJournal
    {
        $this->refuseTheWriteOffSlot($postable, 'A credit loss provision', 'the provision row');

        if (! $this->enabled()) {
            return null;
        }

        $map = AccountMap::resolve();
        $posting = PostingRules::creditLossProvision($this->centavos($pesos, 'provision amount', $postable), $map);
        $this->lockTheAllowance($map);

        return $this->write($posting, $postable, $context, $userId);
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

    // ── Savings & Deposits (Phase 3, design §4) ──
    //
    // Called inside the savings write's own transaction, after SavingsLedger
    // (lock step 7). Unlike the lending postings these never answer null for
    // an organisation with no books: savings needs books, a product can't be
    // turned on without them, and a savings row with no journal would leave
    // the subledger and the general ledger disagreeing. Branch = the
    // account's or the time deposit's; date = the row's business date.

    /**
     * How a savings row's money moved, as the settlement role. `savings` is a
     * move between savings records and settles through no money account.
     */
    private const SAVINGS_SETTLEMENT_ROLES = ['cash' => 'cash', 'bank_transfer' => 'bank', 'gcash' => 'gcash', 'maya' => 'maya'];

    /**
     * A deposit, a withdrawal or a closing payout (source `savings`, keyed on
     * the row): Dr settlement / Cr savings deposits, or the reverse. A
     * transfer between a member's own accounts in one branch posts nothing
     * (null): both legs sit on savings deposits. Call it for a transfer leg
     * only once both legs are written (the second leg names the first as its
     * `counterpart_transaction_id`); a leg with no other leg, or legs in two
     * branches, are refused (decision 9). A row whose money belongs to
     * another journal (interest, tax, a time deposit's, or a reversal, which
     * goes through reverseFor()) is refused.
     *
     * @throws CannotPostToTheBooksException
     */
    public function savingsTransaction(SavingsTransaction $row, ?int $userId): ?AccountingJournal
    {
        $this->requireBooksForSavings();

        if (in_array($row->type, ['transfer_in', 'transfer_out'], true)) {
            $this->assertTransferWithinOneBranch($row);

            return null;
        }

        if (! in_array($row->type, ['deposit', 'withdrawal', 'closing'], true) || $row->time_deposit_id !== null || $row->interest_run_id !== null) {
            throw CannotPostToTheBooksException::because(
                "{$row->reference} is not a deposit, withdrawal or closing payout of its own, so it posts through its own "
                .'journal (interest, tax, time deposit or reversal). Nothing has been saved.');
        }

        $role = $this->savingsSettlementRole($row->method, $row->reference);
        $map = AccountMap::resolve();
        $posting = $row->type === 'deposit'
            ? PostingRules::savingsDeposit($this->centavos($row->credit, 'credit', $row), $role, $map)
            : PostingRules::savingsWithdrawal($this->centavos($row->debit, 'debit', $row), $role, $map);

        if ($row->type === 'closing') {
            $posting['description'] = 'Savings account closed, balance paid out';
        }

        return $this->savingsWrite($posting, $row, [
            'date' => $row->date,
            'reference' => $row->reference,
            'description' => $this->describe($posting['description'], $row->reference),
            'branch_id' => SavingsAccount::query()->whereKey($row->account_id)->value('branch_id'),
        ], $userId);
    }

    /**
     * Interest credited outside a run (at close), with its tax row if any
     * (source `savings_interest`, keyed on the interest row).
     *
     * @throws CannotPostToTheBooksException
     */
    public function savingsInterestCredit(SavingsTransaction $interest, ?SavingsTransaction $tax, ?int $userId): ?AccountingJournal
    {
        $this->requireBooksForSavings();

        if ($interest->type !== 'interest' || $interest->interest_run_id !== null) {
            throw CannotPostToTheBooksException::because(
                "{$interest->reference} is not interest credited outside an interest run. Nothing has been saved.");
        }

        if ($tax !== null && ($tax->type !== 'withholding_tax' || $tax->account_id !== $interest->account_id)) {
            throw CannotPostToTheBooksException::because(
                "{$tax->reference} is not the tax withheld from {$interest->reference}. Nothing has been saved.");
        }

        $posting = PostingRules::savingsInterest(
            $this->centavos($interest->credit, 'credit', $interest),
            $tax === null ? 0 : $this->centavos($tax->debit, 'debit', $tax),
            AccountMap::resolve(),
        );

        return $this->savingsWrite($posting, $interest, [
            'date' => $interest->date,
            'reference' => $interest->reference,
            'description' => $this->describe($posting['description'], $interest->reference),
            'branch_id' => SavingsAccount::query()->whereKey($interest->account_id)->value('branch_id'),
        ], $userId);
    }

    /**
     * One journal for a posted interest run (source `savings_interest`, keyed
     * on the run), dated the day it posts (`posted_at`, else today), in the
     * run's branch. Call it after the run's interest and tax rows are written
     * (`interest_run_id` = the run): it refuses when their sums differ from the
     * run's totals.
     *
     * @throws CannotPostToTheBooksException when the totals don't reconcile with themselves or the rows, or a role is unmapped
     */
    public function savingsInterestRun(SavingsInterestRun $run, ?int $userId): ?AccountingJournal
    {
        $this->requireBooksForSavings();

        $gross = $this->centavos($run->total_gross, 'total gross interest', $run);
        $tax = $this->centavos($run->total_tax, 'total tax', $run);
        $net = $this->centavos($run->total_net, 'total net interest', $run);

        if ($gross - $tax !== $net) {
            throw CannotPostToTheBooksException::because(
                'This interest run does not reconcile: gross '.Money::format($gross).' less tax '.Money::format($tax)
                .' is not the net of '.Money::format($net).'. Nothing has been saved.');
        }

        // The rows the run wrote on its own branch's accounts of its own
        // product, read in this transaction: the journal posts what the
        // subledger holds, never a total that drifted from it, and never a row
        // that belongs to another branch's or product's books.
        $rows = DB::table('savings_transactions AS t')
            ->join('savings_accounts AS a', 'a.id', '=', 't.account_id')
            ->where('t.interest_run_id', $run->id)
            ->where('a.branch_id', $run->branch_id)
            ->where('a.product_id', $run->product_id)
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'interest' THEN t.credit ELSE 0 END), 0) AS gross")
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'withholding_tax' THEN t.debit ELSE 0 END), 0) AS tax")
            ->first();
        $rowsGross = $this->centavos((string) $rows->gross, 'interest rows', $run);
        $rowsTax = $this->centavos((string) $rows->tax, 'tax rows', $run);

        if ($rowsGross !== $gross || $rowsTax !== $tax) {
            throw CannotPostToTheBooksException::because(
                'This interest run\'s own interest and tax rows come to '.Money::format($rowsGross).' interest and '
                .Money::format($rowsTax).' tax, which does not match its totals of '.Money::format($gross).' and '
                .Money::format($tax).'. Nothing has been saved.');
        }

        $posting = PostingRules::savingsInterest($gross, $tax, AccountMap::resolve());
        $period = $run->period_start->format('M j, Y').' to '.$run->period_end->format('M j, Y');

        return $this->savingsWrite($posting, $run, [
            'date' => $run->posted_at,
            'description' => $this->describe("{$posting['description']}, {$period}", null),
            'branch_id' => $run->branch_id,
        ], $userId);
    }

    /**
     * A time deposit placed (source `time_deposit`, keyed on the placement):
     * from a money account, or from savings deposits when funded from the
     * member's savings (`funding_method` savings).
     *
     * @throws CannotPostToTheBooksException
     */
    public function timeDepositPlacement(TimeDeposit $deposit, ?int $userId): ?AccountingJournal
    {
        $this->requireBooksForSavings();

        if ($deposit->rolled_over_from_id !== null) {
            throw CannotPostToTheBooksException::because(
                "{$deposit->certificate_number} was rolled over from an earlier placement, whose settlement already "
                .'moved the amount rolled over into time deposits. It posts no placement of its own. Nothing has been saved.');
        }

        $role = $deposit->funding_method === 'savings'
            ? PostingRules::SAVINGS_DEPOSITS_ROLE
            : $this->savingsSettlementRole($deposit->funding_method, $deposit->certificate_number);

        $posting = PostingRules::timeDepositPlacement($this->centavos($deposit->principal, 'principal', $deposit), $role, AccountMap::resolve());

        return $this->savingsWrite($posting, $deposit, [
            'date' => $deposit->placement_date,
            'reference' => $deposit->certificate_number,
            'description' => $this->describe($posting['description'], $deposit->certificate_number),
            'branch_id' => $deposit->branch_id,
        ], $userId);
    }

    /**
     * A time deposit settled (source `time_deposit_settlement`, keyed on the
     * placement), from its stored settlement: `settled_interest` (gross),
     * `settled_tax` and `settled_on`. `$rolledOverCentavos` goes into the new
     * placement; the rest is paid out through `$payoutMethod` (`savings` for
     * the member's savings account; null when nothing is paid out) and must
     * equal the stored `settled_amount`, which is the amount PAID OUT (₱0.00
     * on a full rollover). A null `$userId` posts as the system (an automatic
     * rollover).
     *
     * @throws CannotPostToTheBooksException
     */
    public function timeDepositSettlement(TimeDeposit $deposit, int $rolledOverCentavos, ?string $payoutMethod, ?int $userId): ?AccountingJournal
    {
        $this->requireBooksForSavings();

        if ($deposit->settled_on === null) {
            throw CannotPostToTheBooksException::because("{$deposit->certificate_number} has not been settled yet. Nothing has been saved.");
        }

        $principal = $this->centavos($deposit->principal, 'principal', $deposit);
        $gross = $this->centavos($deposit->settled_interest ?? 0, 'settled interest', $deposit);
        $tax = $this->centavos($deposit->settled_tax ?? 0, 'settled tax', $deposit);
        $payoutRole = match (true) {
            $payoutMethod === null => null,
            $payoutMethod === 'savings' => PostingRules::SAVINGS_DEPOSITS_ROLE,
            default => $this->savingsSettlementRole($payoutMethod, $deposit->certificate_number),
        };

        $paidOut = $principal + $gross - $tax - $rolledOverCentavos;
        $settledAmount = $this->centavos($deposit->settled_amount, 'settled amount', $deposit);

        if ($paidOut !== $settledAmount) {
            throw CannotPostToTheBooksException::because(
                "{$deposit->certificate_number}'s settled amount of ".Money::format($settledAmount).' is not what this '
                .'settlement pays out ('.Money::format($paidOut).'). Nothing has been saved.');
        }

        $posting = PostingRules::timeDepositSettlement(
            $principal,
            $gross,
            $tax,
            $rolledOverCentavos,
            $paidOut,
            $payoutRole,
            AccountMap::resolve(),
        );

        return $this->savingsWrite($posting, $deposit, [
            'date' => $deposit->settled_on,
            'reference' => $deposit->certificate_number,
            'description' => $this->describe($posting['description'], $deposit->certificate_number),
            'branch_id' => $deposit->branch_id,
        ], $userId);
    }

    /**
     * {@see self::write()} for savings, refusing a key whose journal was
     * reversed. write() hands back the existing journal for a (postable,
     * source) key, which keeps a retry from posting twice; but a reversed one
     * handed back would let a re-post silently do nothing. A re-post after a
     * reversal is a new subledger row, with its own key. The journal row is
     * read under its lock, so a reversal running at the same moment can't
     * slip between this check and the hand-back.
     *
     * @param  array{source: string, description: string, lines: list<array{account_id: int, debit: int, credit: int}>}  $posting
     * @param  array<string, mixed>  $context
     *
     * @throws CannotPostToTheBooksException
     */
    private function savingsWrite(array $posting, Model $postable, array $context, ?int $userId): AccountingJournal
    {
        $existing = AccountingJournal::query()
            ->where('postable_type', $postable->getMorphClass())
            ->where('postable_id', $postable->getKey())
            ->where('source', $posting['source'])
            ->lockForUpdate()
            ->first(['id', 'journal_no', 'status']);

        if ($existing !== null && $existing->status === 'reversed') {
            $what = $context['reference'] ?? 'This savings record';

            throw CannotPostToTheBooksException::because(
                "{$what} was already posted in journal {$existing->journal_no}, which has been reversed. A reversed "
                .'posting is never posted again: record a new transaction instead. Nothing has been saved.');
        }

        return $this->write($posting, $postable, $context, $userId);
    }

    /**
     * The two legs of a transfer pair one to one (the ledger and a unique
     * index keep a leg from being claimed twice), carry the same amount, and
     * are in one branch, so savings deposits moves within one branch's books
     * and no journal is needed (decision 9).
     *
     * @throws CannotPostToTheBooksException
     */
    private function assertTransferWithinOneBranch(SavingsTransaction $row): void
    {
        $other = $row->counterpart_transaction_id !== null
            ? SavingsTransaction::query()->find($row->counterpart_transaction_id)
            : SavingsTransaction::query()->where('counterpart_transaction_id', $row->id)->first();
        $opposite = $row->type === 'transfer_in' ? 'transfer_out' : 'transfer_in';

        if ($other === null || $other->type !== $opposite) {
            throw CannotPostToTheBooksException::because(
                "{$row->reference} is one leg of a transfer, and its other leg isn't recorded. Nothing has been saved.");
        }

        $out = $row->type === 'transfer_out' ? $row : $other;
        $in = $row->type === 'transfer_in' ? $row : $other;

        if ($this->centavos($out->debit, 'debit', $out) !== $this->centavos($in->credit, 'credit', $in)) {
            throw CannotPostToTheBooksException::because(
                "{$out->reference} and {$in->reference} are the two legs of one transfer, but they don't carry the same amount "
                .'('.Money::format($this->centavos($out->debit, 'debit', $out)).' out and '.Money::format($this->centavos($in->credit, 'credit', $in))
                .' in). Nothing has been saved.');
        }

        $branches = SavingsAccount::query()->whereIn('id', [$row->account_id, $other->account_id])->pluck('branch_id', 'id');

        if ($branches[$row->account_id] !== $branches[$other->account_id]) {
            throw CannotPostToTheBooksException::because(
                "{$row->reference} and {$other->reference} move money between two branches. Savings transfers stay "
                .'within one branch, since each branch keeps its own books. Nothing has been saved.');
        }
    }

    /** @throws CannotPostToTheBooksException when there is no chart of accounts */
    private function requireBooksForSavings(): void
    {
        if (! $this->enabled()) {
            throw CannotPostToTheBooksException::because(
                'Savings needs books, and there is no chart of accounts. Set one up in Accounting → Chart of Accounts. Nothing has been saved.');
        }
    }

    /** @throws CannotPostToTheBooksException */
    private function savingsSettlementRole(?string $method, ?string $reference): string
    {
        $role = self::SAVINGS_SETTLEMENT_ROLES[$method ?? ''] ?? null;

        if ($role === null) {
            throw CannotPostToTheBooksException::because(
                ($reference ?? 'This savings transaction').' has no payment method that settles through a money account, so it cannot be posted to the books. Nothing has been saved.');
        }

        return $role;
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
        foreach (['loan_account_number', 'receipt_number', 'application_number', 'reference_no', 'adjustment_number', 'reference'] as $column) {
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
