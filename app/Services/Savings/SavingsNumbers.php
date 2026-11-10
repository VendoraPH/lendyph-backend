<?php

namespace App\Services\Savings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers savings records carry (design §1), each the highest so far plus
 * one, read plainly (lock step 6): two writers can read the same number, and
 * the unique index refuses the second, which GeneratedNumberWrite retries
 * (its INDEXES list these three) and answers 409 after five tries.
 *
 * - account: `SAV-YYYY-NNNNNN`, per year;
 * - transaction: `SVT-YYYYMMDD-NNNNNN`, per day;
 * - time deposit certificate: `TD-YYYY-NNNNNN`, per year;
 * - interest row: `SVI-{run}-{n}`, numbered by the run itself.
 */
class SavingsNumbers
{
    public function accountNumber(string $date): string
    {
        return $this->next('savings_accounts', 'account_number', 'SAV-'.CarbonImmutable::parse($date)->format('Y').'-');
    }

    public function transactionReference(string $date): string
    {
        return $this->next('savings_transactions', 'reference', 'SVT-'.CarbonImmutable::parse($date)->format('Ymd').'-');
    }

    public function certificateNumber(string $date): string
    {
        return $this->next('time_deposits', 'certificate_number', 'TD-'.CarbonImmutable::parse($date)->format('Y').'-');
    }

    public function interestReference(int $runId, int $line): string
    {
        return "SVI-{$runId}-{$line}";
    }

    private function next(string $table, string $column, string $prefix): string
    {
        $last = DB::table($table)
            ->where($column, 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->max($column);

        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
