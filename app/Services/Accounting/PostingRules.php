<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Exceptions\CannotPostToTheBooksException;
use App\Services\FeeOverlapDetector;

/**
 * The automatic posting engine's rule set. A lending event goes in, a balanced
 * journal comes out.
 *
 * The PHP half of `src/lib/accounting/posting-rules.ts`, and the AUTHORITATIVE
 * half: the frontend's copy previews the entry an action will produce, this one
 * writes it. Both must agree, and where they currently do not, see
 * "Two deliberate divergences" below — the frontend is the side that needs
 * changing.
 *
 * ## Pure, and deliberately so
 *
 * Nothing here touches the database, the clock, or the request. A rule takes
 * integer centavos and a resolved {@see AccountMap} and returns line data. That
 * is what lets every rule be unit-tested against a literal mapping, and it is
 * why {@see AutomaticPoster} — not this class — owns the peso/centavo boundary
 * and the call into {@see JournalPoster}.
 *
 * ## Money is INTEGER CENTAVOS here, always
 *
 * The lending tables store pesos as `decimal:2`; accounting stores centavos as
 * `bigInteger`. Every rule in this file crosses that boundary, and it is the
 * single likeliest place in the module to introduce a 100x error. So the
 * boundary is not in this file at all: `declare(strict_types=1)` plus `int`
 * parameters mean a peso float cannot even be passed in — it is a TypeError,
 * not a silently truncated amount. Conversion happens once, explicitly, in
 * {@see AutomaticPoster::centavos()}.
 *
 * ## Two deliberate divergences from `posting-rules.ts`
 *
 * 1. **`loanRelease()` posts the NET disbursement to cash, not the gross.**
 *    The frontend rule debits loans receivable and credits cash with the SAME
 *    figure, which is only correct where nothing is withheld at release. This
 *    backend withholds: `LoanService::computeDeductions()` subtracts processing,
 *    service and notarial fees from the principal, and
 *    `LoanService::applyInsuranceOnRelease()` withholds the insurance premium on
 *    top. Only `net_proceeds` leaves the drawer. Crediting cash with the gross
 *    overstates it by every peso withheld AND omits the fee income entirely —
 *    an entry that balances, posts, and misstates both the balance sheet and
 *    the income statement. The withheld part is then booked ITEM BY ITEM, each
 *    deduction to the account mapped for its type ({@see self::DEDUCTION_ROLES}),
 *    with anything that has no mapping booked where all of it went before. See
 *    {@see self::loanRelease()}.
 *
 * 2. **`loanCollection()` debits what was actually RECEIVED.** The frontend's
 *    `PaymentAllocation` has no overpayment field, so its rule debits cash with
 *    `principal + interest + penalty + fees`. `repayments.amount_paid` is not
 *    bounded by what is owed — `StoreRepaymentRequest` allows any amount over
 *    ₱0.01 — and the excess is stored as `repayments.overpayment`. Debiting
 *    only the allocated part understates the drawer by the overpayment. The
 *    excess is money the organisation is holding and has not earned, so it is
 *    credited to a liability. See {@see self::loanCollection()}.
 *
 * Both are gaps in the frontend rule, not here. The handoff carries them.
 */
final class PostingRules
{
    /**
     * The role an overpayment is credited to.
     *
     * NOT `accounts_payable`: that role is a trade payable, owned by the
     * expenses side of this module, and money held on a borrower's account is a
     * different obligation to a different party. Mapped to 2300 Other
     * Liabilities by default — an account the seeded chart already carries, so
     * this adds a role without adding a chart row the frontend's
     * `DEFAULT_CHART_OF_ACCOUNTS` constant would then be missing.
     */
    public const BORROWER_ADVANCES = 'borrower_advances';

