<?php

namespace App\Http\Requests\Reminders;

use App\Services\Reminders\Api\ReminderLists;

/**
 * The message history's filters: the queue's, with `status` a delivery
 * status (frontend `ReminderHistoryFilters`).
 */
class ReminderHistoryFiltersRequest extends ReminderQueueFiltersRequest
{
    /**
     * @return list<string>
     */
    protected function statuses(): array
    {
        return ReminderLists::MESSAGE_STATUSES;
    }
}
