<?php

namespace App\Services\Cic\Contracts;

use App\Models\CicCapture;

/**
 * Checks a capture's records against CIC's 2016 CSDF rules: field, record,
 * cross-record and financial checks, and the workbook's dependency rules.
 *
 * The records slice implements it (`CicValidator`) and binds it; the
 * submissions slice calls it before a file is generated, and refuses the file
 * while any error remains.
 */
interface ValidatesCicCapture
{
    /**
     * Every issue in the capture, errors first.
     *
     * @return list<array{record_type: string, record_key: string, field_id: string|null, severity: 'error'|'warning', code: string, message: string}>
     */
    public function issues(CicCapture $capture): array;
}
