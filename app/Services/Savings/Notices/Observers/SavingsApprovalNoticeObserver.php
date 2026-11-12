<?php

namespace App\Services\Savings\Notices\Observers;

use App\Models\SavingsApproval;
use App\Services\Savings\Notices\ApprovalWaitingNotice;

/**
 * Tells the checkers when a savings approval opens, and the remaining ones
 * when a two-checker request gets its first approval (ApprovalWaitingNotice).
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

    public function updated(SavingsApproval $approval): void
    {
        if ($approval->status === 'pending' && $approval->wasChanged('first_checker_id')
            && $approval->getOriginal('first_checker_id') === null && $approval->first_checker_id !== null) {
            app(ApprovalWaitingNotice::class)->after($approval);
        }
    }
}
