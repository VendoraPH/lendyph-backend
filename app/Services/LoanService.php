<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function createLoan(array $validated, User $user): Loan
    {
        $product = LoanProduct::findOrFail($validated['loan_product_id']);
        $borrower = Borrower::findOrFail($validated['borrower_id']);

        $interestRate = $validated['interest_rate'] ?? $product->interest_rate;
        $deductionResult = $this->computeDeductions(
            (float) $validated['principal_amount'],
            $validated['deductions'] ?? [],
        );

        $loan = Loan::create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $borrower->branch_id,
            'interest_rate' => $interestRate,
            'interest_method' => $product->interest_method,
            'term' => $product->term,
            'frequency' => $product->frequency,
            'principal_amount' => $validated['principal_amount'],
            'start_date' => $validated['start_date'],
            'maturity_date' => $this->computeMaturityDate(
                $validated['start_date'],
                $product->term,
                $product->frequency,
            ),
            'deductions' => $deductionResult['items'],
            'total_deductions' => $deductionResult['total'],
            'net_proceeds' => $deductionResult['net_proceeds'],
            'penalty_rate' => $product->penalty_rate,
            'grace_period_days' => $product->grace_period_days,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        if (! empty($validated['co_maker_ids'])) {
            $loan->coMakers()->sync($validated['co_maker_ids']);
        }

        return $loan;
    }

    public function updateLoan(Loan $loan, array $validated): Loan
    {
        if (! $loan->is_editable) {
            throw ValidationException::withMessages([
                'status' => ['Loan can only be edited in draft or for_review status.'],
            ]);
        }

        $needsRecompute = isset($validated['principal_amount']) || isset($validated['deductions']);

        if ($needsRecompute) {
            $principal = (float) ($validated['principal_amount'] ?? $loan->principal_amount);
            $deductions = $validated['deductions'] ?? $loan->deductions ?? [];
            $result = $this->computeDeductions($principal, $deductions);
            $validated['deductions'] = $result['items'];
            $validated['total_deductions'] = $result['total'];
            $validated['net_proceeds'] = $result['net_proceeds'];
        }

        if (isset($validated['start_date'])) {
            $validated['maturity_date'] = $this->computeMaturityDate(
                $validated['start_date'],
                $loan->term,
                $loan->frequency,
            );
        }

        $loan->update($validated);

        if (isset($validated['co_maker_ids'])) {
            $loan->coMakers()->sync($validated['co_maker_ids']);
        }

        return $loan;
    }

    public function submitForReview(Loan $loan): Loan
    {
        $this->guardStatus($loan, 'draft', 'submit for review');
        $loan->update(['status' => 'for_review']);

        return $loan;
    }

    public function approve(Loan $loan, User $approver, ?string $remarks): Loan
    {
        $this->guardStatus($loan, 'for_review', 'approve');
        $loan->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        return $loan;
    }

    public function reject(Loan $loan, User $approver, ?string $remarks): Loan
    {
        $this->guardStatus($loan, 'for_review', 'reject');
        $loan->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        return $loan;
    }

    public function release(Loan $loan, User $releaser): Loan
    {
        $this->guardStatus($loan, 'approved', 'release');

        return DB::transaction(function () use ($loan, $releaser) {
            // Generate loan account number
            $lastLN = Loan::whereNotNull('loan_account_number')
                ->orderByDesc('id')
                ->value('loan_account_number');
            $nextNum = $lastLN ? (int) substr($lastLN, 3) + 1 : 1;
            $loanAccountNumber = 'LN-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $loan->update([
                'status' => 'released',
                'loan_account_number' => $loanAccountNumber,
                'released_by' => $releaser->id,
                'released_at' => now(),
            ]);

            // Persist amortization schedule
            $schedule = $this->buildAmortizationPreview($loan);
            foreach ($schedule as $row) {
                AmortizationSchedule::create([
                    'loan_id' => $loan->id,
                    ...$row,
                ]);
            }

            return $loan;
        });
    }

    public function voidLoan(Loan $loan): Loan
    {
        if (in_array($loan->status, ['released', 'closed'])) {
            throw ValidationException::withMessages([
                'status' => ['Released or closed loans cannot be voided.'],
            ]);
        }

        $loan->update(['status' => 'void']);

        return $loan;
    }

    public function computeDeductions(float $principalAmount, array $deductions): array
    {
        $total = 0;
        $items = [];

        foreach ($deductions as $deduction) {
            $amount = $deduction['type'] === 'percentage'
                ? round($principalAmount * $deduction['amount'] / 100, 2)
                : round((float) $deduction['amount'], 2);

            $items[] = [
                'name' => $deduction['name'],
                'amount' => $amount,
                'type' => $deduction['type'],
                'original_value' => $deduction['amount'],
            ];

            $total += $amount;
        }

        $netProceeds = round($principalAmount - $total, 2);

        if ($netProceeds < 0) {
            throw ValidationException::withMessages([
                'deductions' => ['Total deductions exceed the principal amount.'],
            ]);
        }

        return [
            'items' => $items,
            'total' => $total,
            'net_proceeds' => $netProceeds,
        ];
    }

    public function computeMaturityDate(string $startDate, int $term, string $frequency): Carbon
    {
        $date = Carbon::parse($startDate);

        return match ($frequency) {
            'daily' => $date->addDays($term),
            'weekly' => $date->addWeeks($term),
            'semi_monthly' => $date->addDays($term * 15),
            'monthly' => $date->addMonths($term),
        };
    }

    public function buildAmortizationPreview(Loan $loan): array
    {
        return match ($loan->interest_method) {
            'straight' => $this->buildStraight($loan),
            'diminishing' => $this->buildDiminishing($loan),
            'upon_maturity' => $this->buildUponMaturity($loan),
        };
    }

    private function buildStraight(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $rate = (float) $loan->interest_rate / 100;
        $term = $loan->term;
        $ppY = $this->periodsPerYear($loan->frequency);

        $totalInterest = round($principal * $rate / $ppY * $term, 2);
        $interestPerPeriod = round($totalInterest / $term, 2);
        $principalPerPeriod = round($principal / $term, 2);

        $schedule = [];
        $balance = $principal;
        $date = Carbon::parse($loan->start_date);

        for ($i = 1; $i <= $term; $i++) {
            $date = $this->addPeriod($date, $loan->frequency);

            $pDue = ($i === $term) ? $balance : $principalPerPeriod;
            $iDue = ($i === $term) ? $totalInterest - ($interestPerPeriod * ($term - 1)) : $interestPerPeriod;
            $balance = round($balance - $pDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $date->toDateString(),
                'principal_due' => round($pDue, 2),
                'interest_due' => round($iDue, 2),
                'total_due' => round($pDue + $iDue, 2),
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildDiminishing(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $annualRate = (float) $loan->interest_rate / 100;
        $term = $loan->term;
        $ppY = $this->periodsPerYear($loan->frequency);
        $ratePerPeriod = $annualRate / $ppY;

        // PMT formula
        if ($ratePerPeriod > 0) {
            $payment = round($principal * ($ratePerPeriod * pow(1 + $ratePerPeriod, $term))
                / (pow(1 + $ratePerPeriod, $term) - 1), 2);
        } else {
            $payment = round($principal / $term, 2);
        }

        $schedule = [];
        $balance = $principal;
        $date = Carbon::parse($loan->start_date);

        for ($i = 1; $i <= $term; $i++) {
            $date = $this->addPeriod($date, $loan->frequency);

            $interestDue = round($balance * $ratePerPeriod, 2);
            $principalDue = ($i === $term) ? $balance : round($payment - $interestDue, 2);
            $totalDue = round($principalDue + $interestDue, 2);
            $balance = round($balance - $principalDue, 2);

            $schedule[] = [
                'period_number' => $i,
                'due_date' => $date->toDateString(),
                'principal_due' => $principalDue,
                'interest_due' => $interestDue,
                'total_due' => $totalDue,
                'remaining_balance' => max($balance, 0),
                'status' => 'pending',
            ];
        }

        return $schedule;
    }

    private function buildUponMaturity(Loan $loan): array
    {
        $principal = (float) $loan->principal_amount;
        $rate = (float) $loan->interest_rate / 100;
        $term = $loan->term;
        $ppY = $this->periodsPerYear($loan->frequency);

        $totalInterest = round($principal * $rate / $ppY * $term, 2);
        $date = $this->computeMaturityDate(
            $loan->start_date->toDateString(),
            $term,
            $loan->frequency,
        );

        return [
            [
                'period_number' => 1,
                'due_date' => $date->toDateString(),
                'principal_due' => $principal,
                'interest_due' => $totalInterest,
                'total_due' => round($principal + $totalInterest, 2),
                'remaining_balance' => 0,
                'status' => 'pending',
            ],
        ];
    }

    private function periodsPerYear(string $frequency): int
    {
        return match ($frequency) {
            'daily' => 365,
            'weekly' => 52,
            'semi_monthly' => 24,
            'monthly' => 12,
        };
    }

    private function addPeriod(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'semi_monthly' => $date->copy()->addDays(15),
            'monthly' => $date->copy()->addMonth(),
        };
    }

    private function guardStatus(Loan $loan, string $expected, string $action): void
    {
        if ($loan->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["Loan must be in '{$expected}' status to {$action}."],
            ]);
        }
    }
}
