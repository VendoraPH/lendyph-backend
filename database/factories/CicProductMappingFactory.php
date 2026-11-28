<?php

namespace Database\Factories;

use App\Models\CicProductMapping;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CicProductMapping>
 */
class CicProductMappingFactory extends Factory
{
    protected $model = CicProductMapping::class;

    public function definition(): array
    {
        return [
            'loan_product_id' => LoanProduct::factory(),
            'record_type' => 'CI',
            'contract_type' => '20',
            'transaction_type' => 'NA',
            'purpose_code' => null,
            'updated_by' => User::factory(),
        ];
    }

    public function nonInstallment(): static
    {
        return $this->state(['record_type' => 'CN']);
    }
}
