<?php

namespace Database\Factories;

use App\Models\AccountingAccount;
use App\Models\AccountingReconciliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Reconciliation rows for the tests that are about the LIST rather than about
 * the matching. Amounts are INTEGER CENTAVOS, signed.
 */
class AccountingReconciliationFactory extends Factory
{
    protected $model = AccountingReconciliation::class;

    public function definition(): array
    {
        return [
            'accounting_account_id' => AccountingAccount::query()->where('code', '1040')->value('id'),
            'period' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 5000050,
            'notes' => null,
        ];
    }
}
