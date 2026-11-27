<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's balance on the opening balances draft, in integer centavos,
 * on exactly one side. One line per account.
 */
class AccountingOpeningBalanceLine extends Model
{
    protected $fillable = [
        'accounting_account_id',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'accounting_opening_balance_id' => 'integer',
            'accounting_account_id' => 'integer',
            'debit' => 'integer',
            'credit' => 'integer',
        ];
    }

    /** @return BelongsTo<AccountingOpeningBalance, $this> */
    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(AccountingOpeningBalance::class, 'accounting_opening_balance_id');
    }

    /** @return BelongsTo<AccountingAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }
}
