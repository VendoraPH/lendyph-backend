<?php

namespace Tests\Feature\CreditScoring\Engine;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CreditScoringSetting;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use App\Models\User;
use App\Services\CreditScoring\FactorTable;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\CreditScoring\Scorer;
use Carbon\Carbon;

/**
 * Hand-built members, loans, periods and payments for the engine tests, so
 * each fixture states exactly the history it scores. Periods are ₱1,100.00
 * (₱1,000.00 principal, ₱100.00 interest) unless a test says otherwise.
 */
trait CreditFixtures
{
    protected function pinToday(string $date = '2026-11-08 09:00:00'): void
    {
        $this->travelTo(Carbon::parse($date));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function member(array $attributes = []): Borrower
    {
        return Borrower::factory()->create(array_merge([
            'branch_id' => Branch::query()->value('id'),
            'status' => 'active',
            'middle_name' => null,
            'monthly_income' => null,
            'date_hired' => null,
            'contact_number' => '09171234567',
            'approved_at' => Carbon::now()->subMonths(6),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function releasedLoan(Borrower $member, array $attributes = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'borrower_id' => $member->id,
            'branch_id' => $member->branch_id,
            'status' => 'ongoing',
            'principal_amount' => 6000,
            'grace_period_days' => 3,
            'released_at' => Carbon::now()->subMonths(6),
            'created_by' => User::query()->value('id'),
        ], $attributes));
    }

    /**
     * A period with nothing paid.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function period(Loan $loan, int $number, string $dueDate, array $attributes = []): AmortizationSchedule
    {
        return AmortizationSchedule::factory()->create(array_merge([
            'loan_id' => $loan->id,
            'period_number' => $number,
            'due_date' => $dueDate,
            'principal_due' => 1000,
            'interest_due' => 100,
            'total_due' => 1100,
            'remaining_balance' => 0,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'penalty_amount' => 0,
            'penalty_paid' => 0,
            'status' => Carbon::parse($dueDate)->lt(Carbon::today()) ? 'overdue' : 'pending',
        ], $attributes));
    }

    /**
     * A period paid in full by one posted payment on `$paidOn`, with its
     * allocation row.
     */
    protected function paidPeriod(Loan $loan, int $number, string $dueDate, string $paidOn): AmortizationSchedule
    {
        $period = $this->period($loan, $number, $dueDate, ['principal_paid' => 1000, 'interest_paid' => 100, 'status' => 'paid']);

        $repayment = Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => $paidOn,
            'amount_paid' => 1100,
            'principal_applied' => 1000,
            'interest_applied' => 100,
            'received_by' => User::query()->value('id'),
        ]);

        RepaymentAllocation::query()->create([
            'repayment_id' => $repayment->id,
            'amortization_schedule_id' => $period->id,
            'period_number' => $number,
            'penalty' => 0,
            'interest' => 100,
            'principal' => 1000,
        ]);

        return $period;
    }

    /**
     * `$count` monthly periods, due on the 1st from `$firstDue`, each paid
     * `$daysLate` days after its due date.
     */
    protected function paidMonthly(Loan $loan, string $firstDue, int $count, int $daysLate = 0, int $firstNumber = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $due = Carbon::parse($firstDue)->addMonthsNoOverflow($i);
            $this->paidPeriod($loan, $firstNumber + $i, $due->toDateString(), $due->copy()->addDays($daysLate)->toDateString());
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function scoreOf(Borrower $member, ?Loan $applicationLoan = null): array
    {
        $settings = CreditScoringSetting::current();

        return app(Scorer::class)->score(
            MemberCreditData::load($member->id, $applicationLoan),
            $settings,
            FactorTable::fromRevision($settings->currentRevision),
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function factorOf(array $result, string $key): array
    {
        return collect($result['factors'])->firstWhere('factor_key', $key);
    }
}
