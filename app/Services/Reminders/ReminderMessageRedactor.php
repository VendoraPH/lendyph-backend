<?php

namespace App\Services\Reminders;

use App\Models\Loan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Blanks the recipient, subject and text of reminder messages once their loan
 * has been closed for `reminders.retention_years` (2). The row stays, masked
 * recipient, status and times included, so counts and history still add up.
 *
 * There is no `closed_at` on loans: a loan that is no longer collectible
 * (completed, restructured, void) counts as closed at its `updated_at`. That
 * is never earlier than the real close, so text is never blanked early, only
 * possibly later.
 */
final class ReminderMessageRedactor
{
    public function pending(Carbon $cutoff): int
    {
        return $this->candidates($cutoff)->count();
    }

    public function redact(Carbon $cutoff): int
    {
        $redacted = 0;

        do {
            $ids = $this->candidates($cutoff)->orderBy('reminder_messages.id')->limit(500)->pluck('reminder_messages.id')->all();

            if ($ids === []) {
                break;
            }

            $redacted += DB::table('reminder_messages')
                ->whereIn('id', $ids)
                ->whereNull('redacted_at')
                ->update(['recipient' => null, 'subject' => null, 'body' => null, 'redacted_at' => now(), 'updated_at' => now()]);
        } while (count($ids) === 500);

        return $redacted;
    }

    private function candidates(Carbon $cutoff): Builder
    {
        return DB::table('reminder_messages')
            ->join('loans', 'loans.id', '=', 'reminder_messages.loan_id')
            ->whereNull('reminder_messages.redacted_at')
            ->whereNotIn('loans.status', Loan::COLLECTIBLE_STATUSES)
            ->where('loans.updated_at', '<=', $cutoff);
    }
}
