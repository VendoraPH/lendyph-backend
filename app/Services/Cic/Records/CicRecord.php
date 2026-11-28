<?php

namespace App\Services\Cic\Records;

use App\Models\CicCaptureRecord;

/**
 * One built CSDF record (ID, CI, CN or NE), before or after it is captured.
 *
 * - `values`: field id → the CSDF string, already formatted; empty positions
 *   are left out.
 * - `buildIssues`: what the builder found that the values can't show, in the
 *   validator's issue shape without the record type and key.
 * - `borrowerId`: the member the record is about (the subject, the contract's
 *   member or the event's member), for the screens' masked name and code.
 * - `structure`: a contract's record type by its own structure (CI or CN),
 *   which the first capture freezes in `loans.cic_record_type`.
 *
 * @phpstan-type BuildIssue array{field_id: string|null, severity: 'error'|'warning', code: string, message: string}
 */
final class CicRecord
{
    /**
     * @param  array<string, string>  $values
     * @param  list<BuildIssue>  $buildIssues
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly array $values,
        public readonly array $buildIssues = [],
        public readonly ?int $borrowerId = null,
        public readonly ?int $loanId = null,
        public readonly ?string $structure = null,
    ) {}

    /** The same record under another key, with more build issues. */
    public function rekeyed(string $key, array $issues = []): self
    {
        return new self($this->type, $key, $this->values, [...$this->buildIssues, ...$issues], $this->borrowerId, $this->loanId, $this->structure);
    }

    public function checksum(): string
    {
        return CicCaptureRecord::checksumOf($this->values);
    }
}
