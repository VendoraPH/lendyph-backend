<?php

namespace Database\Factories;

use App\Models\CicLoanOverride;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CicLoanOverride>
 */
class CicLoanOverrideFactory extends Factory
{
    protected $model = CicLoanOverride::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'record_type' => 'CN',
            'contract_type' => null,
            'transaction_type' => null,
            'reason' => 'Repaid in one payment at maturity.',
            'created_by' => $user = User::factory(),
            'updated_by' => $user,
        ];
    }
}
