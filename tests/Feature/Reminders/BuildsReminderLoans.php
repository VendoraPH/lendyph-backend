<?php

namespace Tests\Feature\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Services\Reminders\Drivers\FakeDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Traits\SetupLendyPH;

/**
 * A released loan with one hand-built period, so every figure a reminder
 * quotes is known: ₱4,000.00 principal plus ₱1,000.00 interest, no penalty
 * rate, no premium, no build-up.
 */
trait BuildsReminderLoans
{
    use SetupLendyPH;

    protected FakeDriver $driver;

    protected User $officer;

    private int $loanSequence = 0;

    protected function setUpReminders(): void
    {
        $this->seedAndLogin();
        Http::preventStrayRequests();

        $this->driver = new FakeDriver;
        $this->app->instance(FakeDriver::class, $this->driver);
        config([
            'reminders.drivers.sms' => 'fake',
            'reminders.drivers.email' => 'fake',
            'reminders.allow_all_recipients' => true,
        ]);

        ReminderSetting::current()->update(['enabled' => true]);

        $this->officer = User::factory()->inBranches($this->branch)->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $this->officer->assignRole('loan_officer');
    }

    /**
     * Manila wall-clock time, whatever the app timezone.
     */
    protected function at(string $manila): void
    {
        $this->travelTo(Carbon::parse($manila, 'Asia/Manila'));
    }

    /**
     * @param  array<string, mixed>  $loan
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $borrower
     */
    protected function loanDue(string $dueDate, array $loan = [], array $period = [], array $borrower = []): Loan
    {
        $this->loanSequence++;
        $member = Borrower::factory()->create(array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'middle_name' => null,
            'contact_number' => '0917 123 45'.str_pad((string) $this->loanSequence, 2, '0', STR_PAD_LEFT),
            'email' => "juan{$this->loanSequence}@example.com",
            'spouse_contact_number' => '09998887777',
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ], $borrower));

        $product = LoanProduct::factory()->create(['penalty_rate' => 0, 'grace_period_days' => 3]);

        $created = Loan::factory()->create(array_merge([
            'borrower_id' => $member->id,
            'loan_product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'loan_account_number' => sprintf('LN-%06d', 900000 + $this->loanSequence),
            'principal_amount' => 4000,
            'penalty_rate' => 0,
            'grace_period_days' => 3,
            'status' => 'released',
            'released_at' => Carbon::parse($dueDate)->subMonth(),
            'start_date' => Carbon::parse($dueDate)->subMonth(),
            'maturity_date' => Carbon::parse($dueDate),
            'account_officer_id' => $this->officer->id,
            'created_by' => $this->admin->id,
            'auto_pay' => false,
        ], $loan));

        AmortizationSchedule::factory()->create(array_merge([
            'loan_id' => $created->id,
            'period_number' => 1,
            'due_date' => $dueDate,
            'principal_due' => 4000,
            'interest_due' => 1000,
            'total_due' => 5000,
            'remaining_balance' => 0,
            'status' => 'pending',
        ], $period));

        return $created->fresh(['borrower', 'amortizationSchedules']);
    }

    protected function rule(string $name): ReminderRule
    {
        return ReminderRule::query()->where('name', $name)->firstOrFail();
    }

    protected function item(Loan $loan, string $ruleName, string $channel = 'sms'): ReminderQueueItem
    {
        return ReminderQueueItem::query()
            ->where('loan_id', $loan->id)
            ->where('rule_id', $this->rule($ruleName)->id)
            ->where('channel', $channel)
            ->firstOrFail();
    }

    protected function payPeriod(Loan $loan, float $principal, float $interest): void
    {
        $period = $loan->amortizationSchedules()->firstOrFail();
        $paidInFull = $principal >= (float) $period->principal_due && $interest >= (float) $period->interest_due;
        $period->update([
            'principal_paid' => $principal,
            'interest_paid' => $interest,
            'status' => $paidInFull ? 'paid' : 'partial',
        ]);
    }

    protected function plan(): void
    {
        $this->artisan('reminders:plan')->assertSuccessful();
    }

    protected function dispatchReminders(): void
    {
        $this->artisan('reminders:dispatch')->assertSuccessful();
    }
}
