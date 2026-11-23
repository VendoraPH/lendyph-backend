<?php

namespace Tests\Feature\Linda;

use App\Models\Branch;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaText;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Reminders\Api\UsesReminderApi;
use Tests\TestCase;

/**
 * Linda round 4, reminders (Phase 2 reminders task, section 55): the
 * reminders dashboard's own counts (ReminderDashboard) for a day or a
 * period, with "logged" counted apart from "sent", and the failed reminders
 * by member name and loan number, built on the server. It needs
 * `reminders:view`, reads only the caller's branches and never shows a
 * contact detail.
 */
class LindaReminderAnswerTest extends TestCase
{
    use UsesReminderApi;

    private Loan $loan;

    private Loan $failing;

    private Loan $farFailing;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->at('2026-09-15 09:00');

        $this->loan = $this->loanDue('2026-09-15');
        $this->failing = $this->loanDue('2026-09-15', [], [], ['first_name' => 'Pedro', 'last_name' => 'Reyes']);
        $this->far = $this->farBranch();
        $this->farFailing = $this->loanDue('2026-09-15', ['branch_id' => $this->far->id], [], ['branch_id' => $this->far->id, 'first_name' => 'Rosa', 'last_name' => 'Lim']);

        // September 15: one SMS only logged (test mode), one email sent for real,
        // one still queued, and two failed (one in Far Branch).
        $this->messageRow($this->queueRow($this->loan));
        $this->messageRow($this->queueRow($this->loan, ['channel' => 'email']), ['test_mode' => false, 'provider' => 'mailgun']);
        $this->queueRow($this->loan, ['period_number' => 3, 'status' => 'queued']);
        $this->queueRow($this->failing, ['status' => 'failed', 'reason_code' => 'invalid_recipient', 'reason' => 'Mobile number 09171234502 looks invalid.']);
        $this->queueRow($this->farFailing, ['status' => 'failed', 'reason_code' => 'invalid_recipient']);

