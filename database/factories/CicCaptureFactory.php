<?php

namespace Database\Factories;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A running test capture by default. complete() makes it complete with no
 * records; completeWithRecords() adds records while it runs, then completes
 * it with its counts and checksum, as the capture service does.
 *
 * @extends Factory<CicCapture>
 */
class CicCaptureFactory extends Factory
{
    protected $model = CicCapture::class;

    public function definition(): array
    {
        return [
            'reference_date' => now()->toDateString(),
            'kind' => CicCapture::KIND_TEST,
            'status' => CicCapture::STATUS_CAPTURING,
            'started_at' => now(),
            'created_by' => null,
        ];
    }

    public function monthEnd(string $referenceDate): static
    {
        return $this->state(['kind' => CicCapture::KIND_MONTH_END, 'reference_date' => $referenceDate]);
    }

    public function correction(string $referenceDate): static
    {
        return $this->state(['kind' => CicCapture::KIND_CORRECTION, 'reference_date' => $referenceDate]);
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'status' => CicCapture::STATUS_COMPLETE,
            'checksum' => hash_final(hash_init('sha256')),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CicCapture::STATUS_FAILED,
            'finished_at' => now(),
            'error' => 'The capture stopped.',
        ]);
    }

    /** Complete, holding `$subjects` ID records and `$contracts` CI records. */
    public function completeWithRecords(int $subjects = 1, int $contracts = 1): static
    {
        return $this->state(['status' => CicCapture::STATUS_CAPTURING])
            ->afterCreating(function (CicCapture $capture) use ($subjects, $contracts): void {
                CicCaptureRecord::factory()->count($subjects)->for($capture, 'capture')->create();
                CicCaptureRecord::factory()->count($contracts)->contract()->for($capture, 'capture')->create();

                $capture->forceFill([
                    'status' => CicCapture::STATUS_COMPLETE,
                    'subject_count' => $subjects,
                    'contract_count' => $contracts,
                    'checksum' => $capture->computeChecksum(),
                    'finished_at' => now(),
                ])->save();
            });
    }
}
