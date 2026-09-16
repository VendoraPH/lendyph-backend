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
