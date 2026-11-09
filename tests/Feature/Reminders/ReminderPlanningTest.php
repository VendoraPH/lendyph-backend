<?php

namespace Tests\Feature\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\Branch;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `reminders:plan`: which reminders are planned for which periods, and which
 * planned ones are cancelled when the loan or its schedule changes.
 */
class ReminderPlanningTest extends TestCase
{
    use BuildsReminderLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReminders();
    }

    public function test_the_defaults_are_five_rules_twelve_templates_and_one_switched_off_settings_row(): void
    {
        $this->assertSame(1, ReminderSetting::query()->count());
        $this->assertSame(5, ReminderRule::query()->count());
        $this->assertSame(12, ReminderTemplate::query()->count());
        $this->assertSame(['sms', 'email'], $this->rule('Due today')->channels);
        $this->assertSame(
            [['before_due', 3], ['on_due', 0], ['after_due', 3], ['after_due', 7], ['after_due', 15]],
            ReminderRule::query()->orderBy('id')->get()->map(fn (ReminderRule $rule) => [$rule->trigger, $rule->days])->all(),
        );

        // The deployment's own row starts switched off, in Manila, with
        // contact hours 08:00 to 18:00 and at most 3 attempts 15 minutes apart.
        // (setUp switched this test's copy on, so seed a fresh one.)
        DB::table('reminder_settings')->delete();
        (require database_path('migrations/2026_11_08_120100_seed_reminder_defaults.php'))->up();
        $seeded = DB::table('reminder_settings')->sole();
        $this->assertSame(0, (int) $seeded->enabled);
        $this->assertSame('Asia/Manila', $seeded->timezone);
        $this->assertSame(['08:00', '18:00'], [substr($seeded->contact_hours_start, 0, 5), substr($seeded->contact_hours_end, 0, 5)]);
        $this->assertSame([3, 15], [(int) $seeded->max_attempts, (int) $seeded->retry_delay_minutes]);
    }

    public function test_the_seed_migration_runs_safely_twice(): void
    {
        $migration = require database_path('migrations/2026_11_08_120100_seed_reminder_defaults.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, ReminderSetting::query()->count());
        $this->assertSame(5, ReminderRule::query()->count());
        $this->assertSame(12, ReminderTemplate::query()->count());
    }

    public function test_planning_twice_gives_one_row_per_period_rule_and_channel(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');

        $this->plan();
        $this->plan();

        $rows = ReminderQueueItem::query()->where('loan_id', $loan->id)->get();
        // Upcoming (Sep 12) and Due today (Sep 15), and 3 days overdue (Sep 19)
        // inside the 7-day window, each on SMS and email.
        $this->assertCount(6, $rows);
        $this->assertSame(6, $rows->where('status', 'scheduled')->count());
        $this->assertSame('2026-09-12', $this->item($loan, 'Upcoming due')->fire_date->toDateString());
        $this->assertSame('2026-09-12 09:00:00', $this->item($loan, 'Upcoming due')->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->item($loan, 'Upcoming due')->period_number);
        $this->assertSame('2026-09-15', $this->item($loan, 'Upcoming due')->due_date->toDateString());
    }

    public function test_an_after_due_rule_waits_for_the_grace_period_to_end(): void
    {
        // Due Sep 15 with 3 days' grace: the borrower is late from Sep 19, so
        // the 3-day rule fires Sep 19, not Sep 18.
        $this->at('2026-09-14 08:00');
        $loan = $this->loanDue('2026-09-15', ['grace_period_days' => 3]);

        $this->plan();

        $this->assertSame('2026-09-19', $this->item($loan, '3 days overdue')->fire_date->toDateString());
        $this->assertSame('2026-09-19', $this->item($loan, '3 days overdue', 'email')->fire_date->toDateString());
    }

    public function test_one_reminder_per_loan_per_channel_per_day_and_the_later_rule_wins(): void
    {
        // Seven days' grace: the 3-day and 7-day rules both land on Sep 23.
        $this->at('2026-09-21 08:00');
        $loan = $this->loanDue('2026-09-15', ['grace_period_days' => 7]);

        $this->plan();

        $threeDays = $this->item($loan, '3 days overdue');
        $sevenDays = $this->item($loan, '7 days overdue');
        $this->assertSame('2026-09-23', $sevenDays->fire_date->toDateString());
        $this->assertSame('scheduled', $sevenDays->status);
        $this->assertSame('cancelled', $threeDays->status);
        $this->assertSame('superseded', $threeDays->reason_code);
    }

    public function test_paid_before_the_send_time_cancels_the_planned_reminders(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();

        $this->payPeriod($loan, 4000, 1000);
        $this->plan();

        $rows = ReminderQueueItem::query()->where('loan_id', $loan->id)->get();
        $this->assertSame(['cancelled'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(['paid'], $rows->pluck('reason_code')->unique()->values()->all());
        $this->assertSame('Payment received before send time', $rows->first()->reason);
        $this->assertSame(6, DB::table('reminder_events')->where('loan_id', $loan->id)->where('kind', 'cancelled')->count());
    }

    public function test_a_completed_loan_cancels_its_reminders(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();

        $loan->update(['status' => 'completed']);
        $this->plan();

        $this->assertSame('cancelled', $this->item($loan, 'Due today')->status);
        $this->assertSame('loan_closed', $this->item($loan, 'Due today')->reason_code);
        $this->assertSame('Loan is fully paid', $this->item($loan, 'Due today')->reason);
    }

    public function test_a_restructure_cancels_the_old_schedule_and_plans_the_new_loan(): void
    {
        $this->at('2026-09-12 08:00');
        $old = $this->loanDue('2026-09-15');
        $this->plan();

        $old->update(['status' => 'restructured']);
        $new = $this->loanDue('2026-09-16', ['source_loan_id' => $old->id], [], ['first_name' => 'Juana']);
        $this->plan();

        $this->assertSame(['cancelled'], ReminderQueueItem::query()->where('loan_id', $old->id)->pluck('status')->unique()->values()->all());
        $this->assertSame('Loan was restructured', $this->item($old, 'Due today')->reason);
        $this->assertSame('scheduled', $this->item($new, 'Due today')->status);
        $this->assertSame('2026-09-16', $this->item($new, 'Due today')->fire_date->toDateString());
    }

    public function test_a_moved_due_date_cancels_the_old_reminders_and_plans_new_ones(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $this->plan();

        AmortizationSchedule::query()->where('loan_id', $loan->id)->update(['due_date' => '2026-09-20']);
        $this->at('2026-09-14 08:00');
        $this->plan();

        $old = ReminderQueueItem::query()->where('loan_id', $loan->id)->where('due_date', '2026-09-15')->get();
        $this->assertSame(['cancelled'], $old->pluck('status')->unique()->values()->all());
        $this->assertSame(['schedule_changed'], $old->pluck('reason_code')->unique()->values()->all());

        $new = ReminderQueueItem::query()->where('loan_id', $loan->id)->where('due_date', '2026-09-20')->where('status', 'scheduled')->get();
        $this->assertSame(
            ['2026-09-17', '2026-09-20'],
            $new->where('channel', 'sms')->whereIn('rule_id', [$this->rule('Upcoming due')->id, $this->rule('Due today')->id])
                ->map(fn (ReminderQueueItem $row) => $row->fire_date->toDateString())->sort()->values()->all(),
        );
    }

    public function test_nothing_is_planned_while_reminders_are_switched_off(): void
    {
        ReminderSetting::current()->update(['enabled' => false]);
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');

        $this->plan();

        $this->assertSame(0, ReminderQueueItem::query()->where('loan_id', $loan->id)->count());
    }

    public function test_a_rule_scoped_to_another_branch_or_product_plans_nothing_for_this_loan(): void
    {
        $this->at('2026-09-12 08:00');
        $loan = $this->loanDue('2026-09-15');
        $other = Branch::factory()->create();
        $this->rule('Upcoming due')->update(['branch_id' => $other->id]);

        $this->plan();

        $this->assertFalse(ReminderQueueItem::query()->where('loan_id', $loan->id)->where('rule_id', $this->rule('Upcoming due')->id)->exists());
        $this->assertTrue(ReminderQueueItem::query()->where('loan_id', $loan->id)->where('rule_id', $this->rule('Due today')->id)->exists());
    }
}
