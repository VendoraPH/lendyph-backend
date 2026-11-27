<?php

namespace App\Services\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderQueueItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cancels planned reminders that are no longer needed, before each planning
 * run: the loan closed (fully paid, restructured, voided), or the period with
 * that number and due date is no longer open (paid, or the due date moved and
 * the new date gets reminders of its own). Rows already being sent or
 * finished are left alone. The dispatcher checks again right before sending,
 * so a payment between two sweeps is still caught.
 */
final class ReminderSweeper
{
    private const TABLE = 'reminder_queue_items';

    public function sweep(): int
    {
        $cancelled = 0;

        foreach (['completed', 'restructured', null] as $status) {
            $ids = $this->open()
                ->join('loans', 'loans.id', '=', self::TABLE.'.loan_id')
                ->whereNotIn('loans.status', Loan::COLLECTIBLE_STATUSES)
                ->when($status !== null, fn (Builder $q) => $q->where('loans.status', $status))
                ->when($status === null, fn (Builder $q) => $q->whereNotIn('loans.status', ['completed', 'restructured']))
                ->pluck(self::TABLE.'.id');
            $cancelled += $this->cancel($ids->all(), 'loan_closed', ReminderEligibility::closedReason($status ?? 'void'));
        }

        $closedPeriod = fn (Builder $schedules, array $statuses) => $schedules
            ->from('amortization_schedules')
            ->whereColumn('amortization_schedules.loan_id', self::TABLE.'.loan_id')
            ->whereColumn('amortization_schedules.period_number', self::TABLE.'.period_number')
            ->whereColumn('amortization_schedules.due_date', self::TABLE.'.due_date')
            ->whereIn('amortization_schedules.status', $statuses);

        $paid = $this->open()
            ->whereNotExists(fn (Builder $q) => $closedPeriod($q, AmortizationSchedule::UNPAID_STATUSES))
            ->whereExists(fn (Builder $q) => $closedPeriod($q, ['paid'])
                ->whereNull('amortization_schedules.closed_by_adjustment_id')
                ->whereNull('amortization_schedules.closed_by_loan_id'))
            ->pluck('id');
        $cancelled += $this->cancel($paid->all(), 'paid', 'Payment received before send time');

        $changed = $this->open()
            ->whereNotExists(fn (Builder $q) => $closedPeriod($q, AmortizationSchedule::UNPAID_STATUSES))
            ->pluck('id');
        $cancelled += $this->cancel($changed->all(), 'schedule_changed', 'Schedule changed: the due date moved or the period closed');

        return $cancelled;
    }

    private function open(): Builder
    {
        return DB::table(self::TABLE)->whereIn(self::TABLE.'.status', ReminderQueueItem::OPEN_STATUSES);
    }

    /**
     * @param  list<int|string>  $ids
     */
    private function cancel(array $ids, string $code, string $reason): int
    {
        $count = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $count += DB::transaction(function () use ($chunk, $code, $reason): int {
                $items = ReminderQueueItem::query()->whereKey($chunk)->whereIn('status', ReminderQueueItem::OPEN_STATUSES)->lockForUpdate()->get();

                foreach ($items as $item) {
                    $item->forceFill(['status' => 'cancelled', 'reason_code' => $code, 'reason' => $reason, 'next_attempt_at' => null])->save();
                    ReminderEvent::forItem('cancelled', $item, $reason);
                }

                return $items->count();
            });
        }

        return $count;
    }
}
