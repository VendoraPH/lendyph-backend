<?php

namespace Database\Factories;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An ID record in a running capture by default, with made-up values and their
 * checksum; contract() for a CI record. Records go only into a running capture.
 *
 * @extends Factory<CicCaptureRecord>
 */
class CicCaptureRecordFactory extends Factory
{
    protected $model = CicCaptureRecord::class;

    public function definition(): array
    {
        return [
            'capture_id' => CicCapture::factory(),
            'record_type' => 'ID',
            'cic_subject_id' => null,
            'loan_id' => null,
            'record_key' => 'BRW-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
            'values' => fn (array $attributes) => [
                $attributes['record_type'].'1' => $attributes['record_type'],
                $attributes['record_type'].'5' => $attributes['record_key'],
            ],
            'checksum' => fn (array $attributes) => CicCaptureRecord::checksumOf($attributes['values']),
        ];
    }

    public function contract(): static
    {
        return $this->state(fn () => [
            'record_type' => 'CI',
            'record_key' => 'LN-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }
}
