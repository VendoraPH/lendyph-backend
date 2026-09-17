<?php

namespace App\Models;

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
        'interest_receivable' => ['type' => 'asset'],
        'penalty_receivable' => ['type' => 'asset'],
        'interest_income' => ['type' => 'income'],
        'penalty_income' => ['type' => 'income'],
        'processing_fee_income' => ['type' => 'income'],
        'credit_loss_expense' => ['type' => 'expense'],
        'allowance_credit_losses' => ['type' => 'asset', 'is_contra' => true],
        'accounts_payable' => ['type' => 'liability'],
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

        // A role with no declared shape is refused rather than waved through:
        // it means ROLES gained an entry that ROLE_SHAPES did not, and
        // accepting it would let the one role nobody has thought about point
        // anywhere at all.
        if ($shape === null) {
            return [
                'field' => 'role',
                'message' => "There is no defined account shape for the {$role} role, so it cannot be set.",
            ];
        }

        if ($type !== $shape['type']) {
            return [
                'field' => 'type',
                'message' => "{$label} is {$type}, but the {$role} role has to resolve to "
                    .self::article($shape['type'])." {$shape['type']} account. Posting through it would put the "
                    .'amount on the wrong statement, and the entry would still balance — so nothing would report '
                    .'the mistake.',
            ];
        }

        if (($shape['is_contra'] ?? false) && ! $isContra) {
            return [
                'field' => 'is_contra',
                'message' => "{$label} is not a contra account. The {$role} role must resolve to one: a contra "
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
                'message' => "{$label} is the {$role} account, which is not a money account — but it is marked "
                    ."as a {$cashKind} account. `cash_kind` is what puts a balance into the dashboard cash "
                    .'figures, so this would report the balance as money on hand.',
            ];
        }

        if ($kind !== null && $cashKind !== $kind) {
            $held = $cashKind === null ? 'is not a money account at all' : "is a {$cashKind} account";

            return [
                'field' => 'cash_kind',
                'message' => "{$label} {$held}, but the {$role} role has to resolve to one whose cash kind is "
                    ."{$kind}. `cash_kind` is what puts an account on the Cash & Bank screen and into the "
                    .'dashboard cash figures, so settlements through it would never appear in the money on hand.',
            ];
        }

        return null;
    }

    /** "an asset", "a liability". Only `liability` takes "a". */
    private static function article(string $type): string
    {
        return in_array($type[0] ?? '', ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
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
