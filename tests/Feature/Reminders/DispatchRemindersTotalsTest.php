<?php

namespace Tests\Feature\Reminders;

use App\Models\ReminderMessage;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\Drivers\FakeDriver;
use App\Services\Reminders\Drivers\OutgoingReminder;
use App\Services\Reminders\Drivers\ReminderDriver;
use App\Services\Reminders\ReminderDispatcher;
use Tests\TestCase;

/**
 * `reminders:dispatch` reports a message that was only logged (test mode, or
 * a driver that reaches nobody) as "logged", apart from "sent" (Lead's call
 * 6), as the reminders dashboard counts them.
 *
 * A due-today reminder goes by SMS and by email: two messages.
 */
class DispatchRemindersTotalsTest extends TestCase
{
    use BuildsReminderLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
    }

    private function twoMessagesDue(): void
    {
        $this->at('2026-09-15 07:00');
        $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
    }

    public function test_a_log_only_hand_over_is_reported_as_logged_not_sent(): void
    {
        $this->twoMessagesDue();

        $this->artisan('reminders:dispatch')
            ->expectsOutput('Processed 2 reminder(s): 0 sent, 2 logged, 0 retrying, 0 failed, 0 cancelled, 0 skipped; 0 left by a stopped run.')
            ->assertSuccessful();

        $this->assertSame(2, ReminderMessage::query()->where('status', 'sent')->where('test_mode', true)->count());
    }

    public function test_the_run_totals_count_logged_apart_from_sent(): void
    {
        $this->twoMessagesDue();

        $totals = app(ReminderDispatcher::class)->run();

        $this->assertSame(['processed' => 2, 'sent' => 0, 'logged' => 2], array_intersect_key($totals, ['processed' => 0, 'sent' => 0, 'logged' => 0]));
    }

    /** A driver that reaches the member, with live sending on, is "sent". Nothing leaves the test. */
    public function test_a_hand_over_that_reaches_the_member_is_reported_as_sent(): void
    {
        config(['reminders.live_sending' => true]);
        $this->app->instance(FakeDriver::class, new class implements ReminderDriver
        {
            public function name(): string
            {
                return 'fake';
            }

            public function reachesNobody(): bool
            {
                return false;
            }

            public function send(OutgoingReminder $reminder): DriverResult
            {
                return DriverResult::sent('reached-'.$reminder->messageId);
            }

            public function creditsRemaining(): ?int
            {
                return null;
            }
        });
        $this->twoMessagesDue();

        $this->artisan('reminders:dispatch')
            ->expectsOutput('Processed 2 reminder(s): 2 sent, 0 logged, 0 retrying, 0 failed, 0 cancelled, 0 skipped; 0 left by a stopped run.')
            ->assertSuccessful();

        $this->assertSame(2, ReminderMessage::query()->where('status', 'sent')->where('test_mode', false)->count());
    }
}
