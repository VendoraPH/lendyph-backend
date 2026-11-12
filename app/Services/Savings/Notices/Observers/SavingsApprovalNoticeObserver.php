<?php

namespace App\Services\Savings\Notices\Observers;

use App\Models\SavingsApproval;
use App\Services\Savings\Notices\ApprovalWaitingNotice;

/**
 * Tells the checkers when a savings approval opens (ApprovalWaitingNotice).
 * Registered by SavingsNoticesServiceProvider.
 */
class SavingsApprovalNoticeObserver
{
    public function created(SavingsApproval $approval): void
    {
        if ($approval->status === 'pending') {
            app(ApprovalWaitingNotice::class)->after($approval);
        }
    }
}
