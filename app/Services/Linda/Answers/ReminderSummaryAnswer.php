<?php

namespace App\Services\Linda\Answers;

use App\Models\ReminderQueueItem;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaScope;
use App\Services\Linda\LindaWording as W;
use App\Services\Reminders\Api\ReminderDashboard;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Support\Collection;

/**
 * "How many payment reminders were sent today?", "Which members have failed
 * reminders?" (Linda round 4, the reminders task's section 55): the reminders
 * dashboard's own counts (ReminderDashboard::counts(), the counts of GET
 * /reminders/dashboard, over each day of a period) for the day or period
 * asked, today when none is named. Reminders scheduled, sent, only logged
 * (test mode: never counted as sent, as decided), still pending and failed,
 * and by channel.
 *
 * The failed reminders are listed by member name and loan number, built here
 * on the server, never sent to the model. A row shows its channel and dates,
 * never a contact detail or the failure's reason, which can quote one.
 *
 * It needs `reminders:view`, as the dashboard does, and reads only the loans
 * the caller can see (ReminderScope), narrowed to a named branch.
 */
final class ReminderSummaryAnswer extends Answer
{
    /** @var array<string, string> each channel in words */
    private const CHANNELS = ['sms' => 'SMS', 'email' => 'Email'];

    public function __construct(private readonly ReminderDashboard $dashboard) {}

    public function intent(): LindaIntent
    {
        return LindaIntent::ReminderSummary;
    }

    public function allows(User $user): bool
    {
        return $user->can('reminders:view');
    }

    protected function subject(): string
    {
        return 'reminder figures';
    }

    public function build(User $user, LindaScope $scope, ?LindaPeriod $period, #[\SensitiveParameter] ?string $question = null): LindaReply
    {
        $period ??= LindaPeriod::named('today');
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();
        $filters = $scope->branchId === null ? [] : ['branch_id' => $scope->branchId];

        $counts = $this->dashboard->counts($user, $filters, $from, $to);
        $failed = $this->dashboard->failed($user, $filters, $from, $to, max(1, (int) config('linda.list_max', 10)));
        $mode = $this->dashboard->mode();

        $answer = self::summary($counts, $period, $scope, $failed->count());

        if ($mode['test_mode']) {
            $answer .= ' Reminders are in test mode, so new messages are only logged and never reach members.';
        }

        if (! $mode['enabled']) {
            $answer .= ' Reminders are switched off, so no new ones are planned.';
        }

        $blocks = [LindaReply::stats([
            LindaReply::number('Reminders Scheduled', $counts['today']),
            LindaReply::number('Sent', $counts['sent']),
            LindaReply::number('Logged (Test Mode)', $counts['logged']),
            LindaReply::number('Pending', $counts['pending']),
            LindaReply::number('Failed', $counts['failed']),
            LindaReply::number('SMS Sent', $counts['sms_sent']),
            LindaReply::number('SMS Logged', $counts['sms_logged']),
            LindaReply::number('Emails Sent', $counts['emails_sent']),
            LindaReply::number('Emails Logged', $counts['emails_logged']),
        ], self::sentence(W::during($period)))];

        if ($failed->isNotEmpty()) {
            $blocks[] = LindaReply::list('Failed reminders', $failed->map(fn (ReminderQueueItem $item): array => [
                'title' => (string) $item->borrower?->full_name,
                'subtitle' => ReminderFormat::loanNumber($item->loan),
                'fields' => [
                    LindaReply::text('Channel', self::CHANNELS[$item->channel] ?? ucfirst((string) $item->channel)),
                    LindaReply::date('Reminder Date', $item->fire_date->toDateString()),
                    LindaReply::date('Due Date', $item->due_date->toDateString()),
                ],
            ])->values()->all());
        }

        return $this->reply(
            $answer,
            $blocks,
            LindaLinks::for($user, 'reminders'),
            self::ids($failed, 'borrower_id'),
            self::ids($failed, 'loan_id'),
        );
    }

    /**
     * The counts in words: "On September 15, 2026, 5 reminders were scheduled
     * across all branches: 1 was sent to members, 1 was only logged (test
     * mode), 1 is still pending and 2 failed. By channel: …"
     *
     * @param  array<string, int>  $counts  ReminderDashboard::counts()
     */
    private static function summary(array $counts, LindaPeriod $period, LindaScope $scope, int $listed): string
    {
        $scheduled = $counts['today'];

        if ($scheduled === 0 && $counts['sent'] === 0 && $counts['logged'] === 0 && $counts['failed'] === 0) {
            return 'No reminders were scheduled '.W::during($period).' '.W::forScope($scope).'.';
        }

        $text = self::sentence(W::during($period)).', '.W::count($scheduled, 'reminder').' '.self::was($scheduled).' scheduled '.W::forScope($scope).': '
            .number_format($counts['sent']).' '.self::was($counts['sent']).' sent to members, '
            .number_format($counts['logged']).' '.self::was($counts['logged']).' only logged (test mode), '
            .number_format($counts['pending']).' '.($counts['pending'] === 1 ? 'is' : 'are').' still pending and '
            .number_format($counts['failed']).' failed. By channel: SMS '.number_format($counts['sms_sent']).' sent and '
            .number_format($counts['sms_logged']).' logged; email '.number_format($counts['emails_sent']).' sent and '
            .number_format($counts['emails_logged']).' logged.';

        if ($counts['failed'] > $listed) {
            return $text.' The latest '.number_format($listed).' failed reminders are listed below; open Reminders for the rest.';
        }

        return $counts['failed'] === 0 ? $text : $text.' The failed '.($counts['failed'] === 1 ? 'reminder is' : 'reminders are').' listed below.';
    }

    private static function was(int $count): string
    {
        return $count === 1 ? 'was' : 'were';
    }

    /**
     * @param  Collection<int, ReminderQueueItem>  $items
     * @return list<int>
     */
    private static function ids(Collection $items, string $column): array
    {
        return array_values(array_unique(array_map('intval', $items->pluck($column)->filter()->all())));
    }
}
