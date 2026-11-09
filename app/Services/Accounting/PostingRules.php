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
 *
 * ## Phase 2 rules (approved 2026-11-08; new records only)
 *
 * - {@see self::restructureSplit()}, {@see self::restructureRelease()} (J1)
 *   and {@see self::restructureWriteOff()} (J2): a restructure into a new
 *   loan pays no principal out; capitalised interest and penalty wait on
 *   1210 and {@see self::deferralRecognition()} recognises them as the new
 *   loan's principal is collected, inside {@see self::loanCollection()}.
 * - {@see self::loanCollection()} credits a Share Capital Build-Up to 3060.
 * - {@see self::balanceAdjustment()}, {@see self::shareCapitalEntry()},
 *   {@see self::gcashTransaction()} and {@see self::allowanceAdjustment()}.
 *
 * Cash basis throughout (decision 1): a waiver or a deferred extension
 * interest posts nothing.
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
     * A tax goes to its payable role, {@see self::DOCUMENTARY_STAMP_TAX_ROLE}
     * or {@see self::OTHER_TAXES_ROLE} (decision 4b, 2026-11-02; see
     * {@see self::taxRoleFor()}). A fee from the Settings fee catalog whose
     * name is none of these goes to {@see self::CATALOG_FEE_ROLE}. Anything
     * else is booked as before — see {@see self::UNMAPPED_DEDUCTION_ROLE}. Only journals posted from then on
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
     * Where a Documentary Stamp Tax withheld at release is credited: 2230
     * Documentary Stamp Tax Payable by default. A tax is a government levy the
     * cooperative collects and owes on, never its own income (decision 4b,
     * 2026-11-02).
     */
    public const DOCUMENTARY_STAMP_TAX_ROLE = 'documentary_stamp_tax_payable';

    /** Where a withholding tax withheld at release is credited: 2210 Withholding Tax Payable by default. */
    public const WITHHOLDING_TAX_ROLE = 'withholding_tax_payable';

    /**
     * Where any other tax withheld at release is credited: 2220 Other Taxes
     * Payable by default. Also where a Documentary Stamp Tax or a withholding
     * tax goes when the chart has no account for its own role, so it stays a
     * liability ({@see self::classifyDeductions()}).
     */
    public const OTHER_TAXES_ROLE = 'other_taxes_payable';

    /**
     * What makes a deduction's type a tax, tried in this order on the type
     * ({@see self::deductionType()}: lower case, words split by single spaces),
     * each on whole words. The single matcher both the books and the
     * disclosure (DisclosureService::kindOf()) use (decided 2026-11-02).
     *
     * @var array<string, string> pattern => role
     */
    private const TAX_PATTERNS = [
        '/\\b(d ?s ?t|doc(umentary)? stamps?)\\b/' => self::DOCUMENTARY_STAMP_TAX_ROLE,
        '/\\b(withholding tax(es)?|ewt|cwt)\\b/' => self::WITHHOLDING_TAX_ROLE,
        '/\\btax(es)?\\b/' => self::OTHER_TAXES_ROLE,
    ];

    /**
     * A type that reads like a tax and is not one: a tax clearance fee is a
     * fee charged for a certificate, not a levy, and a "tax-exempt" or
     * "non-tax" charge says outright that it is no tax. Hyphens are spaces by
     * the time the type is read.
     */
    private const NOT_A_TAX_PATTERN = '/\\b(tax clearance|tax exempt|non tax)\\b/';

    /**
     * Where a deduction with no entry in {@see self::DEDUCTION_ROLES} is
     * credited, together with any part of `total_deductions` no item explains.
     *
     * The role ALL withheld deductions were credited to before items were
     * booked by type, kept for whatever still has no type: a hand-typed
     * deduction such as "Inspection Fee", and an imported loan's total with no
     * item list.
     */
    public const UNMAPPED_DEDUCTION_ROLE = 'processing_fee_income';

    /**
     * Where a restructure puts the interest and penalty it capitalised into
     * the new loan's principal: 1210 Unearned Interest and Penalty –
     * Restructured Loans by default, a contra-asset beside the allowance.
     * Cash basis (Phase 2, decision 1): it is income only as the new loan's
     * principal is collected ({@see self::deferralRecognition()}).
     */
    public const UNEARNED_RESTRUCTURE_ROLE = 'unearned_restructure_income';

    /** Where a balance adjustment clears: 1180 Loan Adjustments Clearing by default (decision 7). */
    public const LOAN_ADJUSTMENTS_ROLE = 'loan_adjustments';

    /** Where a GCash service charge is earned: 4090 GCash Service Income by default (decision 6). */
    public const GCASH_SERVICE_INCOME_ROLE = 'gcash_service_income';

    /**
     * Line descriptions that mark every movement on the unearned restructure
     * account, so what a loan's deferral has done can be read back from its
     * own journals whatever account the role points at
     * (AutomaticPoster::unrecognisedDeferral()). A reversal copies them, so a
     * voided collection's recognition is undone line for line.
     *
     * - {@see self::DEFERRAL_RECOGNISED_LINE}: the debit, in a collection;
     * - {@see self::DEFERRED_INTEREST_LINE} and {@see self::DEFERRED_PENALTY_LINE}:
     *   the income it becomes, in the same collection;
     * - {@see self::DEFERRAL_WRITTEN_OFF_LINE}: taken against a write-off (J2);
     * - {@see self::DEFERRAL_CLEARED_LINE}: cleared by a balance reduction
     *   that completed the loan;
     * - {@see self::DEFERRED_INTEREST_CLEARED_LINE} and
     *   {@see self::DEFERRED_PENALTY_CLEARED_LINE}: a partial reduction's
     *   share, by part ({@see self::deferralShare()});
     * - {@see self::DEFERRED_INTEREST_CARRIED_OUT_LINE} and
     *   {@see self::DEFERRED_PENALTY_CARRIED_OUT_LINE}: the source's deferral
     *   carried off it, by part, in the new loan's J1
     *   ({@see self::restructureCarry()});
     * - {@see self::DEFERRED_INTEREST_CARRIED_IN_LINE} and
     *   {@see self::DEFERRED_PENALTY_CARRIED_IN_LINE}: the same amounts
     *   carried onto the new loan, in the same J1.
     *
     * NEVER REWORD THESE. Posted journals are immutable and carry these exact
     * strings; a changed constant would stop finding them, and the next
     * collection would recognise the same deferral a second time.
     */
    public const DEFERRAL_RECOGNISED_LINE = 'Unearned interest and penalty recognised';

    public const DEFERRED_INTEREST_LINE = 'Restructured interest recognised';

    public const DEFERRED_PENALTY_LINE = 'Restructured penalty recognised';

    public const DEFERRAL_WRITTEN_OFF_LINE = 'Unearned interest and penalty written off';

    public const DEFERRAL_CLEARED_LINE = 'Unearned interest and penalty cleared';

    public const DEFERRED_INTEREST_CLEARED_LINE = 'Unearned interest cleared';

    public const DEFERRED_PENALTY_CLEARED_LINE = 'Unearned penalty cleared';

    public const DEFERRED_INTEREST_CARRIED_OUT_LINE = 'Unearned interest carried to the new loan';

    public const DEFERRED_PENALTY_CARRIED_OUT_LINE = 'Unearned penalty carried to the new loan';

    public const DEFERRED_INTEREST_CARRIED_IN_LINE = 'Unearned interest carried from the source loan';

    public const DEFERRED_PENALTY_CARRIED_IN_LINE = 'Unearned penalty carried from the source loan';

    /**
     * The other line descriptions Phase 2's rules write, which the ledger
     * gaps report reads to tell an event posted the new way. Like the markers
     * above, never reword them: posted journals carry the exact text.
     *
     * - {@see self::BUILD_UP_LINE}: the share capital build-up a collection credits;
     * - {@see self::carriedPrincipalLine()}: J1's carried principal, with the source's number;
     * - {@see self::capitalisedLine()}: J1's capitalised interest and penalty, with the source's number.
     */
    public const BUILD_UP_LINE = 'Share capital build-up';

    public const CARRIED_PRINCIPAL_LINE_PREFIX = 'Principal carried from ';

    public const CAPITALISED_LINE_PREFIX = 'Interest and penalty capitalised from ';

    /** J1's carried principal line, for the loan it was carried from. */
    public static function carriedPrincipalLine(string $source): string
    {
        return self::CARRIED_PRINCIPAL_LINE_PREFIX.$source;
    }

    /** J1's capitalised interest and penalty line, for the loan they were capitalised from. */
    public static function capitalisedLine(string $source): string
    {
        return self::CAPITALISED_LINE_PREFIX.$source;
    }

    /** The two GCash transaction types and the journal source each posts as. */
    public const GCASH_TYPES = ['cash_in', 'cash_out'];

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
     * A tax (see {@see self::taxRoleFor()}) is credited to its payable role,
     * hand-typed or from the catalog. When `$map` has no account for that
     * role, a Documentary Stamp Tax or withholding tax goes to
     * {@see self::OTHER_TAXES_ROLE} if that has one, so it stays a liability;
     * otherwise the tax is booked where it went before (a catalog fee on
     * {@see self::CATALOG_FEE_ROLE}, a hand-typed one on
     * {@see self::UNMAPPED_DEDUCTION_ROLE}). A chart without the account never
     * stops a release; AutomaticPoster::loanRelease() logs a warning for it
     * ({@see self::taxAccountFallbacks()}).
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
        foreach (self::classifyDeductions($deductions, $items, $loan, $map)['credits'] as $role => $amount) {
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
     *   Only unmapped types and hand-typed taxes ever land here; any other
     *   mapped one is refused instead;
     * - `remainder` — `$deductions` minus every usable item, signed: the part
     *   of the total no item explains (negative when the items add up to more);
     * - `credits` — role => what the release credits it, in line order: the
     *   mapped items in {@see self::DEDUCTION_ROLES} order, then taxes on
     *   their payable roles, then catalog fees on
     *   {@see self::CATALOG_FEE_ROLE}, then `$deductions` minus them on
     *   {@see self::UNMAPPED_DEDUCTION_ROLE}. Zero shares are left out.
     *
     * An item with `catalog_fee` true came from the Settings fee catalog; its
     * name still decides first, so a catalog fee called "Service Fee" is a
     * service fee. A tax comes next ({@see self::taxRoleFor()}), so a catalog
     * fee called "Documentary Stamp Tax" is credited to its payable role.
     *
     * Given `$map`, a tax whose role it has no account for takes its fallback
     * ({@see self::taxAccountFallbacks()}). Without a map, every tax takes its
     * payable role.
     *
     * A hand-typed tax whose amount cannot be booked is set aside, as it was
     * while taxes had no role: the old rule posted those loans.
     *
     * @param  int  $deductions  `loans.total_deductions`, in centavos
     * @param  array<array-key, mixed>  $items  list<array{name: string, amount: int|null, catalog_fee?: bool}|mixed>, amounts in centavos
     * @param  string  $loan  what to call the loan in a refusal
     * @param  AccountMap|null  $map  the accounts the release will post to, for the tax fallback
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
    public static function classifyDeductions(int $deductions, array $items, string $loan = 'This loan', ?AccountMap $map = null): array
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
            $catalog = ($item['catalog_fee'] ?? false) === true;
            $tax = self::bookableTaxRole($type, $map);
            $role = self::DEDUCTION_ROLES[$type] ?? $tax ?? ($catalog ? self::CATALOG_FEE_ROLE : null);
            $amount = $item['amount'] ?? null;
            $problem = self::unusableAmount($amount);

            if ($problem !== null) {
                if ($role !== null && ($catalog || $tax === null)) {
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

        foreach ([...array_unique(self::DEDUCTION_ROLES), self::DOCUMENTARY_STAMP_TAX_ROLE, self::WITHHOLDING_TAX_ROLE, self::OTHER_TAXES_ROLE, self::CATALOG_FEE_ROLE] as $role) {
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
     * The role one deduction item is credited to by {@see self::classifyDeductions()}'s
     * order, read without a chart of accounts: its type's role, then a tax's
     * payable role, then the catalog fee role for an item from the fee catalog.
     * Null when it has none. For readers that must classify an item as a release
     * would, such as the Income report; it never refuses an item.
     */
    public static function deductionItemRole(string $name, bool $catalogFee): ?string
    {
        return self::deductionRoleFor($name)
            ?? self::taxRoleFor($name)
            ?? ($catalogFee ? self::CATALOG_FEE_ROLE : null);
    }

    /**
     * The payable role a deduction named `$name` (or its type) is credited to
     * when it is a tax, or null when it is not one, or its type has a role of
     * its own in {@see self::DEDUCTION_ROLES}.
     *
     * The one tax matcher: DisclosureService::kindOf() calls it too, so the
     * statement the borrower signs and the books can never disagree on what a
     * tax is. On whole words of the type ({@see self::TAX_PATTERNS}):
     *
     * - "DST", "D.S.T.", "Doc Stamp", "Documentary Stamp(s)"
     *   → {@see self::DOCUMENTARY_STAMP_TAX_ROLE};
     * - "Withholding Tax(es)", "EWT", "CWT" → {@see self::WITHHOLDING_TAX_ROLE};
     * - any other "Tax" or "Taxes" → {@see self::OTHER_TAXES_ROLE}.
     *
     * Not taxes: "Taxi Fare", "Non-taxable", "Syntax", a withholding that is
     * no tax ("CBU Withholding", "Savings Withholding"), and anything
     * {@see self::NOT_A_TAX_PATTERN} matches ("Tax Clearance Fee",
     * "Tax-exempt …", "Non-tax …"). Plain "VAT" is not recognised: most
     * cooperatives are VAT-exempt.
     */
    public static function taxRoleFor(string $name): ?string
    {
        $type = self::deductionType($name);

        if (isset(self::DEDUCTION_ROLES[$type]) || preg_match(self::NOT_A_TAX_PATTERN, $type) === 1) {
            return null;
        }

        foreach (self::TAX_PATTERNS as $pattern => $role) {
            if (preg_match($pattern, $type) === 1) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The taxes among `$items` that `$map` has no account for, each with the
     * role it would have taken and the role it is booked to instead, in item
     * order. Empty when every tax can go to its own payable account.
     *
     * Pure: AutomaticPoster::loanRelease() logs these as a warning, and only
     * there, so the read-only `accounting:loan-release-diff` logs nothing.
     *
     * @param  array<array-key, mixed>  $items  as {@see self::classifyDeductions()} takes them
     * @return list<array{type: string, role: string, booked_to: string}>
     */
    public static function taxAccountFallbacks(array $items, AccountMap $map): array
    {
        $fallbacks = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $role = self::taxRoleFor($name);

            if ($role === null || $map->has($role)) {
                continue;
            }

            $type = self::deductionType($name);

            $fallbacks[] = [
                'type' => $type,
                'role' => $role,
                'booked_to' => self::bookableTaxRole($type, $map)
                    ?? (($item['catalog_fee'] ?? false) === true ? self::CATALOG_FEE_ROLE : self::UNMAPPED_DEDUCTION_ROLE),
            ];
        }

        return $fallbacks;
    }

    /**
     * The payable role a tax type is booked to under `$map`: its own role
     * when the map has an account for it (or there is no map), else
     * {@see self::OTHER_TAXES_ROLE} when the map has that, else null (no tax,
     * or booked where it went before).
     */
    private static function bookableTaxRole(string $type, ?AccountMap $map): ?string
    {
        $role = self::taxRoleFor($type);

        if ($role === null || $map === null || $map->has($role)) {
            return $role;
        }

        return $role !== self::OTHER_TAXES_ROLE && $map->has(self::OTHER_TAXES_ROLE) ? self::OTHER_TAXES_ROLE : null;
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
     * The insurance premium a payment collected (`repayments.insurance_applied`,
     * the part of the premium the release left owed) is credited to
     * `insurance_premium_payable`, the account the release booked the part it
     * withheld to: money held for the insurer, never income.
     *
     * ## Share capital build-up (Phase 2, decision 5)
     *
     * On a Share Capital Build-Up loan the part of the overpayment the payment
     * credited to the member's share capital ledger (`$shareCapital`, the
     * `share_capital_ledger` row carrying the `repayment_id`) is the member's
     * equity, so it is credited to `share_capital` (3060). Only the rest of
     * the overpayment is held on {@see self::BORROWER_ADVANCES}.
     *
     * ## Restructured interest and penalty (Phase 2, decision 2)
     *
     * On a loan a restructure created, the payment also recognises part of
     * the interest and penalty capitalised into its principal
     * ({@see self::deferralRecognition()}): Dr the unearned restructure
     * account, Cr interest and penalty income, each on its own marked line so
     * the collection's own interest stays its own line.
     *
     * @param  int  $received  `repayments.amount_paid` — every peso that arrived
     * @param  int  $overpayment  the part of it that settled nothing
     * @param  int  $insurance  the part of it that paid the insurance premium owed
     * @param  int  $shareCapital  the part of the overpayment credited to share capital
     * @param  int  $deferredInterest  capitalised interest recognised by this payment
     * @param  int  $deferredPenalty  capitalised penalty recognised by this payment
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
        int $insurance = 0,
        int $shareCapital = 0,
        int $deferredInterest = 0,
        int $deferredPenalty = 0,
    ): array {
        self::requireComponent($principal, 'The principal component');
        self::requireComponent($interest, 'The interest component');
        self::requireComponent($penalty, 'The penalty component');
        self::requireComponent($fees, 'The fees component');
        self::requireComponent($insurance, 'The insurance premium component');
        self::requireComponent($overpayment, 'The overpayment');
        self::requireComponent($shareCapital, 'The share capital build-up');
        self::requireComponent($deferredInterest, 'The restructured interest recognised');
        self::requireComponent($deferredPenalty, 'The restructured penalty recognised');
        self::requireAmount($received, 'A collection');

        $allocated = $principal + $interest + $penalty + $fees + $insurance;

        if ($allocated + $overpayment !== $received) {
            throw CannotPostToTheBooksException::because(
                'This payment does not reconcile: '.Money::format($allocated).' allocated plus '
                .Money::format($overpayment).' unallocated is '.Money::format($allocated + $overpayment)
                .', but '.Money::format($received).' was received. The payment has not been posted.');
        }

        if ($shareCapital > $overpayment) {
            throw CannotPostToTheBooksException::because(
                'This payment does not reconcile: it credited '.Money::format($shareCapital).' to share capital, '
                .'but only '.Money::format($overpayment).' of it was left after the loan. The payment has not been posted.');
        }

        $deferral = self::requireWithinBounds($deferredInterest + $deferredPenalty, 'The restructured interest and penalty recognised');

        return [
            'source' => 'loan_collection',
            'description' => 'Loan collection',
            'lines' => self::used([
                self::debitIfAny($map, SettlementMethod::assertIsSettlementRole($method), $received),
                self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $deferral, self::DEFERRAL_RECOGNISED_LINE),
                self::creditIfAny($map, 'insurance_premium_payable', $insurance),
                self::creditIfAny($map, 'loans_receivable', $principal),
                self::creditIfAny($map, 'interest_income', $interest),
                self::creditIfAny($map, 'penalty_income', $penalty),
                self::creditIfAny($map, 'processing_fee_income', $fees),
                self::describedCredit($map, 'interest_income', $deferredInterest, self::DEFERRED_INTEREST_LINE),
                self::describedCredit($map, 'penalty_income', $deferredPenalty, self::DEFERRED_PENALTY_LINE),
                // The member's equity, not money held for them.
                self::describedCredit($map, 'share_capital', $shareCapital, self::BUILD_UP_LINE),
                // Held, not earned. See self::BORROWER_ADVANCES.
                self::creditIfAny($map, self::BORROWER_ADVANCES, $overpayment - $shareCapital),
            ]),
        ];
    }

    /**
     * How a restructure divides the new loan's principal, in whole centavos.
     *
     * `$unpaidPrincipal`, `$unpaidInterest` and `$unpaidPenalty` are the
     * source loan's unpaid principal, interest and penalty on its open
     * periods, read before its schedules are cleared; `$principal` is the new
     * loan's principal P.
     *
     * - `carried_principal` CP = min(UP, P): the source's principal, carried;
     * - the rest, C = P − CP, is interest and penalty capitalised, interest
     *   first: `capitalised_interest` = min(C, UI), `capitalised_penalty` = C − that;
     * - `written_off_principal` WP = UP − CP: principal the new loan does not
     *   carry, written off on the source (J2).
     *
     * CP + C = P always, and WP > 0 only when nothing is capitalised.
     *
     * @return array{carried_principal: int, capitalised_interest: int, capitalised_penalty: int, written_off_principal: int}
     *
     * @throws CannotPostToTheBooksException when P is more than the source owes, or a figure is unusable
     */
    public static function restructureSplit(int $unpaidPrincipal, int $unpaidInterest, int $unpaidPenalty, int $principal): array
    {
        self::requireComponent($unpaidPrincipal, 'The unpaid principal');
        self::requireComponent($unpaidInterest, 'The unpaid interest');
        self::requireComponent($unpaidPenalty, 'The unpaid penalty');
        self::requireAmount($principal, 'A restructured principal');

        $owed = self::requireWithinBounds($unpaidPrincipal + $unpaidInterest + $unpaidPenalty, 'What the source owes');

        if ($principal > $owed) {
            throw CannotPostToTheBooksException::because(
                'The restructured principal of '.Money::format($principal).' is more than the '
                .Money::format($owed).' the source loan still owes in principal, interest and penalty. '
                .'A restructure pays nothing out, so it cannot carry more than is owed.');
        }

        $carried = min($unpaidPrincipal, $principal);
        $capitalised = $principal - $carried;
        $interest = min($capitalised, $unpaidInterest);

        return [
            'carried_principal' => $carried,
            'capitalised_interest' => $interest,
            'capitalised_penalty' => $capitalised - $interest,
            'written_off_principal' => $unpaidPrincipal - $carried,
        ];
    }

    /**
     * J1, the release of a loan a restructure created: its principal is
     * owed from day one, but no principal is paid out.
     *
     *     Dr loans receivable        P
     *     Dr settlement account      D   (deductions collected from the member)
     *     Cr loans receivable        CP  (principal carried from the source)
     *     Cr unearned restructure    C   (interest and penalty capitalised)
     *     Dr unearned restructure    R   (the source's own deferral carried off it, by part)
     *     Cr unearned restructure    R   (the same, carried onto the new loan, by part)
     *     Cr each deduction's role   D   (as {@see self::classifyDeductions()} books them)
     *
     * R is what the source's own unrecognised deferral has left once its
     * write-off (J2) has used what it needs ({@see self::restructureCarry()}).
     * Both of its halves are on the unearned restructure account, so the
     * account's balance does not move; the marked lines move it between the
     * two loans, and the new loan recognises it with its own capitalised
     * amounts ({@see self::deferralRecognition()}).
     *
     * @param  int  $principal  the new loan's principal P, in centavos
     * @param  int  $carried  CP, from {@see self::restructureSplit()}
     * @param  int  $deductions  `loans.total_deductions` D, collected at release
     * @param  array<array-key, mixed>  $items  the deductions list, as {@see self::loanRelease()} takes it
     * @param  string  $loan  what to call the new loan in a refusal
     * @param  string  $source  what to call the source loan on the carried line
     * @param  int  $carriedInterest  the source's unrecognised interest carried to this loan
     * @param  int  $carriedPenalty  the source's unrecognised penalty carried to this loan
     *
     * @throws CannotPostToTheBooksException when the split does not add up to the principal
     */
    public static function restructureRelease(
        int $principal,
        int $carried,
        int $capitalisedInterest,
        int $capitalisedPenalty,
        int $deductions,
        string $method,
        AccountMap $map,
        array $items = [],
        string $loan = 'This loan',
        string $source = 'the loan it restructures',
        int $carriedInterest = 0,
        int $carriedPenalty = 0,
    ): array {
        self::requireAmount($principal, 'A loan release');
        self::requireComponent($carried, 'The carried principal');
        self::requireComponent($capitalisedInterest, 'The capitalised interest');
        self::requireComponent($capitalisedPenalty, 'The capitalised penalty');
        self::requireComponent($deductions, 'The total deductions');
        self::requireComponent($carriedInterest, 'The interest deferral carried');
        self::requireComponent($carriedPenalty, 'The penalty deferral carried');

        $capitalised = $capitalisedInterest + $capitalisedPenalty;

        if ($carried + $capitalised !== $principal) {
            throw CannotPostToTheBooksException::because(
                "{$loan} does not reconcile: ".Money::format($carried).' carried plus '
                .Money::format($capitalised).' capitalised is '.Money::format($carried + $capitalised)
                .', but the principal is '.Money::format($principal).'. The release has not been posted.');
        }

        $lines = [
            self::debitIfAny($map, 'loans_receivable', $principal),
            self::debitIfAny($map, SettlementMethod::assertIsSettlementRole($method), $deductions),
            self::describedCredit($map, 'loans_receivable', $carried, self::carriedPrincipalLine($source)),
            self::describedCredit($map, self::UNEARNED_RESTRUCTURE_ROLE, $capitalised, self::capitalisedLine($source)),
            self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $carriedInterest, self::DEFERRED_INTEREST_CARRIED_OUT_LINE),
            self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $carriedPenalty, self::DEFERRED_PENALTY_CARRIED_OUT_LINE),
            self::describedCredit($map, self::UNEARNED_RESTRUCTURE_ROLE, $carriedInterest, self::DEFERRED_INTEREST_CARRIED_IN_LINE),
            self::describedCredit($map, self::UNEARNED_RESTRUCTURE_ROLE, $carriedPenalty, self::DEFERRED_PENALTY_CARRIED_IN_LINE),
        ];

        foreach (self::classifyDeductions($deductions, $items, $loan, $map)['credits'] as $role => $amount) {
            $lines[] = self::creditIfAny($map, $role, $amount);
        }

        return [
            'source' => 'loan_release',
            'description' => 'Loan release (restructure)',
            'lines' => self::used($lines),
        ];
    }

    /**
     * J2, on the source loan: the principal a restructure did not carry,
     * written off.
     *
     *     Dr unearned restructure    up to the source's own unrecognised deferral
     *     Dr allowance               then up to the allowance's credit balance
     *     Dr credit loss expense     the rest
     *     Cr loans receivable        WP
     *
     * The deferral comes first because a source that was itself a
     * restructure still carries capitalised interest and penalty inside its
     * principal: writing that part off is income never earned, not a loss.
     * An allowance with a debit balance absorbs nothing.
     *
     * @param  int  $writtenOff  WP, from {@see self::restructureSplit()}
     * @param  int  $sourceDeferral  the source's unrecognised deferral, interest plus penalty
     * @param  int  $allowanceBalance  the allowance account's credit balance, signed
     * @return array{source: string, description: string, lines: list<array<string, mixed>>, parts: array{from_deferral: int, from_allowance: int, expensed: int}}
     */
    public static function restructureWriteOff(int $writtenOff, int $sourceDeferral, int $allowanceBalance, AccountMap $map): array
    {
        self::requireAmount($writtenOff, 'A restructure write-off');
        self::requireComponent($sourceDeferral, 'The source loan\'s unrecognised deferral');

        $fromDeferral = min($writtenOff, $sourceDeferral);
        $fromAllowance = min($writtenOff - $fromDeferral, max(0, $allowanceBalance));
        $expensed = $writtenOff - $fromDeferral - $fromAllowance;

        return [
            'source' => 'credit_loss',
            'description' => 'Restructure write-off',
            'lines' => self::used([
                self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $fromDeferral, self::DEFERRAL_WRITTEN_OFF_LINE),
                self::debitIfAny($map, 'allowance_credit_losses', $fromAllowance),
                self::debitIfAny($map, 'credit_loss_expense', $expensed),
                self::describedCredit($map, 'loans_receivable', $writtenOff, 'Principal written off on restructure'),
            ]),
            'parts' => ['from_deferral' => $fromDeferral, 'from_allowance' => $fromAllowance, 'expensed' => $expensed],
        ];
    }

    /**
     * What of a source loan's own unrecognised deferral carries to the new
     * loan: all of it less what its write-off (J2) takes, min(WP, D), with
     * that taken in the ratio still owed (as {@see self::deferralRemaining()}
     * reads a write-off back). Nothing carries when WP ≥ D.
     *
     * @param  int  $deferredInterest  the source's unrecognised interest, before its restructure
     * @param  int  $deferredPenalty  the source's unrecognised penalty, before its restructure
     * @param  int  $writtenOff  WP, from {@see self::restructureSplit()}
     * @return array{interest: int, penalty: int}
     */
    public static function restructureCarry(int $deferredInterest, int $deferredPenalty, int $writtenOff): array
    {
        self::requireComponent($deferredInterest, 'The source loan\'s unrecognised interest');
        self::requireComponent($deferredPenalty, 'The source loan\'s unrecognised penalty');
        self::requireComponent($writtenOff, 'The principal written off');
        $deferral = self::requireWithinBounds($deferredInterest + $deferredPenalty, 'The source loan\'s unrecognised deferral');

        return self::deferralRemaining($deferredInterest, $deferredPenalty, 0, 0, min($writtenOff, $deferral));
    }

    /**
     * What one collection on a restructured loan recognises of the interest
     * and penalty its restructure capitalised (cash basis: earned as the
     * principal that carries them is collected).
     *
     * The cumulative target is T = round(C × principal collected ÷ P), or
     * all of C once the loan has no open period; this collection recognises
     * T less what earlier collections recognised, never below zero and never
     * past what is still unrecognised. It is split in the capitalised
     * interest : penalty ratio, cumulatively, so the parts finish exactly at
     * the capitalised amounts. Reading "already recognised" from the
     * journals is what makes a voided collection's reversal self-correcting:
     * the next collection catches up to the target.
     *
     * A partial balance reduction's cleared share (`$takenInterest`,
     * `$takenPenalty`) only lowers what is left, never the target: the
     * share was the deferral on the principal written down, so the principal
     * still to collect carries exactly the rest, and the last collection (no
     * open period) takes whatever rounding left.
     *
     * @param  int  $capitalisedInterest  the interest capitalised into the loan, plus any carried to it
     * @param  int  $capitalisedPenalty  the penalty capitalised into the loan, plus any carried to it
     * @param  int  $principal  the loan's principal P
     * @param  int  $principalPaid  principal collected to date, this payment included
     * @param  bool  $complete  whether the loan has no open period left
     * @param  int  $recognisedInterest  capitalised interest earlier collections recognised
     * @param  int  $recognisedPenalty  capitalised penalty earlier collections recognised
     * @param  int  $released  what a write-off or clearing took off the deferral besides
     * @param  int  $takenInterest  interest taken off by part (a reduction's share, a carry)
     * @param  int  $takenPenalty  penalty taken off by part
     * @return array{interest: int, penalty: int}
     */
    public static function deferralRecognition(
        int $capitalisedInterest,
        int $capitalisedPenalty,
        int $principal,
        int $principalPaid,
        bool $complete,
        int $recognisedInterest,
        int $recognisedPenalty,
        int $released = 0,
        int $takenInterest = 0,
        int $takenPenalty = 0,
    ): array {
        $capitalised = self::requireWithinBounds(
            self::requireComponent($capitalisedInterest, 'The capitalised interest') + self::requireComponent($capitalisedPenalty, 'The capitalised penalty'),
            'The capitalised interest and penalty',
        );
        $remaining = self::deferralRemaining($capitalisedInterest, $capitalisedPenalty, $recognisedInterest, $recognisedPenalty, $released, $takenInterest, $takenPenalty);
        $unrecognised = $remaining['interest'] + $remaining['penalty'];

        if ($capitalised === 0 || $unrecognised === 0) {
            return ['interest' => 0, 'penalty' => 0];
        }

        self::requireAmount($principal, 'A restructured principal');

        $target = $complete
            ? $capitalised
            : self::proportion($capitalised, min(max(0, $principalPaid), $principal), $principal);
        $recognised = $recognisedInterest + $recognisedPenalty;
        $now = max(0, min($target - $recognised, $unrecognised));

        if ($now === 0) {
            return ['interest' => 0, 'penalty' => 0];
        }

        $interestTarget = self::proportion($capitalisedInterest, min($recognised + $now, $capitalised), $capitalised);
        $interest = max(0, min($interestTarget - $recognisedInterest, $now, $remaining['interest']));
        $penalty = $now - $interest;

        if ($penalty > $remaining['penalty']) {
            $penalty = $remaining['penalty'];
            $interest = $now - $penalty;
        }

        return ['interest' => $interest, 'penalty' => $penalty];
    }

    /**
     * The capitalised interest and penalty still unrecognised: what
     * collections have not recognised, less what was taken off by part
     * (`$takenInterest`, `$takenPenalty`: a partial reduction's share, or
     * the deferral carried to a new loan), less `$released` (taken by a
     * write-off or a completing clearing), which is split in the ratio still
     * owed.
     *
     * @return array{interest: int, penalty: int}
     */
    public static function deferralRemaining(
        int $capitalisedInterest,
        int $capitalisedPenalty,
        int $recognisedInterest,
        int $recognisedPenalty,
        int $released,
        int $takenInterest = 0,
        int $takenPenalty = 0,
    ): array {
        $interest = max(0, $capitalisedInterest - max(0, $recognisedInterest) - max(0, $takenInterest));
        $penalty = max(0, $capitalisedPenalty - max(0, $recognisedPenalty) - max(0, $takenPenalty));
        $total = $interest + $penalty;
        $taken = min(max(0, $released), $total);

        if ($taken === 0) {
            return ['interest' => $interest, 'penalty' => $penalty];
        }

        $takenInterest = self::proportion($interest, $taken, $total);

        return ['interest' => $interest - $takenInterest, 'penalty' => $penalty - ($taken - $takenInterest)];
    }

    /**
     * The share of a loan's unrecognised deferral a balance reduction clears
     * (Arvin, 2026-11-09): the deferral × the principal reduced ÷ the
     * principal outstanding before the reduction, in whole centavos, split
     * in the ratio still owed. The principal written down carries that share,
     * and it will never be collected, so it is never income.
     *
     * @param  int  $interest  the interest still unrecognised
     * @param  int  $penalty  the penalty still unrecognised
     * @param  int  $reduced  the principal the reduction takes off, in centavos
     * @param  int  $outstandingBefore  the principal outstanding before it
     * @return array{interest: int, penalty: int}
     *
     * @throws CannotPostToTheBooksException when the reduction is more than was outstanding
     */
    public static function deferralShare(int $interest, int $penalty, int $reduced, int $outstandingBefore): array
    {
        self::requireComponent($interest, 'The unrecognised interest');
        self::requireComponent($penalty, 'The unrecognised penalty');
        self::requireAmount($reduced, 'A balance reduction');
        self::requireAmount($outstandingBefore, 'The principal outstanding');
        $deferral = self::requireWithinBounds($interest + $penalty, 'The unrecognised deferral');

        if ($reduced > $outstandingBefore) {
            throw CannotPostToTheBooksException::because(
                'A reduction of '.Money::format($reduced).' is more than the '.Money::format($outstandingBefore)
                .' of principal outstanding before it, so its share of the unearned interest and penalty cannot be worked out.');
        }

        if ($deferral === 0) {
            return ['interest' => 0, 'penalty' => 0];
        }

        $share = self::proportion($deferral, $reduced, $outstandingBefore);
        $shareInterest = self::proportion($interest, $share, $deferral);

        return ['interest' => $shareInterest, 'penalty' => $share - $shareInterest];
    }

    /**
     * A balance adjustment applied to a loan (decision 7), through the
     * clearing account rather than straight to income or expense, so every
     * one is reviewed before it is closed out.
     *
     * - a reduction (`$signed` < 0): Dr loan adjustments / Cr loans receivable;
     * - an increase (`$signed` > 0): Dr loans receivable / Cr loan adjustments;
     * - a reduction that completed a restructured loan still holding deferral
     *   also moves that remainder: Dr unearned restructure / Cr loan adjustments;
     * - a reduction that leaves the loan open clears its share instead
     *   ({@see self::deferralShare()}), by part, on the same two accounts.
     *
     * @param  int  $signed  the change to the principal balance, in centavos
     * @param  int  $deferralCleared  the deferral still held, when this reduction completed the loan
     * @param  int  $interestCleared  the interest share a partial reduction clears
     * @param  int  $penaltyCleared  the penalty share a partial reduction clears
     */
    public static function balanceAdjustment(int $signed, AccountMap $map, int $deferralCleared = 0, int $interestCleared = 0, int $penaltyCleared = 0): array
    {
        $amount = self::requireAmount(self::magnitude($signed, 'A balance adjustment'), 'A balance adjustment');
        self::requireComponent($deferralCleared, 'The deferral cleared');
        self::requireComponent($interestCleared, 'The interest share cleared');
        self::requireComponent($penaltyCleared, 'The penalty share cleared');
        $shareCleared = $interestCleared + $penaltyCleared;

        if ($signed > 0 && $deferralCleared + $shareCleared > 0) {
            throw CannotPostToTheBooksException::because(
                'An increase to a loan\'s balance cannot clear its unearned interest and penalty; only a reduction does.');
        }

        if ($deferralCleared > 0 && $shareCleared > 0) {
            throw CannotPostToTheBooksException::because(
                'A balance reduction clears either all of a loan\'s unearned interest and penalty, when it completes the '
                .'loan, or its own share of it, never both.');
        }

        $lines = $signed < 0
            ? [
                self::debitIfAny($map, self::LOAN_ADJUSTMENTS_ROLE, $amount),
                self::creditIfAny($map, 'loans_receivable', $amount),
                self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $deferralCleared, self::DEFERRAL_CLEARED_LINE),
                self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $interestCleared, self::DEFERRED_INTEREST_CLEARED_LINE),
                self::describedDebit($map, self::UNEARNED_RESTRUCTURE_ROLE, $penaltyCleared, self::DEFERRED_PENALTY_CLEARED_LINE),
                self::creditIfAny($map, self::LOAN_ADJUSTMENTS_ROLE, $deferralCleared + $shareCleared),
            ]
            : [
                self::debitIfAny($map, 'loans_receivable', $amount),
                self::creditIfAny($map, self::LOAN_ADJUSTMENTS_ROLE, $amount),
            ];

        return [
            'source' => 'adjustment',
            'description' => 'Balance adjustment',
            'lines' => self::used($lines),
        ];
    }

    /**
     * A share capital entry a member paid in or took out on its own
     * (decision 5): a credit is Dr settlement / Cr share capital, a debit
     * Dr share capital / Cr settlement. Exactly one side carries an amount.
     *
     * @param  int  $credit  `share_capital_ledger.credit`, in centavos
     * @param  int  $debit  `share_capital_ledger.debit`, in centavos
     */
    public static function shareCapitalEntry(int $credit, int $debit, string $method, AccountMap $map): array
    {
        self::requireComponent($credit, 'A share capital credit');
        self::requireComponent($debit, 'A share capital debit');

        if (($credit > 0) === ($debit > 0)) {
            throw CannotPostToTheBooksException::because(
                'A share capital entry must be either a credit or a debit, with an amount on that side only.');
        }

        $role = SettlementMethod::assertIsSettlementRole($method);

        return [
            'source' => 'share_capital',
            'description' => $credit > 0 ? 'Share capital contribution' : 'Share capital withdrawal',
            'lines' => $credit > 0
                ? [self::debit($map->accountFor($role), $credit), self::credit($map->accountFor('share_capital'), $credit)]
                : [self::debit($map->accountFor('share_capital'), $debit), self::credit($map->accountFor($role), $debit)],
        ];
    }

    /**
     * A GCash counter transaction (decision 6), amount A and charge F:
     *
     * - Cash In: the customer hands over A + F in cash and the cooperative
     *   sends A from its GCash wallet. Dr cash A+F / Cr gcash A / Cr GCash
     *   service income F.
     * - Cash Out: the customer sends A to the wallet and takes A − F in cash.
     *   Dr gcash A / Cr cash A−F / Cr GCash service income F.
     *
     * A ₱0.00 charge has no income line, and needs no income account.
     */
    public static function gcashTransaction(string $type, int $amount, int $charge, AccountMap $map): array
    {
        if (! in_array($type, self::GCASH_TYPES, true)) {
            throw CannotPostToTheBooksException::because(
                "\"{$type}\" is not a GCash transaction type. Only ".implode(' and ', self::GCASH_TYPES).' post to the books.');
        }

        self::requireAmount($amount, 'A GCash transaction');
        self::requireComponent($charge, 'The GCash charge');

        if ($type === 'cash_in') {
            $total = self::requireWithinBounds($amount + $charge, 'A Cash In with its charge');

            $lines = [
                self::debit($map->accountFor('cash'), $total),
                self::credit($map->accountFor('gcash'), $amount),
                self::creditIfAny($map, self::GCASH_SERVICE_INCOME_ROLE, $charge),
            ];
        } else {
            if ($charge >= $amount) {
                throw CannotPostToTheBooksException::because(
                    'A Cash Out of '.Money::format($amount).' cannot carry a charge of '.Money::format($charge)
                    .': nothing would be paid out.');
            }

            $lines = [
                self::debit($map->accountFor('gcash'), $amount),
                self::credit($map->accountFor('cash'), $amount - $charge),
                self::creditIfAny($map, self::GCASH_SERVICE_INCOME_ROLE, $charge),
            ];
        }

        return [
            'source' => $type,
            'description' => $type === 'cash_in' ? 'GCash Cash In' : 'GCash Cash Out',
            'lines' => self::used($lines),
        ];
    }

    /**
     * A change to the allowance for credit losses (decision 12): a positive
     * amount provides (Dr credit loss expense / Cr allowance), a negative one
     * releases (Dr allowance / Cr credit loss expense).
     *
     * @param  int  $signed  the change to the allowance, in centavos
     */
    public static function allowanceAdjustment(int $signed, AccountMap $map): array
    {
        $amount = self::requireAmount(self::magnitude($signed, 'An allowance adjustment'), 'An allowance adjustment');
        [$debitRole, $creditRole] = $signed > 0
            ? ['credit_loss_expense', 'allowance_credit_losses']
            : ['allowance_credit_losses', 'credit_loss_expense'];

        return [
            'source' => 'credit_loss',
            'description' => $signed > 0 ? 'Allowance for credit losses provided' : 'Allowance for credit losses released',
            'lines' => [
                self::debit($map->accountFor($debitRole), $amount),
                self::credit($map->accountFor($creditRole), $amount),
            ],
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

    /**
     * {@see self::debitIfAny()} with a line description: for a line that
     * shares its account with another in the same journal, or that a later
     * reading has to find (the deferral markers).
     *
     * @return array{account_id: int, debit: int, credit: int, description: string}|null
     */
    private static function describedDebit(AccountMap $map, string $role, int $amount, string $description): ?array
    {
        return $amount === 0 ? null : [...self::debit($map->accountFor($role), $amount), 'description' => $description];
    }

    /**
     * The credit half of {@see self::describedDebit()}.
     *
     * @return array{account_id: int, debit: int, credit: int, description: string}|null
     */
    private static function describedCredit(AccountMap $map, string $role, int $amount, string $description): ?array
    {
        return $amount === 0 ? null : [...self::credit($map->accountFor($role), $amount), 'description' => $description];
    }

    /** The size of a signed amount, refused where PHP could not represent it. */
    private static function magnitude(int $signed, string $what): int
    {
        if ($signed === PHP_INT_MIN) {
            throw CannotPostToTheBooksException::because("{$what} is beyond any amount this system records exactly.");
        }

        return abs($signed);
    }

    /**
     * round(`$whole` × `$part` ÷ `$of`), half up, exactly, for non-negative
     * figures with `$part` ≤ `$of`.
     *
     * Integer long multiplication, one bit of `$part` at a time, keeping
     * the running product as a quotient and a remainder of `$of`: nothing is
     * ever larger than `$whole` or twice `$of`, so it cannot overflow where
     * `$whole * $part` would (₱100,000,000.00 of deferral on ₱500,000,000.00
     * of principal), and no float is involved.
     */
    private static function proportion(int $whole, int $part, int $of): int
    {
        if ($whole < 0 || $part < 0 || $of <= 0 || $part > $of) {
            throw new \InvalidArgumentException('A proportion takes non-negative figures and a part no larger than its whole.');
        }

        $wholeQuotient = intdiv($whole, $of);
        $wholeRemainder = $whole % $of;
        $quotient = 0;
        $remainder = 0;

        for ($bit = 62; $bit >= 0; $bit--) {
            $doubled = 2 * $remainder;
            $quotient = 2 * $quotient + intdiv($doubled, $of);
            $remainder = $doubled % $of;

            if ((($part >> $bit) & 1) === 1) {
                $quotient += $wholeQuotient;
                $remainder += $wholeRemainder;

                if ($remainder >= $of) {
                    $quotient++;
                    $remainder -= $of;
                }
            }
        }

        return 2 * $remainder >= $of ? $quotient + 1 : $quotient;
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
     * @param  list<array{account_id: int, debit: int, credit: int, description?: string}|null>  $lines
     * @return list<array{account_id: int, debit: int, credit: int, description?: string}>
     */
    private static function used(array $lines): array
    {
        return array_values(array_filter(
            $lines,
            static fn (?array $line): bool => $line !== null && ($line['debit'] !== 0 || $line['credit'] !== 0),
        ));
    }
}
