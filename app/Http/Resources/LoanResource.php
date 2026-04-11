<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Loan',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'application_number', type: 'string'),
        new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
        new OA\Property(property: 'interest_rate', type: 'number'),
        new OA\Property(property: 'interest_method', type: 'string'),
        new OA\Property(property: 'term', type: 'integer'),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'principal_amount', type: 'number'),
        new OA\Property(property: 'purpose', type: 'string', nullable: true),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'maturity_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'outstanding_balance', type: 'number'),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'current_due', type: 'number'),
        new OA\Property(property: 'overdue_amount', type: 'number'),
        new OA\Property(property: 'penalty_amount', type: 'number'),
        new OA\Property(property: 'total_payable', type: 'number'),
        new OA\Property(property: 'borrower_name', type: 'string', nullable: true),
        new OA\Property(property: 'loan_product_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_officer_id', type: 'integer', nullable: true),
        new OA\Property(property: 'release_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'rejection_remarks', type: 'string', nullable: true),
    ],
)]
class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Compute payment-related fields from loaded schedules to avoid N+1
        $nextDueDate = null;
        $outstandingBalance = 0.0;
        $currentDue = 0.0;
        $overdueAmount = 0.0;
        $totalPenalty = 0.0;
        $totalPayable = 0.0;

        if ($this->relationLoaded('amortizationSchedules')) {
            $today = now()->startOfDay();
            $unpaidSchedules = $this->amortizationSchedules
                ->whereIn('status', ['pending', 'partial', 'overdue']);

            $nextSchedule = $unpaidSchedules->sortBy('due_date')->first();
            $nextDueDate = $nextSchedule?->due_date?->toDateString();

            // Current due = next unpaid schedule's remaining amount (explicit per-field for consistency)
            if ($nextSchedule) {
                $currentDue = round(
                    max(0, (float) $nextSchedule->principal_due - (float) $nextSchedule->principal_paid)
                    + max(0, (float) $nextSchedule->interest_due - (float) $nextSchedule->interest_paid)
                    + max(0, (float) ($nextSchedule->penalty_amount ?? 0) - (float) ($nextSchedule->penalty_paid ?? 0)),
                    2,
                );
            }

            // Outstanding principal balance
            $outstandingBalance = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) $s->principal_due - (float) $s->principal_paid);
            }), 2);

            // Overdue = sum of remaining amounts on schedules past due date
            $overdueSchedules = $unpaidSchedules->filter(fn ($s) => $s->due_date->lt($today));
            $overdueAmount = round($overdueSchedules->sum(function ($s) {
                return max(0, (float) $s->principal_due - (float) $s->principal_paid)
                    + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                    + max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
            }), 2);

            // Total penalty remaining
            $totalPenalty = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
            }), 2);

            // Total payable = all remaining amounts (principal + interest + penalty)
            $totalPayable = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) $s->principal_due - (float) $s->principal_paid)
                    + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                    + max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
            }), 2);
        }

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'loan_account_number' => $this->loan_account_number,
            'interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'frequency' => $this->frequency,
            'principal_amount' => (float) $this->principal_amount,
            'purpose' => $this->purpose,
            'start_date' => $this->start_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'deductions' => $this->deductions,
            'total_deductions' => $this->total_deductions,
            'net_proceeds' => $this->net_proceeds,
            'penalty_rate' => $this->penalty_rate,
            'grace_period_days' => $this->grace_period_days,
            'status' => $this->status,
            'outstanding_balance' => $outstandingBalance,
            'next_due_date' => $nextDueDate,
            'current_due' => $currentDue,
            'overdue_amount' => $overdueAmount,
            'penalty_amount' => $totalPenalty,
            'total_payable' => $totalPayable,
            'approval_remarks' => $this->approval_remarks,
            'approved_at' => $this->approved_at,
            'rejection_remarks' => $this->rejection_remarks,
            'rejected_by' => $this->rejected_by,
            'rejected_by_user' => new UserResource($this->whenLoaded('rejectedByUser')),
            'rejected_at' => $this->rejected_at,
            'released_at' => $this->released_at,
            'release_date' => $this->released_at?->toDateString(),
            'is_editable' => $this->is_editable,
            'is_releasable' => $this->is_releasable,
            'borrower' => new BorrowerResource($this->whenLoaded('borrower')),
            'borrower_name' => $this->whenLoaded('borrower', fn () => $this->borrower->full_name),
            'borrower_id' => $this->borrower_id,
            'loan_product' => new LoanProductResource($this->whenLoaded('loanProduct')),
            'loan_product_id' => $this->loan_product_id,
            'loan_product_name' => $this->whenLoaded('loanProduct', fn () => $this->loanProduct->name),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'co_makers' => CoMakerResource::collection($this->whenLoaded('coMakers')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedByUser')),
            'released_by_user' => new UserResource($this->whenLoaded('releasedByUser')),
            'created_by_user' => new UserResource($this->whenLoaded('createdByUser')),
            'account_officer_id' => $this->account_officer_id,
            'account_officer' => new UserResource($this->whenLoaded('accountOfficer')),
            'amortization_schedules' => AmortizationScheduleResource::collection(
                $this->whenLoaded('amortizationSchedules')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
