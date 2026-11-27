<?php

namespace App\Services\Savings\Notices;

use Illuminate\Support\Facades\DB;

/**
 * The nightly notices' de-duplication (gap round S4): one row per notice in
 * `savings_notice_log`, under a unique key per account or time deposit and
 * per day-mark, claimed in the same transaction that writes the notice. A
 * key already there means the notice went out, so a re-run or a second job
 * sends nothing again.
 */
class NoticeLog
{
    /** Claim `$key`; false when it was already claimed. */
    public function claim(string $key, string $type, ?int $accountId = null, ?int $timeDepositId = null): bool
    {
        return DB::table('savings_notice_log')->insertOrIgnore([
            'notice_key' => $key,
            'type' => $type,
            'account_id' => $accountId,
            'time_deposit_id' => $timeDepositId,
            'sent_on' => now()->toDateString(),
            'created_at' => now(),
        ]) === 1;
    }
}
