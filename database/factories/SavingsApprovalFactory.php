<?php

namespace Database\Factories;

use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending withdrawal approval that needs one checker and expires at the
 * end of today.
 *
 * @extends Factory<SavingsApproval>
 */
class SavingsApprovalFactory extends Factory
{
    protected $model = SavingsApproval::class;

    public function definition(): array
    {
        return [
            'kind' => 'withdrawal',
            'account_id' => SavingsAccount::factory(),
            'amount' => '20000.00',
            'payload' => null,
            'required_checkers' => 1,
            'status' => 'pending',
            'requested_by' => fn () => User::query()->value('id') ?? User::factory(),
            'expires_at' => now()->endOfDay(),
        ];
    }
}
