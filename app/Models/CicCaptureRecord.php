<?php

namespace App\Models;

use App\Exceptions\CicRecordIsFrozenException;
use App\Models\Builders\CicCaptureRecordBuilder;
use App\Traits\GuardsCicWrites;
use Database\Factories\CicCaptureRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;

/**
 * One captured CSDF record (ID, CI, CN or NE): `values` maps each field id to
 * its CSDF string, already formatted, and `checksum` is checksumOf(values).
 *
 * Records are added only to a running capture, singly or in bulk
 * (CicCaptureRecordBuilder), and can't change or be removed once their capture
 * is complete or failed. Every bulk update or delete is refused.
 *
 * @property int $id
 * @property int $capture_id
 * @property string $record_type ID|CI|CN|NE
 * @property int|null $cic_subject_id
 * @property int|null $loan_id
 * @property string $record_key the subject_no or the contract_no
 * @property array<string, string> $values
 * @property string $checksum
 * @property list<array{field_id: string|null, severity: string, code: string, message: string}>|null $build_issues what the builder found that the values can't show
 */
class CicCaptureRecord extends Model
{
    /** @use HasFactory<CicCaptureRecordFactory> */
    use GuardsCicWrites, HasFactory;

    /** The capture service sets every field; nothing comes from a request. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'capture_id' => 'integer',
            'cic_subject_id' => 'integer',
            'loan_id' => 'integer',
            'values' => 'array',
            'build_issues' => 'array',
        ];
    }

    /**
     * @param  Builder  $query
     */
    public function newEloquentBuilder($query): CicCaptureRecordBuilder
    {
        return new CicCaptureRecordBuilder($query);
    }

    /**
     * A record's checksum: SHA-256 of its values as JSON, fields in their
     * natural order (ID1, ID2 … ID10), unescaped. MySQL's JSON column reorders
     * keys, so the checksum never depends on the stored order.
     *
     * @param  array<string, string|null>  $values
     */
    public static function checksumOf(array $values): string
    {
        ksort($values, SORT_NATURAL);

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function cicGuardInsert(): void
    {
        $this->assertCaptureIsRunning((int) $this->capture_id, 'records can be added only to a capture that is still running.');
    }

    protected function cicGuardUpdate(): void
    {
        if ($this->cicDirtyColumns() === []) {
            return;
        }

        if ($this->isDirty('capture_id')) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), 'a record never moves to another capture.');
        }

        $this->assertCaptureIsRunning((int) $this->capture_id, 'a record can\'t change once its capture is complete or failed.');
    }

    protected function cicGuardDelete(): void
    {
        $this->assertCaptureIsRunning((int) $this->getOriginal('capture_id'), 'a record can\'t be removed once its capture is complete or failed.');
    }

    private function assertCaptureIsRunning(int $captureId, string $reason): void
    {
        $running = CicCapture::query()->whereKey($captureId)->where('status', CicCapture::STATUS_CAPTURING)->exists();

        if (! $running) {
            throw CicRecordIsFrozenException::because($this->getTable(), $this->getKey(), $reason);
        }
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(CicCapture::class, 'capture_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(CicSubject::class, 'cic_subject_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
