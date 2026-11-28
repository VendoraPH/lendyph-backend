<?php

namespace App\Services\Cic\File;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;
use App\Models\CicSetting;
use App\Models\CicSubmission;
use App\Services\Cic\CicLayouts;
use App\Services\Cic\CicSwitch;
use App\Services\Cic\Submissions\CicSubmissionRefusal;
use Carbon\CarbonInterface;

/**
 * Builds the CSDF TXT (prompt §11) from a complete capture's frozen records.
 *
 * - Lines: HD, every ID record, then CI, then CN, then NE (each type by its
 *   record key), then FT. Each record's fields follow CicLayouts' order, every
 *   position kept, an empty value written empty.
 * - HD: "HD", the provider code, the reference date (DDMMYYYY), "1.0", the
 *   submission type (0 or 1) and the comments. FT: "FT", the provider code,
 *   the reference date and the number of data records, HD and FT left out
 *   (an open question with CIC, summary Q6).
 * - A record's own Provider Code field is the setting's code, as HD2; its
 *   record-type field is the layout's fixed code.
 * - Fields joined by `|`, every line ended by CRLF, UTF-8 with no BOM.
 * - The filename is `{provider}_CSDF_{YYYYMMDDhhmmss}.txt`, from the
 *   generation time in Manila.
 *
 * It is deterministic: the same capture, type, comments, generation time and
 * provider code give the same bytes, so a rebuild at encryption proves itself
 * against the stored SHA-256.
 */
final class CicFileBuilder
{
    public const SEPARATOR = '|';

    public const LINE_END = "\r\n";

    public const TIMEZONE = 'Asia/Manila';

    /** @var list<string> the data record types, in the file's order */
    public const DATA_TYPES = ['ID', 'CI', 'CN', 'NE'];

    public const PROVIDER_CODE_PATTERN = '/^[A-Za-z0-9]{8}$/';

    public const PROVIDER_CODE_REFUSAL = 'The CIC provider code must be exactly 8 letters or digits, as CIC issued it. Set it in CIC Settings first.';

    /** The issue code for a value that would break the file's structure. */
    public const UNSAFE_VALUE = 'unsafe_value';

    /** The current provider code, refused in words unless it is 8 letters or digits. */
    public function providerCode(): string
    {
        $code = trim((string) CicSetting::current()->provider_code);

        if (preg_match(self::PROVIDER_CODE_PATTERN, $code) !== 1) {
            throw CicSubmissionRefusal::unprocessable(self::PROVIDER_CODE_REFUSAL, 'provider_code_invalid');
        }

        return $code;
    }

    public function filename(string $providerCode, CarbonInterface $generatedAt): string
    {
        return $providerCode.'_CSDF_'.$generatedAt->copy()->setTimezone(self::TIMEZONE)->format('YmdHis').'.txt';
    }

    /** The submission's file, rebuilt exactly as it was generated. */
    public function forSubmission(CicSubmission $submission): CicFile
    {
        /** @var CicCapture $capture */
        $capture = $submission->capture()->firstOrFail();

        return $this->build($capture, $submission->submission_type, $submission->comments, $submission->generated_at);
    }

    /**
     * The values that would break the file: a `|`, a line break or text that
     * isn't UTF-8. Issues in the validator's shape, naming the field and the
     * record key only.
     *
     * @return list<array{record_type: string, record_key: string, field_id: string|null, severity: 'error', code: string, message: string}>
     */
    public function unsafeValues(CicCapture $capture): array
    {
        $issues = [];

        foreach ($this->records($capture) as $record) {
            foreach ($record->values ?? [] as $fieldId => $value) {
                if (! self::isSafe($value)) {
                    $issues[] = [
                        'record_type' => $record->record_type,
                        'record_key' => $record->record_key,
                        'field_id' => (string) $fieldId,
                        'severity' => 'error',
                        'code' => self::UNSAFE_VALUE,
                        'message' => 'This value holds a "|", a line break or characters that are not UTF-8, which would break the file.',
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * The TXT. The capture must be complete and its values safe (checked
     * before generation with unsafeValues()).
     */
    public function build(CicCapture $capture, int $submissionType, ?string $comments, CarbonInterface $generatedAt): CicFile
    {
        if (! $capture->isComplete()) {
            throw CicSubmissionRefusal::unprocessable('This capture isn\'t complete, so no file can be made from it.', 'capture_incomplete');
        }

        $provider = $this->providerCode();
        $referenceDate = $capture->reference_date->format('dmY');

        $lines = [$this->line([
            'HD',
            $provider,
            $referenceDate,
            CicSwitch::formatVersion(),
            (string) $submissionType,
            (string) ($comments ?? ''),
        ])];

        $counts = array_fill_keys(self::DATA_TYPES, 0);

        foreach ($this->records($capture) as $record) {
            $lines[] = $this->line($this->fields($record, $provider));
            $counts[$record->record_type]++;
        }

        $total = array_sum($counts);
        $lines[] = $this->line(['FT', $provider, $referenceDate, (string) $total]);

        return new CicFile($this->filename($provider, $generatedAt), implode('', $lines), $total, $counts);
    }

    /**
     * The capture's data records in the file's order.
     *
     * @return iterable<CicCaptureRecord>
     */
    private function records(CicCapture $capture): iterable
    {
        foreach (self::DATA_TYPES as $type) {
            yield from CicCaptureRecord::query()
                ->where('capture_id', $capture->id)
                ->where('record_type', $type)
                ->orderBy('record_key')
                ->orderBy('id')
                ->cursor();
        }
    }

    /** @return list<string> */
    private function fields(CicCaptureRecord $record, string $provider): array
    {
        $values = $record->values ?? [];
        $fields = [];

        foreach (CicLayouts::fields($record->record_type) as $field) {
            $value = match (true) {
                $field['fixed'] !== null => $field['fixed'],
                $field['name'] === 'Provider Code' => $provider,
                default => $values[$field['id']] ?? '',
            };

            if (! self::isSafe($value)) {
                throw CicSubmissionRefusal::unprocessable('A captured value would break the file\'s structure, so it can\'t be generated.', self::UNSAFE_VALUE);
            }

            $fields[] = (string) $value;
        }

        return $fields;
    }

    /** @param  list<string>  $fields */
    private function line(array $fields): string
    {
        return implode(self::SEPARATOR, $fields).self::LINE_END;
    }

    private static function isSafe(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $value = (string) $value;

        return mb_check_encoding($value, 'UTF-8')
            && strpbrk($value, "|\r\n") === false
            && ! str_contains($value, "\u{FEFF}");
    }
}