    /**
     * A release deduction's TYPE => the role it is credited to.
     *
     * The type is the item's `name`, normalised by
     * {@see FeeOverlapDetector::normalize()} — the rule the fee guards and the
     * Income report already use, so "PROCESSING-FEE." is a processing fee here
     * exactly as it is there, and "Processing Fee Waiver" is not.
     *
     * ## Confirmed by the cooperative's accountant on 2026-10-03
     *
     * Each type the release path writes has a role of its own, and the role is
     * resolved through Settings → Default Accounts like every other, never by
     * an account's code or name:
     *
     * - processing fee    → processing_fee_income     (4030 by default)
     * - service fee       → service_fee_income        (4040 Service Fee Income)
     * - notarial fee      → notarial_fees_payable     (2030, a liability: owed to the notary)
     * - insurance premium → insurance_premium_payable (2040, a liability: owed to the insurer)
     * - share capital     → share_capital             (3060, the members' equity)
     *
     * A fee from the Settings fee catalog whose name is none of these goes to
     * {@see self::CATALOG_FEE_ROLE}. Anything else is booked as before — see
     * {@see self::UNMAPPED_DEDUCTION_ROLE}. Only journals posted from then on
     * use these roles; a posted journal is never rewritten.
     *
     * @var array<string, string>
     */
    public const DEDUCTION_ROLES = [
        'processing fee' => 'processing_fee_income',
        'service fee' => 'service_fee_income',
        'notarial fee' => 'notarial_fees_payable',
        'insurance premium' => 'insurance_premium_payable',
        'share capital' => 'share_capital',
    ];

    /**
     * Where a fee from the Settings fee catalog (LoanReleaseFeeService, an item
     * carrying `fee_id`) is credited when its name is no type in
     * {@see self::DEDUCTION_ROLES} — a Credit Investigation Fee, say. 4080
     * Other Fee Income by default (accountant-confirmed 2026-10-03).
     */
    public const CATALOG_FEE_ROLE = 'other_fee_income';

    /**
     * Where a deduction with no entry in {@see self::DEDUCTION_ROLES} is
     * credited, together with any part of `total_deductions` no item explains.
     *
     * The role ALL withheld deductions were credited to before items were
     * booked by type, kept for whatever still has no type: a hand-typed
     * deduction such as "Documentary Stamp", and an imported loan's total
     * with no item list.
     */
    public const UNMAPPED_DEDUCTION_ROLE = 'processing_fee_income';

