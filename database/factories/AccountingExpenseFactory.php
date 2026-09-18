<?php

namespace Database\Factories;

use App\Models\AccountingAccount;
use App\Models\AccountingExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Expense rows for the tests that are about the LIST, not about the posting.
 *
 * Deliberately writes no journal. Every real write path goes through
 * ExpenseRecorder, which posts one in the same transaction; a factory that
 * posted too would make a 150-row pagination fixture into 150 journal entries
 * and would be testing the poster for the third time. The tests that care about
 * the journal — which is most of them — build their expenses through the API.
 *
 * Amounts are INTEGER CENTAVOS.
 */
class AccountingExpenseFactory extends Factory
{
    protected $model = AccountingExpense::class;

    public function definition(): array
    {
        return [
            'date' => '2026-09-18',
            'payee' => $this->faker->company(),
            'expense_account_id' => AccountingAccount::query()->where('code', '5030')->value('id'),
            'amount' => 1500050,
            'amount_paid' => 0,
            'payment_account_id' => null,
            'branch_id' => null,
            'reference' => null,
            'description' => null,
            'due_date' => null,
            'settlement_status' => 'unpaid',
            'journal_id' => null,
        ];
    }

    /** Settled in full, the way a cash expense is the moment it is recorded. */
    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount_paid' => $attributes['amount'],
            'settlement_status' => 'paid',
        ]);
    }

    public function partiallyPaid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount_paid' => intdiv((int) $attributes['amount'], 2),
            'settlement_status' => 'partially_paid',
        ]);
    }

    /** Owed, with a due date already behind us. */
    public function overdue(): static
    {
        return $this->state(['due_date' => '2020-01-01', 'settlement_status' => 'unpaid']);
    }
}
