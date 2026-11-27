<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\SavingsApproval;
use App\Services\Savings\Notices\Observers\AuditLogNoticeObserver;
use App\Services\Savings\Notices\Observers\SavingsApprovalNoticeObserver;
use Illuminate\Support\ServiceProvider;

/**
 * The savings in-app notices that follow an event (gap round S4): an
 * account's status events, read from their audit rows, and "approval
 * waiting" when a savings approval opens. Both only notify, after commit,
 * and never change or fail the write that set them off.
 */
class SavingsNoticesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AuditLog::observe(AuditLogNoticeObserver::class);
        SavingsApproval::observe(SavingsApprovalNoticeObserver::class);
    }
}
