<?php

namespace App\Services\Savings\Notices\Observers;

use App\Models\AuditLog;
use App\Services\Savings\Notices\AccountEventNotices;

/**
 * Sends a savings account's status-event notices (AccountEventNotices) when
 * the audit row for the event is written. Registered by
 * SavingsNoticesServiceProvider; only reads the row.
 */
class AuditLogNoticeObserver
{
    public function created(AuditLog $row): void
    {
        if (AccountEventNotices::handles($row)) {
            app(AccountEventNotices::class)->after($row);
        }
    }
}
