<?php

namespace App\Http\Requests\Savings\Report;

/**
 * A savings report's CSV export: the report's own filters, with
 * `reports:export` (and `savings:view`) instead of `reports:view`.
 */
class SavingsExportRequest extends SavingsReportRequest
{
    /** @return list<string> */
    protected function permissions(): array
    {
        return ['reports:export', 'savings:view'];
    }
}
