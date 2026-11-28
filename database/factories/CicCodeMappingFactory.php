<?php

namespace Database\Factories;

use App\Models\CicCodeMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CicCodeMapping>
 */
class CicCodeMappingFactory extends Factory
{
    protected $model = CicCodeMapping::class;

    public function definition(): array
    {
        return [
            'domain' => CicCodeMapping::DOMAIN_ID_KIND,
            'lendy_value' => 'Test ID '.$this->faker->unique()->numberBetween(1, 999999),
            'cic_domain' => 'IdentificationTypeDomain',
            'cic_code' => '10',
            'updated_by' => User::factory(),
        ];
    }

    public function collateralType(int $collateralTypeId, string $cicCode): static
    {
        return $this->state([
            'domain' => CicCodeMapping::DOMAIN_COLLATERAL_TYPE,
            'lendy_value' => (string) $collateralTypeId,
            'cic_domain' => 'GuaranteesDomain',
            'cic_code' => $cicCode,
        ]);
    }
}