    /**
     * A loan release: money leaves a wallet and becomes an amount owed.
     *
     * ## The gross/net split, which is the whole point of this rule
     *
     * The borrower owes the FULL principal from day one — the amortisation
     * schedule is built on `principal_amount`, not on what they walked out
     * with. So loans receivable is debited with the gross.
     *
     * What actually left the drawer is `net_proceeds`. The difference is the
     * deductions the organisation withheld and kept, which are its income. Post
     * the gross to cash instead and two statements are wrong at once: cash is
     * overstated by the deductions, and the fee income never appears at all.
     * The entry still balances, because both sides moved by the same amount —
     * which is exactly why nothing downstream would ever catch it.
     *
     *     gross = net + deductions        (asserted, not assumed)
     *
     * The assertion is not ceremony. `net_proceeds` is maintained by repeated
     * float subtraction in pesos across `computeDeductions()` and
     * `applyInsuranceOnRelease()`, and a CSV-imported or hand-edited loan need
     * not satisfy it at all. A gross that disagrees with its own parts would
     * produce an unbalanced entry; better to say which three numbers disagree
     * than to let JournalPoster report a difference in debits and credits.
     *
     * ## The deductions, booked by type
     *
     * `$items` is the loan's `deductions` list, one `{name, amount}` per
     * withholding: the product's processing, service and notarial fees from
     * `LoanService::createLoan()`, catalog fees from `LoanReleaseFeeService`,
     * the "Insurance Premium" from `applyInsuranceOnRelease()`. Each is
     * credited to the role its type maps to in {@see self::DEDUCTION_ROLES},
     * or a catalog fee of no such type to {@see self::CATALOG_FEE_ROLE};
     * everything else — an untyped deduction, and whatever part of
     * `$deductions` no item explains (an imported loan carries a total and no
     * list) — is credited to {@see self::UNMAPPED_DEDUCTION_ROLE}, exactly as
     * the whole total was before. See {@see self::classifyDeductions()}.
     *
     * One credit line per ROLE, not per item: items that resolve to the same
     * role are summed.
     *
     * ## No new refusals for what the old rule posted
     *
     * The total stays the authority on what was withheld, and the item list is
     * only trusted where it has to be: for a type with an account of its own.
     * So the only new refusals are a MAPPED item whose amount cannot be booked
     * (missing, non-numeric, negative, or past the ceiling) and mapped
     * items that add up to more than the total — booking either would credit
     * that account with money the loan's own figures do not show was kept.
     * Anything unusable on an UNMAPPED type, or an entry that is not an item at
     * all, is set aside: its money is already in the remainder, which is
     * booked exactly as before.
     *
     * @param  int  $gross  `loans.principal_amount`, in centavos
     * @param  int  $net  `loans.net_proceeds` — what was handed over
     * @param  int  $deductions  `loans.total_deductions` — what was withheld
     * @param  array<array-key, mixed>  $items  `loans.deductions` as a list, each usable amount in centavos:
     *                                          list<array{name: string, amount: int|null, catalog_fee?: bool}|mixed>
     * @param  string  $loan  what to call the loan in a refusal ("LA-000154")
     *
     * @throws CannotPostToTheBooksException when the three figures do not reconcile, or a mapped item cannot be booked
     */
    public static function loanRelease(
        int $gross,
        int $net,
        int $deductions,
        string $method,
        AccountMap $map,
        array $items = [],
        string $loan = 'This loan',
    ): array {
        self::requireAmount($gross, 'A loan release');
        self::requireComponent($net, 'The net proceeds');
        self::requireComponent($deductions, 'The total deductions');

        if ($net + $deductions !== $gross) {
            throw CannotPostToTheBooksException::because(
                'This loan does not reconcile: '.Money::format($net).' disbursed plus '
                .Money::format($deductions).' withheld is '.Money::format($net + $deductions)
                .', but the principal is '.Money::format($gross).'. The release has not been posted — an '
                .'entry built from figures that disagree would either fail to balance or misstate the '
                .'portfolio by the difference.');
        }

        $lines = [
            self::debitIfAny($map, 'loans_receivable', $gross),
            self::creditIfAny($map, SettlementMethod::assertIsSettlementRole($method), $net),
        ];

        // Withheld at release and kept: income, recognised now, one line per
        // role. A role whose share is zero emits no line and is not resolved
        // at all, so a product with no fees still releases on a chart that has
        // never mapped processing fee income.
        foreach (self::classifyDeductions($deductions, $items, $loan)['credits'] as $role => $amount) {
            $lines[] = self::creditIfAny($map, $role, $amount);
        }

        return [
            'source' => 'loan_release',
            'description' => 'Loan release',
            'lines' => self::used($lines),
        ];
    }

    /**
     * A release's deductions sorted by type, and the credit each role takes.
     *
     * Pure, like every rule here, so the release rule, the
     * `accounting:loan-release-diff` preview and the tests all read one answer:
     *
     * - `types` — every type with a usable item (normalised name), its summed
     *   amount, and the role it maps to, or null when it has none;
     * - `mapped` — role => the summed items whose type maps to it;
     * - `unmapped` — type => the summed usable items whose type maps to nothing;
     * - `unusable` — the entries set aside, each with its type ('' when it has
     *   no name, or is not an item at all) and why: `not an item`,
     *   `no usable amount`, `negative amount`, or `amount past the ceiling`.
     *   Only unmapped types ever land here; a mapped one is refused instead;
     * - `remainder` — `$deductions` minus every usable item, signed: the part
     *   of the total no item explains (negative when the items add up to more);
     * - `credits` — role => what the release credits it, in line order: the
     *   mapped items in {@see self::DEDUCTION_ROLES} order, then catalog fees
     *   on {@see self::CATALOG_FEE_ROLE}, then `$deductions` minus them on
     *   {@see self::UNMAPPED_DEDUCTION_ROLE}. Zero shares are left out.
     *
     * An item with `catalog_fee` true came from the Settings fee catalog; its
     * name still decides first, so a catalog fee called "Service Fee" is a
     * service fee.
     *
     * @param  int  $deductions  `loans.total_deductions`, in centavos
     * @param  array<array-key, mixed>  $items  list<array{name: string, amount: int|null, catalog_fee?: bool}|mixed>, amounts in centavos
     * @param  string  $loan  what to call the loan in a refusal
     * @return array{
     *     types: array<string, array{amount: int, role: string|null}>,
     *     mapped: array<string, int>,
     *     unmapped: array<string, int>,
     *     unusable: list<array{type: string, reason: string}>,
     *     remainder: int,
     *     credits: array<string, int>,
     * }
     *
     * @throws CannotPostToTheBooksException for a mapped item whose amount cannot be booked,
     *                                       or mapped items that exceed `$deductions`
     */
    public static function classifyDeductions(int $deductions, array $items, string $loan = 'This loan'): array
    {
        self::requireComponent($deductions, 'The total deductions');

        $types = [];
        $mapped = [];
        $unmapped = [];
        $unusable = [];
        $itemised = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                $unusable[] = ['type' => '', 'reason' => 'not an item'];

                continue;
            }

            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $type = self::deductionType($name);
            $role = self::DEDUCTION_ROLES[$type] ?? (($item['catalog_fee'] ?? false) === true ? self::CATALOG_FEE_ROLE : null);
            $amount = $item['amount'] ?? null;
            $problem = self::unusableAmount($amount);

