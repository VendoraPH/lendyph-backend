<?php

namespace App\Services\LoanOrigination\Staff\Observers;

use App\Models\OriginationStatusHistory;
use App\Services\LoanOrigination\Staff\OriginationStaffNotices;

/**
 * Every status move writes one history row (ApplicationTransitions::move()),
 * whoever made it: the applicant's submit, a staff move, the system's expiry
 * or the loan the application follows. Watching the row tells staff about
 * each of them from one place, without touching the transition table
 * (OriginationStaffNotices, sent after commit).
 */
class OriginationStatusHistoryObserver
{
    public function __construct(private readonly OriginationStaffNotices $notices) {}

    public function created(OriginationStatusHistory $row): void
    {
        $this->notices->afterMove($row);
    }
}
