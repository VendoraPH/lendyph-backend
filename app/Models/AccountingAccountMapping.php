<?php

namespace App\Models;

use App\Services\Accounting\OpeningBalancePoster;
use App\Services\Accounting\PostingRules;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which account a posting role resolves to. One row per role.
 *
 * @property int $id
 * @property string $role
 * @property int $accounting_account_id
 */
class AccountingAccountMapping extends Model
{
    /**
     * Re-pointing a posting role silently changes where every future release,
     * collection, fee and penalty lands. Who did it has to be recoverable.
     */
    use Auditable, HasFactory;

    protected $table = 'accounting_account_mappings';

    /**
     * Every posting role the engine resolves, in the order the settings screen
     * groups them.
     *
     * The canonical vocabulary: it mirrors `AccountMapping` in
     * `src/types/accounting.ts` key for key, and a role absent from here is a
     * role no rule can ask for. Adding one is a deliberate change to the
     * contract on both sides, which is why it is a constant rather than
     * whatever happens to be in the table.
     *
     * ## Two of these are mapped and never posted to, on purpose
     *
     * `penalty_receivable` and `interest_receivable` are seeded, mapped (to
     * 1160 and 1150) and resolved by no posting rule anywhere. That is not an
     * oversight and not an unfinished feature — it is the accrual model this
     * organisation REJECTED.
     *
     * Penalties and interest are both recognised on a CASH basis: each is
     * credited to its income account (`penalty_income`, `interest_income`) as a
     * LINE inside the `loan_collection` journal, at the moment the money
     * arrives. Nothing accrues a receivable, so nothing relieves one.
     * {@see PostingRules::loanCollection()} carries
     * the full rationale; read it before wiring either role into a rule,
     * because a posting that debits a receivable needs the matching relief on
     * collection, and half of that pair overstates assets forever.
     *
     * They stay in the list because both accounts are already seeded and both
     * roles already mapped on every deployment: removing them means a migration
     * that deletes live rows, for a chart that would still carry the accounts.
     * The frontend's `AccountMapping` type also mirrors this list key for key,
     * so dropping one is a contract change on both sides — and an administrator
     * pointing them somewhere is harmless while no rule asks.
     */
    public const ROLES = [
        'cash',
        'gcash',
        'maya',
        'bank',
        'loans_receivable',
        'interest_receivable',
        'penalty_receivable',
        'interest_income',
        'penalty_income',
        'processing_fee_income',
        'credit_loss_expense',
        'allowance_credit_losses',
        'accounts_payable',
        'borrower_advances',
        'service_fee_income',
        'notarial_fees_payable',
        'insurance_premium_payable',
        'share_capital',
        'other_fee_income',
        'documentary_stamp_tax_payable',
        'withholding_tax_payable',
        'other_taxes_payable',
        'gcash_service_income',
        'loan_adjustments',
        'unearned_restructure_income',
        'net_surplus',
        'suspense',
        'savings_deposits',
        'time_deposits',
        'interest_expense_deposits',
        'withholding_tax_deposits',
    ];

