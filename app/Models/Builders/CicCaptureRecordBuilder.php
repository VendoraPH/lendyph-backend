<?php

namespace App\Models\Builders;

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\CicCapture;
use App\Models\CicCaptureRecord;

/**
 * CicGuardedBuilder for capture records, whose bulk inserts (the capture
 * writes its records in batches) are checked too: rows go only into a capture
 * that is still running, never into a complete or failed one.
 *
 * @extends CicGuardedBuilder<CicCaptureRecord>
 */
class CicCaptureRecordBuilder extends CicGuardedBuilder
{
    /** @param  array<int|string, mixed>  $values */
    public function insert(array $values): bool
    {
        $this->assertCapturesAreRunning($values);

        return $this->toBase()->insert($values);
    }

    /** @param  array<int|string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        $this->assertCapturesAreRunning($values);

        return $this->toBase()->insertOrIgnore($values);
    }

    /** @param  array<string, mixed>  $values */
    public function insertGetId(array $values, $sequence = null): int
    {
        $this->assertCapturesAreRunning($values);

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function insertUsing(array $columns, $query): never
    {
        throw $this->refused('insertUsing');
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw $this->refused('insertOrIgnoreUsing');
    }

    /** @param  array<int|string, mixed>  $values  one row or a list of rows */
    private function assertCapturesAreRunning(array $values): void
    {
        if ($values === []) {
            return;
        }

        $rows = is_array(reset($values)) ? $values : [$values];
        $captureIds = array_values(array_unique(array_map(fn (array $row) => (int) ($row['capture_id'] ?? 0), $rows)));
        $running = CicCapture::query()->whereKey($captureIds)->where('status', CicCapture::STATUS_CAPTURING)->count();

        if ($running !== count($captureIds)) {
            throw CicRecordIsFrozenException::because('cic_capture_records', null, 'records can be added only to a capture that is still running.');
        }
    }
}
