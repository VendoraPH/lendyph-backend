<?php

namespace Tests\Feature\Linda;

use App\Models\Branch;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\User;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Feature\Reminders\Api\UsesReminderApi;
use Tests\TestCase;

/**
 * Linda round 4, the reminders task's section 55: "Did Juan receive his due
 * date reminder?" is a lookup. A question that names a member (by the
 * full-name check or a member code) and mentions reminders gets that
 * member's latest reminders on each loan the caller may see, answered on the
 * server: the model is never asked. It needs `reminders:view` and
 * `borrowers:view`, finds only members in the caller's branches, and never
 * shows a contact, a message or a failure reason.
 */
class LindaMemberRemindersTest extends TestCase
{
    use UsesReminderApi;

    private const URL = '/api/linda/chat';

    private FakeModel $fake;

    private Loan $pedro;

    private Loan $rosa;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'fake');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.monthly_cap', '1000.00');
        config()->set('linda.usd_to_php', '62.00');
        $this->fake = new FakeModel;
        $this->app->instance(FakeModel::class, $this->fake);
        $this->at('2026-09-15 09:00');

        $this->pedro = $this->loanDue('2026-09-15', [], [], ['first_name' => 'Pedro', 'last_name' => 'Reyes']);
        // September 10: an upcoming-due reminder sent for real. September 15: today's failed.
        $this->messageRow(
            $this->queueRow($this->pedro, ['template_type' => 'upcoming', 'fire_date' => '2026-09-10', 'scheduled_at' => now()->subDays(5)]),
            ['test_mode' => false, 'provider' => 'semaphore', 'body' => 'Hi Pedro, PHP 5,000.00 is due on Sep 15.'],
        );
        $this->queueRow($this->pedro, ['status' => 'failed', 'reason_code' => 'invalid_recipient', 'reason' => 'Mobile number 09171234501 looks invalid.']);

        $this->far = $this->farBranch();
        $this->rosa = $this->loanDue('2026-09-15', ['branch_id' => $this->far->id], [], ['branch_id' => $this->far->id, 'first_name' => 'Rosa', 'last_name' => 'Lim']);
        $this->queueRow($this->rosa);
    }

    private function headOffice(): User
    {
        return $this->staff(['reminders:view', 'borrowers:view', 'loans:view', 'branches:view_all', 'linda:use']);
    }

    /** @return array<string, mixed> */
    private function chat(User $user, string $message): array
    {
        return $this->actingAs($user)->postJson(self::URL, ['message' => $message])->assertOk()->json('data');
    }

    private function assertNeverAsked(string $case): void
    {
        $this->assertSame([], $this->fake->requests, $case);
        $this->assertSame([], $this->fake->writeRequests, $case);
    }

    /** @param  array<string, mixed>  $reply */
    private function assertPedrosReminders(array $reply, string $case): void
    {
        $code = $this->pedro->borrower->borrower_code;

        $this->assertSame('borrower_lookup', $reply['intent'], $case);
        $this->assertSame(
            'The latest reminder to Pedro Reyes ('.$code.'), due today by SMS on September 15, 2026 for loan '.$this->pedro->loan_account_number
            .', failed. Their latest reminders are listed below.',
            $reply['answer'],
            $case,
        );
        $this->assertSame([
            ['title' => 'Due today', 'subtitle' => $this->pedro->loan_account_number, 'fields' => [
                ['label' => 'Date', 'value' => '2026-09-15', 'format' => 'date'],
                ['label' => 'Channel', 'value' => 'SMS', 'format' => 'text'],
                ['label' => 'Status', 'value' => 'Failed', 'format' => 'text'],
            ]],
            ['title' => 'Upcoming due', 'subtitle' => $this->pedro->loan_account_number, 'fields' => [
                ['label' => 'Date', 'value' => '2026-09-10', 'format' => 'date'],
                ['label' => 'Channel', 'value' => 'SMS', 'format' => 'text'],
                ['label' => 'Status', 'value' => 'Sent', 'format' => 'text'],
            ]],
        ], $reply['blocks'][0]['items'], $case);

        $json = json_encode($reply, JSON_UNESCAPED_UNICODE);
        foreach (['0917', '63917', '@example.com', '0999', 'Hi Pedro', 'invalid', 'Mobile number'] as $private) {
            $this->assertStringNotContainsString($private, $json, $case);
        }
        $this->assertNeverAsked($case);

        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $log->source, $case);
        $this->assertSame('MemberRemindersAnswer', $log->answer_function, $case);
        $this->assertSame([$this->pedro->borrower_id], $log->member_ids, $case);
        $this->assertSame([$this->pedro->id], $log->loan_ids, $case);
    }

    public function test_a_named_member_gets_their_sent_and_failed_reminders_on_the_server(): void
    {
        $user = $this->headOffice();

        $this->assertPedrosReminders($this->chat($user, 'Did Pedro Reyes receive his due date reminder?'), 'English');
        $this->assertPedrosReminders($this->chat($user, 'Nakatanggap ba si Pedro Reyes ng paalala?'), 'Tagalog');
        $this->assertPedrosReminders($this->chat($user, 'Nadawat ba ni Pedro Reyes ang pahinumdom?'), 'Bisaya');
    }

    public function test_the_member_code_form(): void
    {
        $this->assertPedrosReminders($this->chat($this->headOffice(), 'Did '.$this->pedro->borrower->borrower_code.' get a reminder?'), 'member code');
    }

    public function test_a_caller_without_reminders_view_is_refused_in_words(): void
    {
        $user = $this->staff(['borrowers:view', 'loans:view', 'branches:view_all', 'linda:use']);

        $reply = $this->chat($user, 'Did Pedro Reyes receive his due date reminder?');

        $this->assertSame(LindaWording::denied('member reminders'), $reply['answer']);
        $this->assertSame([], $reply['blocks']);
        $this->assertSame('denied', LindaQuestion::query()->latest('id')->firstOrFail()->outcome);
        $this->assertNeverAsked('no reminders:view');

        // borrowers:view is needed as well.
        $reply = $this->chat($this->staff(['reminders:view', 'loans:view', 'branches:view_all', 'linda:use']), 'Did Pedro Reyes receive his due date reminder?');
        $this->assertSame(LindaWording::denied('member reminders'), $reply['answer']);
        $this->assertNeverAsked('no borrowers:view');
    }

    public function test_a_member_outside_the_callers_branches_reads_like_no_member(): void
    {
        $main = $this->staff(['reminders:view', 'borrowers:view', 'loans:view', 'linda:use']);

        $byName = $this->chat($main, 'Did Rosa Lim receive her reminder?');
        $this->assertSame(LindaWording::noMemberNamed('Rosa Lim'), $byName['answer']);
        $this->assertSame([], $byName['blocks']);

        $code = $this->rosa->borrower->borrower_code;
        $byCode = $this->chat($main, 'Did '.$code.' get a reminder?');
        $this->assertSame(LindaWording::noMemberWithCode($code), $byCode['answer']);

        $this->assertStringNotContainsString($this->rosa->loan_account_number, json_encode([$byName, $byCode]));
        $this->assertNeverAsked('outside the branches');

        // The head office reaches her.
        $this->assertStringStartsWith('The latest reminder to Rosa Lim', $this->chat($this->headOffice(), 'Did Rosa Lim receive her reminder?')['answer']);
    }

    public function test_several_members_of_one_name_are_listed_to_choose_from(): void
    {
        $this->loanDue('2026-09-15');
        $this->loanDue('2026-09-15');

        $reply = $this->chat($this->headOffice(), 'Did Juan Dela Cruz receive his reminder?');

        $this->assertStringStartsWith('I found 2 members named "Juan Dela Cruz" in your branches. Which one?', $reply['answer']);
        $this->assertCount(2, $reply['blocks'][0]['items']);
        $this->assertNeverAsked('several members');
    }

    public function test_a_member_with_no_reminders_says_so(): void
    {
        $quiet = $this->loanDue('2026-09-15', [], [], ['first_name' => 'Lito', 'last_name' => 'Bautista']);

        $reply = $this->chat($this->headOffice(), 'Did Lito Bautista get his payment reminder?');

        $this->assertSame('Lito Bautista ('.$quiet->borrower->borrower_code.') has no payment reminders on the loans you can see.', $reply['answer']);
        $this->assertNeverAsked('no reminders');
    }

    public function test_a_member_question_without_reminder_words_stays_the_member_lookup(): void
    {
        $reply = $this->chat($this->headOffice(), 'What does Pedro Reyes owe?');

        $this->assertSame('MemberLookupAnswer', LindaQuestion::query()->latest('id')->firstOrFail()->answer_function);
        $this->assertStringNotContainsString('reminder', $reply['answer']);
        $this->assertNeverAsked('member lookup');
    }
}
