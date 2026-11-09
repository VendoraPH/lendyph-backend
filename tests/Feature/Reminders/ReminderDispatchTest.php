<?php

namespace Tests\Feature\Reminders;

use App\Models\Borrower;
use App\Models\BorrowerNotificationPreference;
use App\Models\BrandingSetting;
use App\Models\ReminderMessage;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Notifications\StaffNotification;
use App\Services\Reminders\Drivers\DriverResult;
use App\Services\Reminders\ReminderDispatcher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `reminders:dispatch`: the final check right before the driver call, the
 * text that is handed over, retries, and what a failure leaves behind.
 */
class ReminderDispatchTest extends TestCase
{
    use BuildsReminderLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
        BrandingSetting::query()->updateOrCreate([], ['organization_name' => 'Binhs Coop', 'organization_contact' => '0917 000 1111']);
    }

    public function test_a_partly_paid_period_quotes_what_is_still_due_in_pesos_as_php_on_sms(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();

        // ₱5,000.00 period, ₱2,000.00 paid (interest first, then principal).
        $this->payPeriod($loan, 1000, 1000);
        $this->at('2026-09-15 08:00');
        $this->plan();
        $this->dispatchReminders();

        $sms = $this->driver->sentTo('sms')->last();
        $this->assertNotNull($sms);
        $this->assertStringContainsString('PHP 3,000.00', $sms->body);
        $this->assertStringNotContainsString('5,000', $sms->body);
        $this->assertStringNotContainsString('₱', $sms->body);
        $this->assertStringContainsString('due today, Sep 15, 2026', $sms->body);
        $this->assertSame('sent', $this->item($loan, 'Due today')->status);
        $this->assertSame(3000.0, (float) $this->item($loan, 'Due today')->amount_due);
    }

    public function test_email_renders_the_peso_sign_and_due_today_goes_by_sms_and_email(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $email = $this->driver->sentTo('email')->last();
        $this->assertNotNull($email);
        $this->assertStringContainsString('₱5,000.00', $email->body);
        $this->assertStringNotContainsString('PHP 5,000.00', $email->body);
        $this->assertSame('Payment due today for loan '.$loan->loan_account_number, $email->subject);
        $this->assertSame('juan1@example.com', $email->recipient);
        $this->assertSame('sent', $this->item($loan, 'Due today', 'sms')->status);
        $this->assertSame('sent', $this->item($loan, 'Due today', 'email')->status);

        $message = ReminderMessage::query()->where('queue_item_id', $this->item($loan, 'Due today', 'email')->id)->firstOrFail();
        $this->assertSame($email->body, $message->body);
        $this->assertTrue($message->test_mode);
        $this->assertSame('ju***@example.com', $message->recipient_masked);
    }

    public function test_every_variable_and_alias_renders(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        ReminderSetting::current()->update(['payment_instructions' => 'Pay at any branch or via GCash.']);
        $variables = ['borrower_first_name', 'borrower_full_name', 'loan_number', 'installment_number', 'due_date', 'amount_due',
            'remaining_balance', 'days_overdue', 'lender_name', 'branch_name', 'branch_phone', 'payment_method',
            'remaining_amount', 'company_name', 'company_phone', 'payment_reference'];
        ReminderTemplate::query()->where('type', 'upcoming')->where('channel', 'sms')
            ->update(['body' => implode('|', array_map(fn ($v) => '{{ '.$v.' }}', $variables))]);

        $this->plan();
        $this->at('2026-09-12 09:00');
        $this->dispatchReminders();

        $body = $this->driver->sentTo('sms')->last()->body;
        $this->assertStringNotContainsString('{{', $body);
        $this->assertSame([
            'Juan', 'Juan Dela Cruz', $loan->loan_account_number, '1', 'Sep 15, 2026', 'PHP 5,000.00',
            'PHP 5,000.00', '0', 'Binhs Coop', 'Main Branch', '0917 000 1111', 'Pay at any branch or via GCash.',
            'PHP 5,000.00', 'Binhs Coop', '0917 000 1111', $loan->loan_account_number,
        ], explode('|', $body));
    }

    public function test_a_template_with_an_unknown_variable_is_never_sent(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        ReminderTemplate::query()->where('type', 'upcoming')->where('channel', 'sms')->update(['body' => 'Hi {{nickname}}']);
        $this->plan();
        $this->at('2026-09-12 09:00');
        $this->dispatchReminders();

        $this->assertSame('skipped', $this->item($loan, 'Upcoming due')->status);
        $this->assertSame('template_unavailable', $this->item($loan, 'Upcoming due')->reason_code);
        $this->assertCount(0, $this->driver->sentTo('sms'));
    }

    public function test_paid_between_planning_and_sending_cancels_at_the_final_check(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();

        $this->payPeriod($loan, 4000, 1000);
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame('cancelled', $this->item($loan, 'Due today')->status);
        $this->assertSame('Payment received before send time', $this->item($loan, 'Due today')->reason);
        $this->assertCount(0, $this->driver->calls);
        $this->assertSame(0, ReminderMessage::query()->count());
    }

    public function test_dispatching_twice_and_a_second_dispatcher_give_one_message(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->at('2026-09-15 08:00');

        // A second dispatcher started at the same moment claims nothing the
        // first one holds: the claim is one UPDATE ... LIMIT.
        $first = app(ReminderDispatcher::class);
        $second = app(ReminderDispatcher::class);
        $claimed = $first->claim(10);
        $this->assertSame(2, $claimed->count());
        $this->assertSame(0, $second->claim(10)->count());
        foreach ($claimed as $item) {
            $first->process($item);
        }

        $this->dispatchReminders();
        $this->dispatchReminders();

        $this->assertCount(1, $this->driver->sentTo('sms'));
        $this->assertCount(1, $this->driver->sentTo('email'));
        $this->assertSame(2, ReminderMessage::query()->where('loan_id', $loan->id)->count());
    }

    public function test_nothing_is_sent_outside_contact_hours(): void
    {
        ReminderSetting::current()->update(['contact_hours_start' => '08:00', 'contact_hours_end' => '18:00']);
        $this->at('2026-09-15 06:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['send_time' => '07:00']);
        $this->plan();

        // 07:00 is before the window opens: planned at 08:00 instead.
        $this->assertSame('08:00', $this->item($loan, 'Due today')->scheduled_at->format('H:i'));
        $this->at('2026-09-15 07:59');
        $this->dispatchReminders();
        $this->assertCount(0, $this->driver->calls);
        $this->assertSame('scheduled', $this->item($loan, 'Due today')->status);

        // Missed the whole window (the dispatcher was down): never sent late.
        $this->at('2026-09-15 18:00');
        $this->dispatchReminders();
        $this->assertCount(0, $this->driver->calls);
        $this->assertSame('skipped', $this->item($loan, 'Due today')->status);
        $this->assertSame('outside_contact_hours', $this->item($loan, 'Due today')->reason_code);
    }

    public function test_the_window_is_held_within_six_am_to_ten_pm_whatever_is_stored(): void
    {
        DB::table('reminder_settings')->update(['contact_hours_start' => '05:00', 'contact_hours_end' => '23:30']);
        $this->at('2026-09-15 05:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['send_time' => '05:30']);
        $this->plan();

        $this->assertSame('06:00', $this->item($loan, 'Due today')->scheduled_at->format('H:i'));
        $this->at('2026-09-15 22:00');
        $this->dispatchReminders();
        $this->assertCount(0, $this->driver->calls);
    }

    public function test_each_pause_scope_and_an_opted_out_member_are_skipped_with_a_reason(): void
    {
        $this->at('2026-09-15 07:00');
        $loanPaused = $this->loanDue('2026-09-15');
        $memberPaused = $this->loanDue('2026-09-15');
        $optedOut = $this->loanDue('2026-09-15');
        $normal = $this->loanDue('2026-09-15');
        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $loanPaused->id, 'reason' => 'Payment under verification', 'paused_by' => $this->admin->id, 'paused_at' => now()]);
        ReminderPause::query()->create(['scope' => 'borrower', 'borrower_id' => $memberPaused->borrower_id, 'reason' => 'Special payment arrangement', 'paused_by' => $this->admin->id, 'paused_at' => now()]);
        BorrowerNotificationPreference::query()->create(['borrower_id' => $optedOut->borrower_id, 'sms_enabled' => false]);
        $this->plan();

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame(['skipped', 'paused', 'Reminders are paused for this loan: Payment under verification'],
            $this->outcome($this->item($loanPaused, 'Due today')));
        $this->assertSame(['skipped', 'paused', 'Reminders are paused for this member: Special payment arrangement'],
            $this->outcome($this->item($memberPaused, 'Due today')));
        $this->assertSame(['skipped', 'opted_out', 'Member turned off SMS reminders'], $this->outcome($this->item($optedOut, 'Due today')));
        $this->assertSame('sent', $this->item($optedOut, 'Due today', 'email')->status);
        $this->assertSame('sent', $this->item($normal, 'Due today')->status);
    }

    public function test_a_global_pause_lets_nothing_leave(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        ReminderPause::query()->create(['scope' => 'global', 'reason' => 'Provider problems', 'paused_by' => $this->admin->id, 'paused_at' => now()]);

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame(['skipped', 'paused', 'All reminders are paused: Provider problems'], $this->outcome($this->item($loan, 'Due today')));
        $this->assertCount(0, $this->driver->calls);
    }

    public function test_a_resumed_or_expired_pause_no_longer_applies(): void
    {
        $this->at('2026-09-15 07:00');
        $resumed = $this->loanDue('2026-09-15');
        $expired = $this->loanDue('2026-09-15');
        $pause = ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $resumed->id, 'reason' => 'Checking', 'paused_by' => $this->admin->id, 'paused_at' => now()]);
        $pause->update(['resumed_at' => now(), 'resumed_by' => $this->admin->id]);
        ReminderPause::query()->create(['scope' => 'loan', 'loan_id' => $expired->id, 'reason' => 'Until 07:30', 'paused_by' => $this->admin->id, 'paused_at' => now(), 'until' => now()->addMinutes(30)]);
        $this->plan();

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame('sent', $this->item($resumed, 'Due today')->status);
        $this->assertSame('sent', $this->item($expired, 'Due today')->status);
        $this->assertSame(['paused', 'resumed', 'paused'], DB::table('reminder_events')->whereIn('kind', ['paused', 'resumed'])->orderBy('id')->pluck('kind')->all());
    }

    public function test_a_landline_or_missing_mobile_is_skipped_and_never_retried(): void
    {
        $this->at('2026-09-15 07:00');
        $landline = $this->loanDue('2026-09-15', [], [], ['contact_number' => '(088) 856 1234']);
        $missing = $this->loanDue('2026-09-15', [], [], ['contact_number' => null, 'email' => null]);
        $this->plan();

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();
        $this->at('2026-09-15 09:00');
        $this->dispatchReminders();

        $this->assertSame(['skipped', 'invalid_contact', 'Mobile number is not a valid mobile number'], $this->outcome($this->item($landline, 'Due today')));
        $this->assertSame(['skipped', 'no_contact', 'Member has no mobile number'], $this->outcome($this->item($missing, 'Due today')));
        $this->assertSame(['skipped', 'no_contact', 'Member has no email address'], $this->outcome($this->item($missing, 'Due today', 'email')));
        $this->assertSame(0, $this->item($landline, 'Due today')->attempts);
        $this->assertCount(0, $this->driver->sentTo('sms'));
    }

    public function test_the_member_is_the_only_person_ever_contacted(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15', [], [], ['contact_number' => '0917-555-0101', 'spouse_contact_number' => '09170000000']);
        $this->plan();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertSame(['639175550101'], $this->driver->sentTo('sms')->pluck('recipient')->all());
        $this->assertSame('63917*****01', $this->item($loan, 'Due today')->recipient_masked);
    }

    public function test_the_fake_driver_fails_twice_then_succeeds_on_the_third_attempt(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['channels' => ['sms']]);
        $this->plan();
        $this->driver->willReturn('sms', DriverResult::temporaryFailure('provider_down', 'Provider unavailable'), DriverResult::temporaryFailure('provider_down', 'Provider unavailable'));

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();
        $item = $this->item($loan, 'Due today');
        $this->assertSame(['queued', 1], [$item->status, $item->attempts]);
        $this->assertSame('2026-09-15 08:15', $item->next_attempt_at->format('Y-m-d H:i'));

        // Not before the retry delay.
        $this->at('2026-09-15 08:10');
        $this->dispatchReminders();
        $this->assertSame(1, $this->item($loan, 'Due today')->attempts);

        $this->at('2026-09-15 08:15');
        $this->dispatchReminders();
        $this->at('2026-09-15 08:30');
        $this->dispatchReminders();
        $this->at('2026-09-15 08:45');
        $this->dispatchReminders();

        $item = $this->item($loan, 'Due today');
        $this->assertSame(['sent', 3], [$item->status, $item->attempts]);
        $this->assertCount(3, $this->driver->sentTo('sms'));
        $message = ReminderMessage::query()->where('queue_item_id', $item->id)->sole();
        $this->assertSame(['sent', 3], [$message->status, $message->attempts]);
    }

    public function test_three_temporary_failures_end_as_failed_with_one_staff_notice(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['channels' => ['sms']]);
        $this->plan();
        $down = DriverResult::temporaryFailure('provider_down', 'Provider unavailable');
        $this->driver->willReturn('sms', $down, $down, $down, $down);

        foreach (['08:00', '08:15', '08:30', '08:45'] as $time) {
            $this->at("2026-09-15 {$time}");
            $this->dispatchReminders();
        }

        $this->assertSame(['failed', 3], [$this->item($loan, 'Due today')->status, $this->item($loan, 'Due today')->attempts]);
        $this->assertCount(3, $this->driver->sentTo('sms'));
        $this->assertSame(1, $this->officer->notifications()->where('type', StaffNotification::REMINDER_FAILED)->count());
    }

    public function test_a_permanent_failure_fails_the_row_flags_the_contact_and_tells_staff_once(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();
        $this->driver->willReturn('sms', DriverResult::permanentFailure('invalid_number', 'Invalid mobile number'));

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $item = $this->item($loan, 'Due today');
        $this->assertSame(['failed', 'invalid_number', 'Invalid mobile number', 1], [$item->status, $item->reason_code, $item->reason, $item->attempts]);
        $preference = BorrowerNotificationPreference::query()->where('borrower_id', $loan->borrower_id)->sole();
        $this->assertSame('639171234501', $preference->mobile_invalid_value);
        $this->assertSame('Invalid mobile number', $preference->mobile_invalid_reason);

        $notices = $this->officer->notifications()->where('type', StaffNotification::REMINDER_CONTACT_INVALID)->get();
        $this->assertCount(1, $notices);
        $this->assertSame('Member contact needs updating', $notices->first()->data['title']);
        $this->assertStringNotContainsString('639171234501', $notices->first()->data['body']);

        // The next reminder to the same number is skipped and never handed over.
        $this->at('2026-09-19 08:00');
        $this->plan();
        $this->at('2026-09-19 09:00');
        $this->dispatchReminders();
        $this->assertSame(['skipped', 'contact_flagged'], array_slice($this->outcome($this->item($loan, '3 days overdue')), 0, 2));
        $this->assertCount(1, $this->driver->sentTo('sms'));
        $this->assertSame(1, $this->officer->notifications()->where('type', StaffNotification::REMINDER_CONTACT_INVALID)->count());

        // Once staff change the number, the flag no longer applies.
        Borrower::query()->whereKey($loan->borrower_id)->update(['contact_number' => '09175550000']);
        $this->assertFalse($preference->fresh()->mobileFlaggedFor('639175550000'));
    }

    public function test_a_row_left_by_a_killed_run_is_never_sent_twice(): void
    {
        $this->at('2026-09-15 07:00');
        $loan = $this->loanDue('2026-09-15');
        $this->rule('Due today')->update(['channels' => ['sms']]);
        $this->plan();

        // A run claimed the row and was killed after the attempt started.
        $item = $this->item($loan, 'Due today');
        $item->forceFill(['status' => 'processing', 'claim_token' => 'dead-run', 'claimed_at' => now()->setTime(8, 0), 'sending_started_at' => now()->setTime(8, 0), 'attempts' => 1])->save();
        // And another claimed but was killed before it reached the driver.
        $other = $this->loanDue('2026-09-15');
        $this->plan();
        $this->item($other, 'Due today')->forceFill(['status' => 'processing', 'claim_token' => 'dead-run', 'claimed_at' => now()->setTime(8, 0)])->save();

        $this->at('2026-09-15 08:20');
        $this->dispatchReminders();

        $this->assertSame(['failed', 'interrupted'], array_slice($this->outcome($this->item($loan, 'Due today')), 0, 2));
        $this->assertCount(0, $this->driver->sentTo('sms')->where('recipient', '639171234501'));
        $this->assertSame('sent', $this->item($other, 'Due today')->status);
        $this->assertCount(1, $this->driver->sentTo('sms'));
    }

    public function test_an_auto_pay_loan_is_skipped_before_and_on_the_due_day_but_not_after(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15', ['auto_pay' => true]);
        $this->plan();

        $this->at('2026-09-12 09:00');
        $this->dispatchReminders();
        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();
        $this->at('2026-09-19 09:00');
        $this->plan();
        $this->dispatchReminders();

        $this->assertSame(['skipped', 'auto_pay', 'Collected by Auto-Pay'], $this->outcome($this->item($loan, 'Upcoming due')));
        $this->assertSame(['skipped', 'auto_pay', 'Collected by Auto-Pay'], $this->outcome($this->item($loan, 'Due today')));
        $this->assertSame('sent', $this->item($loan, '3 days overdue')->status);
    }

    public function test_the_fifteen_day_rule_tells_the_loan_officer_to_follow_up_once(): void
    {
        $this->at('2026-09-30 08:00');
        $loan = $this->loanDue('2026-09-15', ['grace_period_days' => 3]);
        $this->plan();
        $this->at('2026-09-30 09:00');
        $this->dispatchReminders();

        $this->assertSame('sent', $this->item($loan, '15 days overdue')->status);
        $notices = $this->officer->notifications()->where('type', StaffNotification::REMINDER_FOLLOW_UP)->get();
        $this->assertCount(1, $notices);
        $this->assertSame('Account requires follow up', $notices->first()->data['title']);
        $this->assertStringContainsString('15 days past due', $notices->first()->data['body']);
        $this->assertStringContainsString('₱5,000.00', $notices->first()->data['body']);
    }

    public function test_a_dispatch_run_stops_at_its_message_limit(): void
    {
        config(['reminders.dispatch.max_messages' => 3]);
        $this->at('2026-09-15 07:00');
        foreach (range(1, 3) as $i) {
            $this->loanDue('2026-09-15');
        }
        $this->plan();

        $this->at('2026-09-15 08:00');
        $this->dispatchReminders();

        $this->assertCount(3, $this->driver->calls);
        $this->assertSame(3, ReminderQueueItem::query()->where('status', 'scheduled')->whereDate('fire_date', '2026-09-15')->count());
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function outcome(ReminderQueueItem $item): array
    {
        return [$item->status, $item->reason_code, $item->reason];
    }
}
