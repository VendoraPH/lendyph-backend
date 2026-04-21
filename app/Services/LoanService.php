<?php

namespace App\Services;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\CoMaker;
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

        $principal = (float) $validated['principal_amount'];
        $interestRate = (float) ($validated['interest_rate'] ?? $product->interest_rate);
        $term = (int) ($validated['term'] ?? $product->term);
        $frequency = $validated['frequency'] ?? $product->frequency;

        // Validate principal against product min/max
        if ($product->min_amount > 0 && $principal < (float) $product->min_amount) {
            throw ValidationException::withMessages([
                'principal_amount' => ["Minimum loan amount for this product is {$product->min_amount}."],
            ]);
        }
        if ($product->max_amount > 0 && $principal > (float) $product->max_amount) {
            throw ValidationException::withMessages([
                'principal_amount' => ["Maximum loan amount for this product is {$product->max_amount}."],
            ]);
        }

        // Validate interest rate against product range
        $minRate = (float) ($product->min_interest_rate ?? $product->interest_rate);
        $maxRate = (float) $product->interest_rate;
        if ($interestRate < $minRate || $interestRate > $maxRate) {
            throw ValidationException::withMessages([
                'interest_rate' => ["Interest rate must be between {$minRate}% and {$maxRate}% for this product."],
            ]);
        }

        // Validate term against product range
        $minTerm = (int) ($product->min_term ?? 1);
        $maxTerm = (int) ($product->max_term ?? $product->term);
        if ($term < $minTerm || $term > $maxTerm) {
            throw ValidationException::withMessages([
                'term' => ["Term must be between {$minTerm} and {$maxTerm} months for this product."],
            ]);
        }

        // Auto-compute deductions from product fees when not sent by frontend
        $deductions = $validated['deductions'] ?? [];
        if (empty($deductions)) {
            if ((float) $product->processing_fee > 0) {
                $deductions[] = ['name' => 'Processing Fee', 'amount' => (float) $product->processing_fee, 'type' => 'percentage'];
            }
            if ((float) $product->service_fee > 0) {
                $deductions[] = ['name' => 'Service Fee', 'amount' => (float) $product->service_fee, 'type' => 'percentage'];
            }
            if ((float) ($product->notarial_fee ?? 0) > 0) {
                $deductions[] = ['name' => 'Notarial Fee', 'amount' => (float) $product->notarial_fee, 'type' => 'fixed'];
            }
        }

        $deductionResult = $this->computeDeductions($principal, $deductions);

        $loan = Loan::create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $borrower->branch_id,
            'interest_rate' => $interestRate,
            'interest_method' => $product->interest_method,
            'term' => $term,
            'frequency' => $frequency,
            'principal_amount' => $principal,
            'purpose' => $validated['purpose'] ?? null,
            'start_date' => $validated['start_date'],
            'maturity_date' => $this->computeMaturityDate(
                $validated['start_date'],
                $term,
                $frequency,
            ),
            'deductions' => $deductionResult['items'],
            'total_deductions' => $deductionResult['total'],
            'net_proceeds' => $deductionResult['net_proceeds'],
            'scb_amount' => $validated['scb_amount'] ?? 0,
            'penalty_rate' => $product->penalty_rate,
            'grace_period_days' => $product->grace_period_days,
            'policy_exception' => $validated['policy_exception'] ?? false,
            'policy_exception_details' => $validated['policy_exception_details'] ?? null,
            'status' => 'draft',
            'created_by' => $user->id,
            'account_officer_id' => $validated['account_officer_id'] ?? null,
        ]);

        // Frontend sends borrower IDs as co-makers — resolve to CoMaker records
        if (! empty($validated['co_maker_ids'])) {
            $coMakerIds = [];
            foreach ($validated['co_maker_ids'] as $id) {
                // Try as co_maker ID first, then as borrower ID
                $coMaker = CoMaker::find($id);
                if ($coMaker) {
                    $coMakerIds[] = $coMaker->id;
                } else {
                    // Look up borrower and find/create a co-maker for them
                    $cmBorrower = Borrower::find($id);
                    if ($cmBorrower) {
                        $coMaker = CoMaker::firstOrCreate(
                            ['borrower_id' => $cmBorrower->id, 'first_name' => $cmBorrower->first_name, 'last_name' => $cmBorrower->last_name],
                            [
                                'address' => $cmBorrower->address,
                                'contact_number' => $cmBorrower->contact_number,
                                'relationship_to_borrower' => 'other',
                                'status' => 'active',
                            ],
                        );
                        $coMakerIds[] = $coMaker->id;
                    }
                }
            }
            if (! empty($coMakerIds)) {
                $loan->coMakers()->sync($coMakerIds);
            }
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
            'rejection_remarks' => $remarks,
            'rejected_by' => $approver->id,
            'rejected_at' => now(),
        ]);

        return $loan;
    }

    public function release(Loan $loan, User $releaser): Loan
    {
        $this->guardStatus($loan, 'approved', 'release');

        return DB::transaction(function () use ($loan, $releaser) {
            // Generate loan account number with row-level lock to prevent race conditions
            $lastLoan = Loan::whereNotNull('loan_account_number')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            $nextNum = $lastLoan ? (int) substr($lastLoan->loan_account_number, 3) + 1 : 1;
            $loanAccountNumber = 'LN-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);

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
        if (in_array($loan->status, ['released', 'ongoing', 'completed'])) {
            throw ValidationException::withMessages([
                'status' => ['Released, ongoing, or completed loans cannot be voided.'],
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
            'bi_weekly' => $date->addDays($term * 14),
            'semi_monthly' => $date->addDays($term * 15),
            // Upon-maturity bullet loans treat `term` as months-until-maturity
            // (single lump-sum payment on the maturity date).
            'monthly', 'upon_maturity' => $date->addMonths($term),
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
        $rate = (float) $loan->interest_rate / 100; // Monthly rate (PH convention)
        $term = $loan->term;

        // PH lending: interest = principal × monthly rate (flat on original principal each period)
        $interestPerPeriod = round($principal * $rate, 2);
        $totalInterest = round($interestPerPeriod * $term, 2);
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
        $term = $loan->term;
        // PH lending: interest_rate is monthly rate (e.g., 3 = 3% per month)
        $ratePerPeriod = (float) $loan->interest_rate / 100;

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
        $rate = (float) $loan->interest_rate / 100; // Monthly rate (PH convention)
        $term = $loan->term;

        // PH lending: interest = principal × monthly rate per period
        $interestPerPeriod = round($principal * $rate, 2);
        $totalInterest = round($interestPerPeriod * $term, 2);

        // If term > 1, generate interest-only periodic payments + principal at maturity
        if ($term > 1) {
            $schedule = [];
            $date = Carbon::parse($loan->start_date);

            for ($i = 1; $i <= $term; $i++) {
                $date = $this->addPeriod($date, $loan->frequency);
                $isLast = ($i === $term);

                $pDue = $isLast ? $principal : 0;
                $iDue = ($isLast) ? $totalInterest - ($interestPerPeriod * ($term - 1)) : $interestPerPeriod;
                $balance = $isLast ? 0 : $principal;

                $schedule[] = [
                    'period_number' => $i,
                    'due_date' => $date->toDateString(),
                    'principal_due' => round($pDue, 2),
                    'interest_due' => round($iDue, 2),
                    'total_due' => round($pDue + $iDue, 2),
                    'remaining_balance' => round($balance, 2),
                    'status' => 'pending',
                ];
            }

            return $schedule;
        }

        // Single-period: lump sum at maturity
        $maturityDate = $this->computeMaturityDate(
            $loan->start_date->toDateString(),
            $term,
            $loan->frequency,
        );

        return [
            [
                'period_number' => 1,
                'due_date' => $maturityDate->toDateString(),
                'principal_due' => $principal,
                'interest_due' => $totalInterest,
                'total_due' => round($principal + $totalInterest, 2),
                'remaining_balance' => 0,
                'status' => 'pending',
            ],
        ];
    }

    private function addPeriod(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'daily' => $date->copy()->addDay(),
            'weekly' => $date->copy()->addWeek(),
            'bi_weekly' => $date->copy()->addDays(14),
            'semi_monthly' => $date->copy()->addDays(15),
            // Upon-maturity loans schedule a single bullet payment, so the
            // schedule generator never iterates past period 1 — but treat the
            // "next period" as the maturity date itself (term months out) for
            // any caller that does invoke addPeriod defensively.
            'monthly', 'upon_maturity' => $date->copy()->addMonth(),
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
