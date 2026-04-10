<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Compute next due date and outstanding balance from loaded schedules to avoid N+1
        $nextDueDate = null;
        $outstandingBalance = 0.0;

        if ($this->relationLoaded('amortizationSchedules')) {
            $unpaidSchedules = $this->amortizationSchedules
                ->whereIn('status', ['pending', 'partial', 'overdue']);

            $nextDueDate = $unpaidSchedules
                ->sortBy('due_date')
                ->first()
                ?->due_date?->toDateString();

            $outstandingBalance = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) $s->principal_due - (float) $s->principal_paid);
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
            'approval_remarks' => $this->approval_remarks,
            'approved_at' => $this->approved_at,
            'released_at' => $this->released_at,
            'is_editable' => $this->is_editable,
            'is_releasable' => $this->is_releasable,
            'borrower' => new BorrowerResource($this->whenLoaded('borrower')),
            'loan_product' => new LoanProductResource($this->whenLoaded('loanProduct')),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'co_makers' => CoMakerResource::collection($this->whenLoaded('coMakers')),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedByUser')),
            'released_by_user' => new UserResource($this->whenLoaded('releasedByUser')),
            'created_by_user' => new UserResource($this->whenLoaded('createdByUser')),
            'amortization_schedules' => AmortizationScheduleResource::collection(
                $this->whenLoaded('amortizationSchedules')
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