    /**
     * Each role in words, as the Accounting Settings screen labels it
     * (`mapping-fields.ts` in the frontend). Messages name a role through
     * {@see self::label()}, never by its stored key.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'bank' => 'Bank',
        'loans_receivable' => 'Loans receivable',
        'interest_receivable' => 'Interest receivable',
        'penalty_receivable' => 'Penalty receivable',
        'interest_income' => 'Interest income',
        'penalty_income' => 'Penalty income',
        'processing_fee_income' => 'Processing fee income',
        'credit_loss_expense' => 'Credit loss expense',
        'allowance_credit_losses' => 'Allowance for credit losses',
        'accounts_payable' => 'Accounts payable',
        'borrower_advances' => 'Member advances',
        'service_fee_income' => 'Service fee income',
        'notarial_fees_payable' => 'Notarial fees payable',
        'insurance_premium_payable' => 'Insurance premium payable',
        'share_capital' => 'Share capital',
        'other_fee_income' => 'Other fee income',
        'documentary_stamp_tax_payable' => 'Documentary stamp tax payable',
        'withholding_tax_payable' => 'Withholding tax payable',
        'other_taxes_payable' => 'Other taxes payable',
        'gcash_service_income' => 'GCash service income',
        'loan_adjustments' => 'Loan adjustments clearing',
        'unearned_restructure_income' => 'Unearned restructure interest and penalty',
        'net_surplus' => 'Retained earnings or net surplus',
        'suspense' => 'Suspense',
        'savings_deposits' => 'Savings deposits',
        'time_deposits' => 'Time deposits',
        'interest_expense_deposits' => 'Interest expense on deposits',
        'withholding_tax_deposits' => 'Withholding tax on deposits',
    ];

    /**
     * What each role's account has to BE, beyond merely being postable.
     *
     * ## Why the type has to be checked and why it is not enough to be careful
     *
     * The posting engine trusts this table without question: a rule asks for
     * `allowance_credit_losses` and posts to whatever comes back. Nothing
     * downstream re-examines the account, and nothing can — by the time the
     * entry exists it is a balanced journal against a real account, and it
     * balances, and every report renders it without complaint.
     *
     * So the damage is silent and it is arithmetic. `AccountRules::signedBalance()`
     * reads `normal_balance`, which is derived from `type` + `is_contra`. Point
     * `allowance_credit_losses` at an ordinary (non-contra) asset and the
     * allowance becomes debit-normal: Net Loans Receivable comes out as gross
     * PLUS the provision instead of minus, overstating the portfolio by twice
     * the allowance on the dashboard and the balance sheet at once. Point
     * `cash` at an income account and every collection credits revenue twice
     * while the cash never appears. Neither fails. Both just report.
     *
     * The three checks:
     *
     * - `type` — the statement classification. Always required.
     * - `is_contra` — required `true` for the allowance, which is the whole
     *   reason that account exists: it is an asset carrying a credit balance
     *   that SUBTRACTS from the assets above it.
     * - `cash_kind` — required, and matching the role, for the four settlement
     *   accounts. `cash_kind` is what puts an account on the Cash & Bank screen
     *   and into the dashboard's cash figures, so a `gcash` role pointing at an
     *   account with no `cash_kind` would take collections that never show up
     *   in the money the organisation thinks it holds.
     *
     * Keyed by role and covering every entry in {@see self::ROLES}; a role
     * added there without a shape here is refused outright rather than waved
     * through, so the two lists cannot drift.
     *
     * @var array<string, array{type: string, is_contra?: bool, cash_kind?: string}>
     */
    public const ROLE_SHAPES = [
        'cash' => ['type' => 'asset', 'cash_kind' => 'cash'],
        'gcash' => ['type' => 'asset', 'cash_kind' => 'gcash'],
        'maya' => ['type' => 'asset', 'cash_kind' => 'maya'],
        'bank' => ['type' => 'asset', 'cash_kind' => 'bank'],
        'loans_receivable' => ['type' => 'asset'],

        /*
         * Shapes for two roles nothing posts to. See the note on self::ROLES:
         * interest and penalties are recognised on a CASH basis, as lines
         * inside the collection journal, so neither receivable is ever debited
         * or relieved. The shape is still declared because ROLES and
         * ROLE_SHAPES are required to cover each other exactly — a role without
         * a shape is refused outright rather than waved through.
         */
        'interest_receivable' => ['type' => 'asset'],
        'penalty_receivable' => ['type' => 'asset'],
        'interest_income' => ['type' => 'income'],
        'penalty_income' => ['type' => 'income'],
        'processing_fee_income' => ['type' => 'income'],
        'credit_loss_expense' => ['type' => 'expense'],
        'allowance_credit_losses' => ['type' => 'asset', 'is_contra' => true],
        'accounts_payable' => ['type' => 'liability'],

        /*
         * Money received that has not settled anything yet.
         *
         * `repayments.amount_paid` is not bounded by what is owed —
         * StoreRepaymentRequest allows any amount over a centavo — and the
         * excess is stored as `repayments.overpayment`. It is cash the
         * organisation is holding on the borrower's behalf, so it is a
         * LIABILITY, not income: crediting it anywhere on the income statement
         * would report money as earned that the borrower can still ask back.
         *
         * Deliberately NOT `accounts_payable`. That role is a trade payable,
         * owned by the expenses side of this module, and an obligation to a
         * supplier is not an obligation to a borrower. Sharing one account
         * would make the payables ageing report unreadable and would put two
         * streams' postings in the same balance.
         */
        'borrower_advances' => ['type' => 'liability'],

        /*
         * Where each deduction withheld at release is credited, as the
         * cooperative's accountant confirmed on 2026-10-03 (see
         * PostingRules::DEDUCTION_ROLES). The notarial fee and the insurance
         * premium are collected for someone else — the notary, the insurer —
         * so they are owed onward, not earned: liabilities. Share capital is
         * the member's own contribution: equity. The service fee and catalog
         * fees are earned: income.
         */
        'service_fee_income' => ['type' => 'income'],
        'notarial_fees_payable' => ['type' => 'liability'],
        'insurance_premium_payable' => ['type' => 'liability'],
        'share_capital' => ['type' => 'equity'],
        'other_fee_income' => ['type' => 'income'],

        /*
         * Taxes withheld at release (decision 4b, 2026-11-02; see
         * PostingRules::taxRoleFor()). A tax is a government levy the
         * cooperative collects and remits, never its own income: liabilities.
         */
        'documentary_stamp_tax_payable' => ['type' => 'liability'],
        'withholding_tax_payable' => ['type' => 'liability'],
        'other_taxes_payable' => ['type' => 'liability'],

        /*
         * Phase 2 (approved 2026-11-08; see PostingRules):
         *
         * - `gcash_service_income`: the charge on a Cash In or Cash Out is
         *   earned when the transaction posts, so income.
         * - `loan_adjustments`: a balance adjustment clears through here, an
         *   asset that should read zero once each one is reviewed.
         * - `unearned_restructure_income`: interest and penalty a restructure
         *   capitalised into the new loan's principal and has not earned yet.
         *   A CONTRA asset, like the allowance: it subtracts from the loans it
         *   is carried in, and an ordinary asset here would add to them.
         * - `net_surplus`: where the year-end close moves the result, equity.
         * - `suspense`: money received that cannot be identified yet, owed
         *   until it is, so a liability.
         */
        'gcash_service_income' => ['type' => 'income'],
        'loan_adjustments' => ['type' => 'asset'],
        'unearned_restructure_income' => ['type' => 'asset', 'is_contra' => true],
        'net_surplus' => ['type' => 'equity'],
        'suspense' => ['type' => 'liability'],

        /*
         * Savings & Deposits (Phase 3, approved 2026-11-10). Members' savings
         * and time deposits are money the cooperative owes them back, so both
         * are liabilities; interest paid on them is an expense, credited to
         * the member when it is paid (cash basis); tax withheld from that
         * interest is owed to the BIR. Mapped by the cooperative template and
         * by `savings:prepare-books`.
         */
        'savings_deposits' => ['type' => 'liability'],
        'time_deposits' => ['type' => 'liability'],
        'interest_expense_deposits' => ['type' => 'expense'],
        'withholding_tax_deposits' => ['type' => 'liability'],
    ];

