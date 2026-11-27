<?php

namespace Database\Factories;

use App\Models\CdaNoticeSent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaNoticeSent>
 */
class CdaNoticeSentFactory extends Factory
{
    protected $model = CdaNoticeSent::class;

    public function definition(): array
    {
        return [
            'subject_type' => 'report',
            'subject_id' => $this->faker->unique()->numberBetween(1, 999_999),
            'kind' => 'deadline',
            'dedupe_key' => '30:2027-04-30',
            'sent_at' => now(),
        ];
    }
}