            if ($problem !== null) {
                if ($role !== null) {
                    throw self::unbookableMappedItem($loan, $name, $amount, $problem);
                }

                $unusable[] = ['type' => $type, 'reason' => $problem];

                continue;
            }

            $types[$type] = ['amount' => ($types[$type]['amount'] ?? 0) + $amount, 'role' => $role];

            if ($role === null) {
                $unmapped[$type] = ($unmapped[$type] ?? 0) + $amount;
            } else {
                $mapped[$role] = ($mapped[$role] ?? 0) + $amount;
            }

            $itemised += $amount;
        }

        $mappedTotal = array_sum($mapped);

        if ($mappedTotal > $deductions) {
            throw CannotPostToTheBooksException::because(
                "{$loan} does not reconcile: its deductions with an account of their own come to "
                .Money::format($mappedTotal).', but it withheld '.Money::format($deductions)
                .' in total. The release has not been posted — booking those items where they belong would '
                .'credit more than was withheld, and trimming one to fit would be choosing which figure is wrong.');
        }

        $credits = [];

        foreach ([...array_unique(self::DEDUCTION_ROLES), self::CATALOG_FEE_ROLE] as $role) {
            if (($mapped[$role] ?? 0) > 0) {
                $credits[$role] = $mapped[$role];
            }
        }

        $unexplained = $deductions - $mappedTotal;

        if ($unexplained > 0) {
            $credits[self::UNMAPPED_DEDUCTION_ROLE] = ($credits[self::UNMAPPED_DEDUCTION_ROLE] ?? 0) + $unexplained;
        }

        return [
            'types' => $types,
            'mapped' => $mapped,
            'unmapped' => $unmapped,
            'unusable' => $unusable,
            'remainder' => $deductions - $itemised,
            'credits' => $credits,
        ];
    }

    /**
     * Why an item amount cannot be booked, or null when it is whole,
     * non-negative centavos within the module's ceiling.
     */
    private static function unusableAmount(mixed $amount): ?string
    {
        return match (true) {
            ! is_int($amount) => 'no usable amount',
            $amount < 0 => 'negative amount',
            $amount > Money::maxCentavos() => 'amount past the ceiling',
            default => null,
        };
    }

    /** The refusal for an item with an account of its own that cannot be booked to it. */
    private static function unbookableMappedItem(string $loan, string $name, mixed $amount, string $problem): CannotPostToTheBooksException
    {
        $what = match ($problem) {
            'negative amount' => 'is '.Money::format($amount).'. A deduction is money withheld and cannot be '
                .'negative — direction belongs to the column, not the sign.',
            'amount past the ceiling' => 'is '.Money::format($amount).', which is beyond any amount this system '
                .'records exactly.',
            default => 'has no usable amount, and reading it as zero would book that fee as something else.',
        };

        return CannotPostToTheBooksException::because(
            "{$loan} cannot be posted: its deduction \"{$name}\" {$what} The release has not been posted.");
    }

    /** A deduction's type: its name, normalised as {@see FeeOverlapDetector::normalize()} does. */
    public static function deductionType(string $name): string
    {
        return (new FeeOverlapDetector)->normalize($name);
    }

    /** The role a deduction named `$name` is credited to, or null when its type has none. */
    public static function deductionRoleFor(string $name): ?string
    {
        return self::DEDUCTION_ROLES[self::deductionType($name)] ?? null;
    }

    /**
     * A repayment.
     *
     * The split comes FROM the loan engine and is never inferred here.
     * Principal/interest allocation is an amortisation decision, and if
     * accounting re-derived it the ledger would disagree with the loan balance
     * the moment either side changed its rounding.
     *
     * Each component is validated on its own terms BEFORE the total, because
     * validating only the sum is not validation: `{principal: 300, interest:
     * -100}` sums to a clean 200 and posts a 200 debit against a 300 credit and
     * a -100 credit, which balances arithmetically and is nonsense as
     * bookkeeping. This mirrors `requireComponent` in the frontend module, which
     * exists for the same reason.
     *
     * Penalty is credited to `penalty_income` HERE, at collection, because this
     * organisation keeps penalties on a CASH basis — penalty accrual writes no
     * journal, and `RepaymentService::applyPenalties()` is deliberately left
     * alone. There is no separate accrual event and none should be added
     * without also adding the reversing entry that a cash-basis book does not
     * need.
     *
     * @param  int  $received  `repayments.amount_paid` — every peso that arrived
     * @param  int  $overpayment  the part of it that settled nothing
     */
    public static function loanCollection(
        int $received,
        int $principal,
        int $interest,
        int $penalty,
        int $fees,
        int $overpayment,
        string $method,
        AccountMap $map,
    ): array {
        self::requireComponent($principal, 'The principal component');
        self::requireComponent($interest, 'The interest component');
        self::requireComponent($penalty, 'The penalty component');
        self::requireComponent($fees, 'The fees component');
        self::requireComponent($overpayment, 'The overpayment');
        self::requireAmount($received, 'A collection');

        $allocated = $principal + $interest + $penalty + $fees;

        if ($allocated + $overpayment !== $received) {
            throw CannotPostToTheBooksException::because(
                'This payment does not reconcile: '.Money::format($allocated).' allocated plus '
                .Money::format($overpayment).' unallocated is '.Money::format($allocated + $overpayment)
                .', but '.Money::format($received).' was received. The payment has not been posted.');
        }

        return [
            'source' => 'loan_collection',
            'description' => 'Loan collection',
            'lines' => self::used([
                self::debitIfAny($map, SettlementMethod::assertIsSettlementRole($method), $received),
                self::creditIfAny($map, 'loans_receivable', $principal),
                self::creditIfAny($map, 'interest_income', $interest),
                self::creditIfAny($map, 'penalty_income', $penalty),
                self::creditIfAny($map, 'processing_fee_income', $fees),
                // Held, not earned. See self::BORROWER_ADVANCES.
                self::creditIfAny($map, self::BORROWER_ADVANCES, $overpayment),
            ]),
        ];
    }

    /**
     * Company money moving between its own accounts.
     *
     * Emphatically NOT income — treating a GCash-to-bank sweep as revenue would
     * inflate the income statement by the entire amount swept, which is the
     * single most damaging mistake a naive implementation makes.
     */
    public static function fundTransfer(int $amount, string $from, string $to, AccountMap $map): array
    {
        self::requireAmount($amount, 'A transfer');
        SettlementMethod::assertIsSettlementRole($from);
        SettlementMethod::assertIsSettlementRole($to);

        if ($from === $to) {
            throw CannotPostToTheBooksException::because('A transfer cannot move money into the same account.');
        }

        return [
            'source' => 'transfer',
            'description' => "Fund transfer from {$from} to {$to}",
            'lines' => [
                self::debit($map->accountFor($to), $amount),
                self::credit($map->accountFor($from), $amount),
            ],
        ];
    }

    /** A wallet's own fee — an expense, paid out of that wallet. */
    public static function walletCharge(
        int $amount,
        string $method,
        int $expenseAccountId,
        AccountMap $map,
    ): array {
        self::requireAmount($amount, 'A charge');

        return [
            'source' => 'gcash',
            'description' => 'Wallet service charge',
            'lines' => [
                self::debit($expenseAccountId, $amount),
                self::credit($map->accountFor(SettlementMethod::assertIsSettlementRole($method)), $amount),
            ],
        ];
    }

    /**
     * An expense paid on the spot.
     *
     * A RULE ONLY. The expenses tables, endpoints and screens belong to a
     * separate stream; this exists so both streams post through one rule set
     * rather than two, and so the shape is settled before the tables land.
     *
     * ## `$expenseAccountId` is NOT validated here, and must be by the caller
     *
     * Every other rule in this file names a role and resolves it through
     * {@see AccountMap}, so the account is one an administrator curated. This
     * one takes an id straight from its caller, because which expense a cost
     * belongs to is an operator's choice rather than a fixed role.
     *
     * This class is pure and cannot query, so the type check has to sit in
     * front of it: call
     * {@see AutomaticPoster::assertIsPostableExpenseAccount()} first.
     * {@see JournalPoster} will still refuse an inactive account or a group
     * heading, so the gap left open is TYPE — an expense debited to equity, or
     * to the credit-loss allowance, posts and balances and is simply on the
     * wrong statement. Nothing downstream reports that.
     */
    public static function expenseCash(int $amount, int $expenseAccountId, string $method, AccountMap $map): array
    {
        self::requireAmount($amount, 'An expense');

        return [
            'source' => 'expense',
            'description' => 'Expense paid',
            'lines' => [
                self::debit($expenseAccountId, $amount),
                self::credit($map->accountFor(SettlementMethod::assertIsSettlementRole($method)), $amount),
            ],
        ];
    }

    /**
     * Cost recognised now, cash paid later — the liability is booked today.
     *
     * A rule only, and `$expenseAccountId` is unvalidated here for the same
     * reason and with the same obligation on the caller; see
     * {@see self::expenseCash()}.
     */
    public static function expenseAccrual(int $amount, int $expenseAccountId, AccountMap $map): array
    {
        self::requireAmount($amount, 'An expense');

        return [
            'source' => 'payable',
            'description' => 'Expense accrued',
            'lines' => [
                self::debit($expenseAccountId, $amount),
                self::credit($map->accountFor('accounts_payable'), $amount),
            ],
        ];
    }

    /**
     * Settling that liability later. No expense — it was booked on accrual.
     * A rule only; see {@see self::expenseCash()}.
     */
    public static function payablePayment(int $amount, string $method, AccountMap $map): array
    {
        self::requireAmount($amount, 'A payment');

        return [
            'source' => 'payable',
            'description' => 'Payable settled',
            'lines' => [
                self::debit($map->accountFor('accounts_payable'), $amount),
                self::credit($map->accountFor(SettlementMethod::assertIsSettlementRole($method)), $amount),
            ],
        ];
    }

    /**
     * A processing fee collected separately from a repayment and from a release.
     *
     * Recognised immediately. Under IFRS 9 a fee integral to originating a
     * financial asset forms part of its effective interest rate and is amortised
     * over the loan's life rather than taken up front — which is why fee
     * treatment is a configurable setting rather than a hard-coded rule. This is
     * the simple, and by far the more common, treatment for the organisations
     * this serves.
     */
    public static function loanFee(int $amount, string $method, AccountMap $map): array
    {
        self::requireAmount($amount, 'A fee');

        return [
            'source' => 'loan_fee',
            'description' => 'Loan processing fee',
            'lines' => [
                self::debit($map->accountFor(SettlementMethod::assertIsSettlementRole($method)), $amount),
                self::credit($map->accountFor('processing_fee_income'), $amount),
            ],
        ];
    }

    /**
     * Recognising expected losses before any borrower has actually defaulted.
     *
     * The allowance is a contra-asset, so crediting it REDUCES net loans
     * receivable without touching the gross figure the loan module reports —
     * which is what keeps the portfolio the loans screen shows and the
     * portfolio the balance sheet shows reconcilable rather than merely similar.
     */
    public static function creditLossProvision(int $amount, AccountMap $map): array
    {
        self::requireAmount($amount, 'A provision');

        return [
            'source' => 'credit_loss',
            'description' => 'Credit loss provision',
            'lines' => [
                self::debit($map->accountFor('credit_loss_expense'), $amount),
                self::credit($map->accountFor('allowance_credit_losses'), $amount),
            ],
        ];
    }

    /**
     * Guards an amount that must exist.
     *
     * `int` in a strict-types file already refuses a fraction, so what is left
     * to check is the sign and the module's exact-arithmetic ceiling.
     * {@see JournalPoster} re-checks the ceiling against the persisted rows,
     * which is the check that counts; this one names the event rather than a
     * line number, which is what an operator can act on.
     */
    private static function requireAmount(int $amount, string $what): int
    {
        if ($amount <= 0) {
            throw CannotPostToTheBooksException::because("{$what} must be greater than zero.");
        }

        return self::requireWithinBounds($amount, $what);
    }

    /**
     * One PART of an amount split across several lines.
     *
     * Distinct from {@see self::requireAmount()} because zero is legitimate here
     * and nowhere else: a collection with no penalty component is ordinary, and
     * {@see self::used()} drops the empty line further down. A NEGATIVE
     * component is not ordinary and is refused — direction belongs to the
     * column, not the sign.
     */
    private static function requireComponent(int $amount, string $what): int
    {
        if ($amount < 0) {
            throw CannotPostToTheBooksException::because("{$what} cannot be negative.");
        }

        return self::requireWithinBounds($amount, $what);
    }

    private static function requireWithinBounds(int $amount, string $what): int
    {
        if ($amount > Money::maxCentavos()) {
            throw CannotPostToTheBooksException::because(
                "{$what} is ".Money::format($amount).', which is beyond any amount this system records '
                .'exactly. An amount that size is a typo or a unit error, not a balance.');
        }

        return $amount;
    }

    /**
     * A leg that only resolves its role when it has an amount.
     *
     * ## Why this is not just `used()` with extra steps
     *
     * PHP evaluates every element of an array literal BEFORE the array exists,
     * so `used([... credit($map->accountFor('penalty_income'), 0) ...])`
     * resolves `penalty_income` and only then drops the zero line. The role is
     * required to be mapped for a line that is never emitted.
     *
     * That turned a narrow requirement into a broad one, invisibly. A
     * collection with no penalty, no fees and no overpayment — the ordinary
     * shape — demanded `penalty_income`, `processing_fee_income` AND
     * `borrower_advances` anyway, so a deployment that adopted accounting
     * before `borrower_advances` existed would have had EVERY repayment
     * refused, not just the overpaying ones. Same for a zero-fee release and
     * `processing_fee_income`.
     *
     * Resolving lazily means the set of roles a posting REQUIRES is exactly the
     * set it USES, which is the only version of fail-closed that is honest: it
     * refuses what it genuinely cannot record, and nothing else.
     *
     * @return array{account_id: int, debit: int, credit: int}|null
     */
    private static function debitIfAny(AccountMap $map, string $role, int $amount): ?array
    {
        return $amount === 0 ? null : self::debit($map->accountFor($role), $amount);
    }

    /** The credit half of {@see self::debitIfAny()}. */
    private static function creditIfAny(AccountMap $map, string $role, int $amount): ?array
    {
        return $amount === 0 ? null : self::credit($map->accountFor($role), $amount);
    }

    /** @return array{account_id: int, debit: int, credit: int} */
    private static function debit(int $accountId, int $amount): array
    {
        return ['account_id' => $accountId, 'debit' => $amount, 'credit' => 0];
    }

    /** @return array{account_id: int, debit: int, credit: int} */
    private static function credit(int $accountId, int $amount): array
    {
        return ['account_id' => $accountId, 'debit' => 0, 'credit' => $amount];
    }

    /**
     * Drops zero legs, so a payment without penalty emits no penalty line and a
     * release with no deductions emits no fee line.
     *
     * `array_values` because the result is a list handed to
     * {@see JournalPoster::draft()}, which numbers lines by iteration order —
     * a filtered array with holes would still iterate correctly, but every
     * caller and every test then has to know that.
     *
     * @param  list<array{account_id: int, debit: int, credit: int}|null>  $lines
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    private static function used(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (?array $line): bool => $line !== null && ($line['debit'] !== 0 || $line['credit'] !== 0),
        ));
    }
}