        // September 10: one logged and one failed.
        $this->messageRow($this->queueRow($this->loan, ['period_number' => 4, 'fire_date' => '2026-09-10', 'scheduled_at' => now()->subDays(5)]));
        $this->queueRow($this->loan, ['period_number' => 5, 'fire_date' => '2026-09-10', 'scheduled_at' => now()->subDays(5), 'status' => 'failed']);
    }

    private function headOffice(array $extra = []): User
    {
        return $this->staff(['reminders:view', 'loans:view', 'branches:view_all', ...$extra]);
    }

    private function ask(User $user, ?string $question, ?LindaPeriod $period = null, ?string $branch = null): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, LindaIntent::ReminderSummary, $period, $branch, $question);
    }

    /** @return array<string, mixed> */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'stats' ? $block['items'] : [] as $item) {
                $values[$item['label']] = $item['value'];
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function rows(LindaReply $reply): array
    {
        $rows = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'list' ? $block['items'] : [] as $item) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /** @param  array<string, int>  $counts  the dashboard's counts */
    private function assertCountsMatch(array $counts, LindaReply $reply, string $case): void
    {
        $stats = $this->stats($reply);

        $this->assertSame([
            'Reminders Scheduled' => $counts['today'],
            'Sent' => $counts['sent'],
            'Logged (Test Mode)' => $counts['logged'],
            'Pending' => $counts['pending'],
            'Failed' => $counts['failed'],
            'SMS Sent' => $counts['sms_sent'],
            'SMS Logged' => $counts['sms_logged'],
            'Emails Sent' => $counts['emails_sent'],
            'Emails Logged' => $counts['emails_logged'],
        ], $stats, $case);
    }

    public function test_a_days_counts_equal_the_reminders_dashboard(): void
    {
        foreach (['head office' => $this->headOffice(), 'Main Branch' => $this->staff(['reminders:view', 'loans:view'])] as $case => $user) {
            $counts = $this->actingAs($user)->getJson('/api/reminders/dashboard?date=2026-09-15')->assertOk()->json('data.counts');

            $this->assertCountsMatch($counts, $this->ask($user, 'How many reminders were sent today?', LindaPeriod::named('today')), $case);
            // No period: today, as the dashboard opens.
            $this->assertCountsMatch($counts, $this->ask($user, 'How many reminders failed?'), $case.', no period');
        }

        $this->assertSame(['today' => 5, 'sent' => 1, 'logged' => 1, 'pending' => 1, 'failed' => 2], array_intersect_key(
            $this->actingAs($this->headOffice())->getJson('/api/reminders/dashboard?date=2026-09-15')->json('data.counts'),
            array_flip(['today', 'sent', 'logged', 'pending', 'failed']),
        ));
    }

    public function test_a_periods_counts_add_up_its_days_on_the_dashboard(): void
    {
        $user = $this->headOffice();
        $period = LindaPeriod::extract(LindaText::clean('September 10 to 15, 2026'))['period'];
        $this->assertInstanceOf(LindaPeriod::class, $period);

        $sum = [];
        for ($day = 10; $day <= 15; $day++) {
            foreach ($this->actingAs($user)->getJson('/api/reminders/dashboard?date=2026-09-'.$day)->assertOk()->json('data.counts') as $key => $count) {
                $sum[$key] = ($sum[$key] ?? 0) + $count;
            }
        }

        $reply = $this->ask($user, 'How many reminders were sent from September 10 to 15?', $period);

        $this->assertCountsMatch($sum, $reply, 'September 10 to 15');
        $this->assertSame(2, $this->stats($reply)['Logged (Test Mode)']);
        $this->assertSame(3, $this->stats($reply)['Failed']);
    }

    public function test_logged_is_told_apart_from_sent_in_words(): void
    {
        $reply = $this->ask($this->headOffice(), 'How many payment reminders were sent today?', LindaPeriod::named('today'));

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('ReminderSummaryAnswer', $reply->answerFunction);
        $this->assertSame(
            'On September 15, 2026, 5 reminders were scheduled across all branches: 1 was sent to members, 1 was only logged (test mode), '
            .'1 is still pending and 2 failed. By channel: SMS 0 sent and 1 logged; email 1 sent and 0 logged. The failed reminders are listed below. '
            .'Reminders are in test mode, so new messages are only logged and never reach members.',
            $reply->answer,
        );
    }

    public function test_the_failed_list_names_each_member_and_loan_and_never_a_contact(): void
    {
        $reply = $this->ask($this->headOffice(), 'Which members have failed reminders today?', LindaPeriod::named('today'));
        $rows = $this->rows($reply);

        $this->assertSame(['Pedro Reyes', 'Rosa Lim'], array_column($rows, 'title'));
        $this->assertSame([$this->failing->loan_account_number, $this->farFailing->loan_account_number], array_column($rows, 'subtitle'));
        $this->assertSame([['label' => 'Channel', 'value' => 'SMS', 'format' => 'text'], ['label' => 'Reminder Date', 'value' => '2026-09-15', 'format' => 'date'], ['label' => 'Due Date', 'value' => '2026-09-15', 'format' => 'date']], $rows[0]['fields']);
        $this->assertSame([$this->failing->borrower_id, $this->farFailing->borrower_id], $reply->memberIds);
        $this->assertSame([$this->failing->id, $this->farFailing->id], $reply->loanIds);

        $json = json_encode($reply->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['0917', '63917', '@example.com', '0999', 'invalid', 'Mobile number'] as $contact) {
            $this->assertStringNotContainsString($contact, $json);
        }

        // A Main Branch user sees only Main Branch's.
        $main = $this->ask($this->staff(['reminders:view', 'loans:view']), 'Which members have failed reminders today?', LindaPeriod::named('today'));
        $this->assertSame(['Pedro Reyes'], array_column($this->rows($main), 'title'));
        $this->assertStringNotContainsString('Rosa', json_encode($main->toArray()));
    }

    public function test_it_needs_reminders_view_and_reads_only_the_callers_branches(): void
    {
        $denied = $this->ask($this->staff(['loans:view', 'branches:view_all']), 'How many reminders were sent today?');
        $this->assertSame('denied', $denied->outcome);
        $this->assertSame('reminder_summary', $denied->intent);
        $this->assertSame([], $denied->blocks);
        $this->assertSame([], $denied->links);
        $this->assertDoesNotMatchRegularExpression('/\d/', $denied->answer);

        $this->assertSame('no_branch', $this->ask($this->staff(['reminders:view', 'loans:view']), 'Reminders sent today in Far branch', null, $this->far->name)->outcome);

        $far = $this->ask($this->headOffice(), 'Reminders sent today in Far branch', LindaPeriod::named('today'), $this->far->name);
        $farCounts = $this->actingAs($this->headOffice())->getJson('/api/reminders/dashboard?date=2026-09-15&branch_id='.$this->far->id)->assertOk()->json('data.counts');
        $this->assertCountsMatch($farCounts, $far, 'Far Branch');
        $this->assertSame(['Rosa Lim'], array_column($this->rows($far), 'title'));
        $this->assertStringContainsString('for '.$this->far->name, $far->answer);
    }

    public function test_it_links_to_the_reminders_page_the_caller_can_open(): void
    {
        $this->assertSame([['label' => 'Open Reminders', 'url' => '/loans/reminders']], $this->ask($this->headOffice(), 'Reminders today')->links);
        $this->assertSame([], $this->ask($this->staff(['reminders:view', 'branches:view_all']), 'Reminders today')->links);
    }

    public function test_a_day_with_no_reminders_says_so(): void
    {
        $reply = $this->ask($this->headOffice(), 'How many reminders were sent yesterday?', LindaPeriod::named('yesterday'));

        $this->assertSame('answered', $reply->outcome);
        $this->assertStringStartsWith('No reminders were scheduled on September 14, 2026 across all branches.', $reply->answer);
        $this->assertSame([], $this->rows($reply));
    }

    public function test_the_chat_answers_on_the_server_and_logs_no_text(): void
    {
        config()->set('linda.enabled', true);
        $user = $this->headOffice(['linda:use']);

        $reply = $this->actingAs($user)->postJson('/api/linda/chat', ['message' => 'How many payment reminders were sent today?'])->assertOk()->json('data');

        $this->assertSame('reminder_summary', $reply['intent']);
        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_MATCHER, $log->source);
        $this->assertSame('reminder_summary', $log->intent);
        $this->assertSame('2026-09-15', $log->period_from?->toDateString());
        $this->assertStringNotContainsString('reminders were sent', json_encode($log->getAttributes()));
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function questions(): array
    {
        return [
            'en sent today' => ['How many payment reminders were sent today?', 'today', null],
            'en failed members' => ['Which borrowers have failed reminders?', null, null],
            'en failed this week' => ['How many reminders failed this week?', 'this_week', null],
            'en sms yesterday' => ['SMS reminders sent yesterday', 'yesterday', null],
            'en overdue reminders' => ['How many overdue reminders were logged last week?', 'last_week', null],
            'en in Main' => ['How many reminders were sent in Main branch this month?', 'this_month', 'main'],
            'tl naipadala ngayon' => ['Ilan ang mga paalala na naipadala ngayon?', 'today', null],
            'tl failed' => ['Sinong mga miyembro ang may failed reminders?', null, null],
            'ceb napadala karon' => ['Pila ka reminders ang napadala karon?', 'today', null],
            'ceb na-fail sa Main' => ['Pila ka reminders ang na-fail sa Main?', null, 'main'],
        ];
    }

    #[DataProvider('questions')]
    public function test_reminder_questions_route_to_the_reminder_intent(string $question, ?string $period, ?string $branch): void
    {
        $match = LindaMatcher::match($question);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::ReminderSummary, $match->intent, $question);
        $this->assertSame($period, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
    }
}