    protected $fillable = [
        'role',
        'accounting_account_id',
    ];

    protected function casts(): array
    {
        return [
            'accounting_account_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }

    /**
     * Why an account of this shape cannot serve `$role` — or null when it can.
     *
     * ## Takes scalars, not a model, because it guards BOTH directions
     *
     * A role and an account can be made to disagree from either end, and until
     * both ends were closed the guard was worth very little:
     *
     * - `PUT /accounting/settings/account-mapping` points the role at a
     *   different account. Checked against that account's stored values.
     * - `PUT /accounting/accounts/{id}` re-shapes the account the role already
     *   points at — changing its `type`, clearing its `cash_kind`, or dropping
     *   `is_contra`. Checked against what the account will BE after the save,
     *   which is not on any model yet. That is why this takes loose values.
     *
     * Both doors need `chart_of_accounts:update` or `accounting:settings`, held
     * by the same principals, so closing one and leaving the other is not a
     * defence — it is a longer walk to the same wrong number.
     *
     * @param  string  $label  "1010 Cash on Hand", for the message.
     * @return array{field: string, message: string}|null `field` names the
     *                                                    account attribute at
     *                                                    fault, for the account
     *                                                    direction; the mapping
     *                                                    direction reports
     *                                                    against the role.
     */
    public static function roleMismatch(
        string $role,
        string $label,
        string $type,
        bool $isContra,
        ?string $cashKind,
    ): ?array {
        $shape = self::ROLE_SHAPES[$role] ?? null;
        $roleLabel = self::label($role);

        // A role with no declared shape is refused rather than waved through:
        // it means ROLES gained an entry that ROLE_SHAPES did not, and
        // accepting it would let the one role nobody has thought about point
        // anywhere at all.
        if ($shape === null) {
            return [
                'field' => 'role',
                'message' => "There is no defined account shape for the {$roleLabel} role, so it cannot be set.",
            ];
        }

        if ($type !== $shape['type']) {
            return [
                'field' => 'type',
                'message' => "{$label} is ".self::typeInWords($type).", but the {$roleLabel} role has to resolve to "
                    .self::typeInWords($shape['type']).'. Posting through it would put the '
                    .'amount on the wrong statement, and the entry would still balance — so nothing would report '
                    .'the mistake.',
            ];
        }

        if (($shape['is_contra'] ?? false) && ! $isContra) {
            return [
                'field' => 'is_contra',
                'message' => "{$label} is not a contra account. The {$roleLabel} role must resolve to one: a contra "
                    .'asset carries a credit balance and SUBTRACTS from the assets above it, so an ordinary asset '
                    .'here would make the net figure come out as gross plus the allowance instead of minus.',
            ];
        }

        $kind = $shape['cash_kind'] ?? null;

        // The inverse of the check below, and the reason the shape has to say
        // what a role must NOT be as well as what it must. Variant B of the
        // original finding stripped `cash_kind` off a money account so its
        // balance vanished from money-on-hand; this is the same door from the
        // other side — put a `cash_kind` on an account whose role has none,
        // and AccountingDashboardBuilder::sumByCashKind() folds it in. Do it
        // to `loans_receivable` and the whole net loan portfolio is reported
        // as cash in the bank.
        if ($kind === null && $cashKind !== null) {
            return [
                'field' => 'cash_kind',
                'message' => "{$label} is the {$roleLabel} account, which is not a money account — but it is marked "
                    .'as '.self::cashKindInWords($cashKind).'. Being a money account is what puts a balance into the '
                    .'dashboard cash figures, so this would report the balance as money on hand.',
            ];
        }

        if ($kind !== null && $cashKind !== $kind) {
            $held = $cashKind === null ? 'is not a money account' : 'is '.self::cashKindInWords($cashKind);

            return [
                'field' => 'cash_kind',
                'message' => "{$label} {$held}, but the {$roleLabel} role has to resolve to "
                    .self::cashKindInWords($kind).'. The kind of money account is what puts it on the Cash & Bank '
                    .'screen and into the dashboard cash figures, so settlements through it would never appear in '
                    .'the money on hand.',
            ];
        }

        return null;
    }

    /**
     * Why `$role` can't resolve to the account with this code, beyond its
     * shape, or null. One rule so far: net surplus never resolves to 3050
     * Opening Balance Equity. The year's result would be mixed into the
     * opening balances, and a close or reopen would lock 3050 after its
     * months while an opening balance locks 3050 before them
     * (OpeningBalancePoster's lock order). The caller adds what to do next.
     *
     * @param  string  $label  "3050 Opening Balance Equity", for the message.
     */
    public static function reservedAccountReason(string $role, string $code, string $label): ?string
    {
        if ($role !== 'net_surplus' || $code !== OpeningBalancePoster::OPENING_EQUITY_CODE) {
            return null;
        }

        return self::label($role)." can't post to {$label}, the opening balance equity account: "
            ."the year's result would be mixed into the opening balances.";
    }

    /**
     * A role in words. A role with no label reads as its key in words
     * ("Some new role"), so a stored key never reaches the screen.
     */
    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? ucfirst(strtolower(str_replace('_', ' ', $role)));
    }

    /**
     * Several roles in words, in role-list order: "Loan adjustments clearing
     * and Suspense", "Cash, Bank and Suspense".
     *
     * @param  iterable<string>  $roles
     */
    public static function labelList(iterable $roles): string
    {
        $roles = collect($roles)->unique()->sortBy(static function (string $role): int {
            $position = array_search($role, self::ROLES, true);

            return $position === false ? PHP_INT_MAX : $position;
        });

        return $roles->map(static fn (string $role): string => self::label($role))
            ->values()
            ->join(', ', ' and ');
    }

    /**
     * An account type in words, with its article: "an asset account", "a
     * liability account". Messages never show a bare stored type.
     */
    public static function typeInWords(string $type): string
    {
        $words = $type === '' ? 'unclassified' : $type;
        $article = in_array($words[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

        return "{$article} {$words} account";
    }

    /**
     * A money account's kind in words, with its article: "a cash account",
     * "a GCash wallet". Messages never show a stored `cash_kind`.
     */
    public static function cashKindInWords(string $cashKind): string
    {
        return match ($cashKind) {
            'cash' => 'a cash account',
            'bank' => 'a bank account',
            'gcash' => 'a GCash wallet',
            'maya' => 'a Maya wallet',
            'wallet' => 'an e-wallet',
            default => 'a money account',
        };
    }

    /**
     * The whole mapping as `role => account_id`, which is the shape the API
     * returns and the posting engine reads.
     *
     * Roles with no row are simply absent rather than present-and-null: an
     * unseeded chart has no mapping at all, and "this role is unset" is a
     * different statement from "this role points at nothing".
     *
     * Emitted in {@see self::ROLES} order so the response is stable between
     * calls. A row whose role is not in that list is still included, at the
     * end: it should be impossible, and if it ever happens it must be visible
     * rather than quietly dropped from the settings screen.
     *
     * @return array<string, int>
     */
    public static function resolved(): array
    {
        $rows = static::query()->pluck('accounting_account_id', 'role');

        $resolved = [];

        foreach (self::ROLES as $role) {
            if ($rows->has($role)) {
                $resolved[$role] = (int) $rows[$role];
            }
        }

        foreach ($rows as $role => $accountId) {
            if (! array_key_exists($role, $resolved)) {
                $resolved[$role] = (int) $accountId;
            }
        }

        return $resolved;
    }
}
