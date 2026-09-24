<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Repayment',
    description: 'One repayment (official receipt), including how `amount_paid` was allocated. '
        .'GET /api/loans/{loan}/repayments and GET /api/repayments return exactly this object for every row — '
        .'the same payload GET /api/repayments/{repayment} returns — so a list row never needs a detail fetch. '
        .'Each allocation total is published under three names (`principal_applied` / `principal_amount` / '
        .'`principal`, and likewise for interest and penalty); they always carry the same value. '
        .'`scb_paid` is whatever share capital this payment credited, posted atomically with the payment '
        .'itself, NET of any void reversal — it is 0 whenever the loan carries no `scb_amount`, the payment '
        .'left no overpayment, or the payment that credited it has since been voided.',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'receipt_number', type: 'string'),
        new OA\Property(property: 'loan_id', type: 'integer'),
        new OA\Property(property: 'borrower_id', type: 'integer', nullable: true, description: "The loan's borrower"),
        new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
        new OA\Property(property: 'borrower_name', type: 'string', nullable: true),
        new OA\Property(property: 'loan_product_name', type: 'string', nullable: true),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date', description: 'Alias for payment_date'),
        new OA\Property(property: 'method', type: 'string', enum: ['cash', 'gcash', 'maya', 'bank_transfer', 'online']),
        new OA\Property(property: 'reference_number', type: 'string', nullable: true),
        new OA\Property(property: 'amount_paid', type: 'number'),
        new OA\Property(property: 'amount', type: 'number', description: 'Alias for amount_paid'),
        new OA\Property(property: 'principal_applied', type: 'number', description: 'Principal portion of this payment'),
        new OA\Property(property: 'principal_amount', type: 'number', description: 'Alias for principal_applied'),
        new OA\Property(property: 'principal', type: 'number', description: 'Alias for principal_applied'),
        new OA\Property(property: 'interest_applied', type: 'number', description: 'Interest portion of this payment'),
        new OA\Property(property: 'interest_amount', type: 'number', description: 'Alias for interest_applied'),
        new OA\Property(property: 'interest', type: 'number', description: 'Alias for interest_applied'),
        new OA\Property(property: 'penalty_applied', type: 'number', description: 'Penalty portion of this payment'),
        new OA\Property(property: 'penalty_amount', type: 'number', description: 'Alias for penalty_applied'),
        new OA\Property(property: 'penalty', type: 'number', description: 'Alias for penalty_applied'),
        new OA\Property(property: 'overdue_interest_applied', type: 'number', description: 'Interest paid on overdue schedules'),
        new OA\Property(property: 'current_interest_applied', type: 'number', description: 'Interest paid on the current period'),
        new OA\Property(property: 'current_principal_applied', type: 'number', description: 'Principal paid on the current period'),
        new OA\Property(property: 'next_interest_applied', type: 'number', description: 'Excess interest flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'next_principal_applied', type: 'number', description: 'Excess principal flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'overpayment', type: 'number', description: 'Unallocated remainder (frontend routes to SCB when scb_amount>0)'),
        new OA\Property(property: 'scb_paid', type: 'number', description: 'Net share capital credited FROM this payment: 0 when the loan carries no scb_amount or there was no overpayment, and back to 0 once a credited payment is voided (the reversal debit nets against its own credit).'),
        new OA\Property(property: 'balance_before', type: 'number', description: 'Outstanding principal before this payment'),
        new OA\Property(property: 'balance_after', type: 'number', description: 'Outstanding principal after this payment'),
        new OA\Property(property: 'previous_balance', type: 'number', description: 'Alias for balance_before'),
        new OA\Property(property: 'new_balance', type: 'number', description: 'Alias for balance_after'),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true, description: "Due date of the loan's earliest unpaid schedule as of NOW, not as of this payment"),
        new OA\Property(property: 'payment_type', type: 'string', enum: ['exact', 'partial', 'advance']),
        new OA\Property(property: 'status', type: 'string', enum: ['completed', 'voided'], description: '`completed` is a posted payment'),
        new OA\Property(property: 'void_reason', type: 'string', nullable: true),
        new OA\Property(property: 'voided_by', type: 'integer', nullable: true),
        new OA\Property(
            property: 'voided_by_user',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true, example: '2026-09-23 14:05:00'),
        new OA\Property(property: 'received_by', type: 'integer', nullable: true),
        new OA\Property(property: 'collected_by', type: 'string', nullable: true, description: 'Full name of the receiving user'),
        new OA\Property(
            property: 'received_by_user',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-09-23 14:05:00'),
    ],
)]
class RepaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Map backend status 'posted' to frontend 'completed' for badge color matching
        $frontendStatus = $this->status === 'posted' ? 'completed' : $this->status;

        // Derive next due date from the loan's next unpaid schedule if the loan and its
        // schedules are eager-loaded. Safe null fallback otherwise.
        $nextDueDate = null;
        if ($this->loan && $this->loan->relationLoaded('amortizationSchedules')) {
            $nextSchedule = $this->loan->amortizationSchedules
                ->whereIn('status', ['pending', 'partial', 'overdue'])
                ->sortBy('due_date')
                ->first();
            $nextDueDate = $nextSchedule?->due_date?->toDateString();
        }

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'loan_id' => $this->loan_id,
            'borrower_id' => $this->loan?->borrower_id,
            'loan_account_number' => $this->loan?->loan_account_number,
            'borrower_name' => $this->loan?->borrower?->full_name,
            'loan_product_name' => $this->loan?->loanProduct?->name,
            'payment_date' => $this->payment_date?->toDateString(),
            'paid_at' => $this->payment_date?->toDateString(),
            'method' => $this->method,
            'reference_number' => $this->reference_number,
            'amount_paid' => (float) $this->amount_paid,
            'amount' => (float) $this->amount_paid,
            'principal_applied' => (float) $this->principal_applied,
            'principal_amount' => (float) $this->principal_applied,
            'principal' => (float) $this->principal_applied,
            'interest_applied' => (float) $this->interest_applied,
            'interest_amount' => (float) $this->interest_applied,
            'interest' => (float) $this->interest_applied,
            'penalty_applied' => (float) $this->penalty_applied,
            'penalty_amount' => (float) $this->penalty_applied,
            'penalty' => (float) $this->penalty_applied,
            'overdue_interest_applied' => (float) $this->overdue_interest_applied,
            'current_interest_applied' => (float) $this->current_interest_applied,
            'current_principal_applied' => (float) $this->current_principal_applied,
            'next_interest_applied' => (float) $this->next_interest_applied,
            'next_principal_applied' => (float) $this->next_principal_applied,
            'overpayment' => (float) $this->overpayment,
            // NET, not gross: a void's reversal debit shares this repayment's
            // repayment_id, so a plain sum('credit') would keep reporting the
            // original amount after voidRepayment() has already reversed it.
            'scb_paid' => (float) ($this->shareCapitalLedgerEntries->sum('credit') - $this->shareCapitalLedgerEntries->sum('debit')),
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            // Frontend-canonical aliases (consumed by payments receipt page)
            'previous_balance' => (float) $this->balance_before,
            'new_balance' => (float) $this->balance_after,
            'next_due_date' => $nextDueDate,
            'payment_type' => $this->payment_type,
            'status' => $frontendStatus,
            'void_reason' => $this->void_reason,
            'voided_by' => $this->voided_by,
            'voided_by_user' => $this->whenLoaded('voidedByUser', fn () => [
                'id' => $this->voidedByUser->id,
                'name' => $this->voidedByUser->full_name,
            ]),
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'received_by' => $this->received_by,
            // collected_by must be a string for frontend display (full_name of receiving user)
            'collected_by' => $this->whenLoaded('receivedByUser', fn () => $this->receivedByUser->full_name),
            'received_by_user' => $this->whenLoaded('receivedByUser', fn () => [
                'id' => $this->receivedByUser->id,
                'name' => $this->receivedByUser->full_name,
            ]),
            'remarks' => $this->remarks,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
