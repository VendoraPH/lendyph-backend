<?php

namespace Database\Factories;

use App\Models\CicCapture;
use App\Models\CicSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A generated standard submission of a complete capture by default.
 *
 * @extends Factory<CicSubmission>
 */
class CicSubmissionFactory extends Factory
{
    protected $model = CicSubmission::class;

    public function definition(): array
    {
        return [
            'capture_id' => CicCapture::factory()->complete(),
            'reference_date' => fn (array $attributes) => CicCapture::query()->whereKey($attributes['capture_id'])->value('reference_date'),
            'submission_type' => CicSubmission::TYPE_STANDARD,
            'filename' => 'TEST0001_CSDF_'.now()->format('Ymd').str_pad((string) $this->faker->unique()->numberBetween(0, 999999), 6, '0', STR_PAD_LEFT).'.txt',
            'record_count' => 2,
            'txt_sha256' => hash('sha256', $this->faker->uuid()),
            'status' => CicSubmission::STATUS_GENERATED,
            'generated_by' => User::factory(),
            'generated_at' => now(),
        ];
    }

    public function encrypted(): static
    {
        return $this->state(fn () => [
            'status' => CicSubmission::STATUS_ENCRYPTED,
            'zip_sha256' => hash('sha256', $this->faker->uuid()),
            'gpg_sha256' => hash('sha256', $this->faker->uuid()),
            'gpg_path' => 'cic/submissions/test.zip.gpg',
            'key_fingerprint' => strtoupper(bin2hex(random_bytes(20))),
            'encrypted_by' => User::factory(),
            'encrypted_at' => now(),
        ]);
    }

    public function submitted(): static
    {
        return $this->encrypted()->state(fn () => [
            'status' => CicSubmission::STATUS_SUBMITTED,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    public function correcting(CicSubmission $original, string $reason = 'A member\'s birthdate was wrong.'): static
    {
        return $this->state([
            'submission_type' => CicSubmission::TYPE_CORRECTION,
            'corrects_submission_id' => $original->id,
            'correction_reason' => $reason,
            'reference_date' => $original->reference_date->toDateString(),
        ]);
    }
}
