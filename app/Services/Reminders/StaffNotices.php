<?php

namespace App\Services\Reminders;

use App\Models\Loan;
use App\Models\ReminderQueueItem;
use App\Notifications\StaffNotification;
use App\Services\Notifications;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The reminders' in-app notices to staff, each sent once.
 *
 * The marker (`reminder_staff_notices`, unique on kind and subject) and the
 * notification commit together, as in NotifyPastDueLoans: a concurrent run
 * inserts no marker and so notifies nobody twice, and a failed send rolls the
 * marker back and is logged. A notice never stops a reminder being recorded.
 */
final class StaffNotices
{
    private const TABLE = 'reminder_staff_notices';

    public function __construct(private readonly Notifications $notifications) {}

    /**
     * Once per member, channel and bad value; the value itself is hashed.
     */
    public function contactInvalid(Loan $loan, string $channel, string $value, string $maskedRecipient, string $reason): bool
    {
        return $this->once(
            StaffNotification::REMINDER_CONTACT_INVALID,
            sprintf('borrower:%d:%s:%s', $loan->borrower_id, $channel, hash('sha256', $value)),
            $loan,
            fn () => $this->notifications->reminderContactInvalid($loan, $channel, $maskedRecipient, $reason),
        );
    }

    /**
     * Once per loan, period and due date, however many channels fire.
     */
    public function followUp(Loan $loan, ReminderQueueItem $item, int $daysPastDue, float $amountDue): bool
    {
        return $this->once(
            StaffNotification::REMINDER_FOLLOW_UP,
            sprintf('loan:%d:period:%d:due:%s', $loan->id, $item->period_number, $item->due_date->toDateString()),
            $loan,
            fn () => $this->notifications->reminderFollowUp($loan, $daysPastDue, $amountDue),
        );
    }

    public function failed(Loan $loan, ReminderQueueItem $item, string $reason): bool
    {
        return $this->once(
            StaffNotification::REMINDER_FAILED,
            'item:'.$item->id,
            $loan,
            fn () => $this->notifications->reminderFailed($loan, $item->channel, $item->recipient_masked, $reason),
        );
    }

    private function once(string $kind, string $subject, Loan $loan, Closure $send): bool
    {
        try {
            return DB::transaction(function () use ($kind, $subject, $send): bool {
                $inserted = DB::table(self::TABLE)->insertOrIgnore(['kind' => $kind, 'subject' => $subject, 'created_at' => now()]);

                if ($inserted === 0) {
                    return false;
                }

                $send();

                return true;
            });
        } catch (Throwable $e) {
            Log::error('A reminders staff notice could not be sent; the reminder was still recorded.', [
                'kind' => $kind,
                'loan_id' => $loan->id,
                'exception' => $e,
            ]);

            return false;
        }
    }
}
