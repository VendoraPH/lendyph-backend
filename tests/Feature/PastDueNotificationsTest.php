<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use App\Notifications\StaffNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `notifications:past-due` tells the assigned officer once per past-due
 * episode (decision N, Arvin, 2026-11-02), and the overnight loan jobs run
 * just after midnight Manila in order: defaults, penalties, then this.
 */
class PastDueNotificationsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_past_due_loan_is_announced_to_its_officer_once_per_episode(): void
    {
        $officer = $this->officer();
        $loan = $this->lateLoan($officer);
        $current = $this->createReleasedLoan();
        $current->update(['account_officer_id' => $officer->id]);

        $this->artisan('notifications:past-due')->assertSuccessful();

        $this->assertSame(1, $this->pastDueCount($loan));
        $this->assertSame(0, $this->pastDueCount($current));
        $row = $officer->notifications()->firstOrFail();
        $this->assertSame('Loan past due', $row->data['title']);
        $this->assertSame("/loans/{$loan->id}", $row->data['url']);
        $firstLate = $loan->amortizationSchedules()->reorder()->orderBy('due_date')->first();
        $this->assertStringContainsString('due '.$firstLate->due_date->format('M j, Y').' that is past its grace period', $row->data['body']);

        // Still past due the next day: not announced again.
        $this->travel(1)->days();
        $this->artisan('notifications:past-due')->assertSuccessful();
        $this->assertSame(1, $this->pastDueCount($loan));

        // Paid up: the episode closes and nothing is sent.
        $unpaid = AmortizationSchedule::where('loan_id', $loan->id)->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)->pluck('id');
        AmortizationSchedule::whereKey($unpaid)->update(['status' => 'paid']);
        $this->artisan('notifications:past-due')->assertSuccessful();
        $this->assertFalse(DB::table('loan_past_due_notices')->where('loan_id', $loan->id)->exists());
        $this->assertSame(1, $this->pastDueCount($loan));

        // Behind again: a new episode, announced again.
        AmortizationSchedule::whereKey($unpaid)->update(['status' => 'pending']);
        $this->artisan('notifications:past-due')->assertSuccessful();
        $this->assertSame(2, $this->pastDueCount($loan));
    }

    public function test_a_loan_with_no_officer_or_an_officer_outside_its_branch_tells_nobody_and_is_not_retried(): void
    {
        $noOfficer = $this->lateLoan(null);
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $farOfficer = User::factory()->inBranches($far)->create();
        $farOfficer->assignRole('loan_officer');
        $outOfBranch = $this->lateLoan($farOfficer);

        $this->artisan('notifications:past-due')->assertSuccessful();

        $this->assertSame(0, $this->pastDueCount($noOfficer));
        $this->assertSame(0, $this->pastDueCount($outOfBranch));
        $this->assertSame(2, DB::table('loan_past_due_notices')->whereIn('loan_id', [$noOfficer->id, $outOfBranch->id])->count());
    }

    public function test_a_failed_send_keeps_no_marker_and_the_next_run_retries(): void
    {
        $officer = $this->officer();
        $loan = $this->lateLoan($officer);
        Log::spy();
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('database channel down'));

        $this->artisan('notifications:past-due')->assertFailed();

        $this->assertFalse(DB::table('loan_past_due_notices')->where('loan_id', $loan->id)->exists());
        $this->assertSame(0, $this->pastDueCount($loan));
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'retried on the next run') && $context['loan_id'] === $loan->id)
            ->once();

        Notification::swap(new ChannelManager($this->app));
        $this->artisan('notifications:past-due')->assertSuccessful();

        $this->assertTrue(DB::table('loan_past_due_notices')->where('loan_id', $loan->id)->exists());
        $this->assertSame(1, $this->pastDueCount($loan));
    }

    public function test_one_loans_failed_send_does_not_stop_the_next_loan(): void
    {
        $officer = $this->officer();
        $first = $this->lateLoan($officer);
        $second = $this->lateLoan($officer);
        $this->assertLessThan($second->id, $first->id);
        Log::spy();
        $real = new ChannelManager($this->app);
        $calls = 0;
        Notification::shouldReceive('send')->andReturnUsing(function ($recipients, $notification) use ($real, &$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('database channel down');
            }

            $real->send($recipients, $notification);
        });

        $this->artisan('notifications:past-due')->assertFailed();

        $this->assertFalse(DB::table('loan_past_due_notices')->where('loan_id', $first->id)->exists());
        $this->assertSame(0, $this->pastDueCount($first));
        $this->assertTrue(DB::table('loan_past_due_notices')->where('loan_id', $second->id)->exists());
        $this->assertSame(1, $this->pastDueCount($second));
    }

    public function test_the_overnight_jobs_run_just_after_midnight_manila_in_order(): void
    {
        Artisan::all();
        $events = collect(app(Schedule::class)->events());

        $at = function (string $command) use ($events): array {
            $event = $events->first(fn (Event $e) => str_contains((string) $e->command, $command));
            $this->assertNotNull($event, "{$command} is not scheduled");

            return [$event->expression, (string) $event->timezone];
        };

        $this->assertSame(['5 0 * * *', 'Asia/Manila'], $at('loans:check-defaulted'));
        $this->assertSame(['10 0 * * *', 'Asia/Manila'], $at('loans:apply-penalties'));
        $this->assertSame(['20 0 * * *', 'Asia/Manila'], $at('notifications:past-due'));
    }

    private function officer(): User
    {
        $officer = User::factory()->inBranches($this->branch)->create();
        $officer->assignRole('loan_officer');

        return $officer;
    }

    private function lateLoan(?User $officer): Loan
    {
        $loan = $this->createReleasedLoan(['start_date' => now()->subMonths(3)->toDateString()]);
        $loan->update(['account_officer_id' => $officer?->id]);

        return $loan;
    }

    private function pastDueCount(Loan $loan): int
    {
        return DatabaseNotification::query()
            ->where('type', StaffNotification::LOAN_PAST_DUE)
            ->get()
            ->filter(fn (DatabaseNotification $n) => ($n->data['data']['loan_id'] ?? null) === $loan->id)
            ->count();
    }
}
