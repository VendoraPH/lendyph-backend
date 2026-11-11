<?php

namespace App\Http\Resources;

use App\Models\Borrower;
use App\Services\LoanService;
use Carbon\CarbonImmutable;
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
        new OA\Property(property: 'borrower_code', type: 'string', nullable: true, description: "The member's code, the Official Receipt's Member No."),
        new OA\Property(property: 'borrower_first_last', type: 'string', nullable: true, description: 'First and last name, as the list search matches it'),
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
        new OA\Property(property: 'insurance_applied', type: 'number', description: 'Insurance premium owed on the loan that this payment collected, before anything else'),
        new OA\Property(property: 'penalty_amount', type: 'number', description: 'Alias for penalty_applied'),
        new OA\Property(property: 'penalty', type: 'number', description: 'Alias for penalty_applied'),
        new OA\Property(property: 'overdue_interest_applied', type: 'number', description: 'Interest paid on overdue schedules'),
        new OA\Property(property: 'current_interest_applied', type: 'number', description: 'Interest paid on the current period'),
        new OA\Property(property: 'current_principal_applied', type: 'number', description: 'Principal paid on the current period'),
        new OA\Property(property: 'next_interest_applied', type: 'number', description: 'Excess interest flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'next_principal_applied', type: 'number', description: 'Excess principal flowed to next schedule (0 when scb_amount>0)'),
        new OA\Property(property: 'overpayment', type: 'number', description: 'Unallocated remainder (frontend routes to SCB when scb_amount>0)'),
        new OA\Property(property: 'scb_paid', type: 'number', description: 'Net share capital credited FROM this payment: 0 when the loan carries no scb_amount or there was no overpayment, and back to 0 once a credited payment is voided (the reversal debit nets against its own credit).'),
        new OA\Property(property: 'build_up_credited', type: 'string', nullable: true, example: '750.00', description: 'Share capital build-up this payment credited, from its share capital ledger credit (gross: a voided payment keeps what it credited; `scb_paid` is the net). 2-decimal string. Null when the stored data can\'t tell: overpayment on a build-up loan with no ledger credit naming the payment, unless the member is a pending or rejected applicant and the payment was recorded from 2026-11-08 (Manila), once member status edits were locked (then 0.00).'),
        new OA\Property(property: 'overpayment_kept', type: 'string', nullable: true, example: '0.00', description: 'Advance or overpayment left as such: the stored `overpayment` less `build_up_credited`. 2-decimal string. Null whenever `build_up_credited` is. principal + interest + penalty + insurance + build_up_credited + overpayment_kept = amount_paid.'),
        new OA\Property(property: 'balance_before', type: 'number', description: 'Outstanding principal before this payment: principal only, never the insurance premium owed. The payment preview\'s `balance_before` is the outstanding balance, premium owed included. Label it "Principal balance".'),
        new OA\Property(property: 'balance_after', type: 'number', description: 'Outstanding principal after this payment: principal only, never the insurance premium owed. The payment preview\'s `balance_after` is the outstanding balance, premium owed included. Label it "Principal balance".'),
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
    /**
     * When a member's status stopped being editable, in Manila time. Until
     * #226 (merged 2026-11-07 22:45 Manila) a member edit could set `status`,
     * so a member who is pending or rejected now may have been active when an
     * older payment was made. The cutoff is the first whole day after that
     * merge, so no payment recorded before the lock reached a deployment
     * counts as proof.
     */
    public const MEMBER_STATUS_LOCKED_FROM = '2026-11-08 00:00:00';

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
            'borrower_code' => $this->loan?->borrower?->borrower_code,
            // "first last", exactly what the list's `search` matches (CONCAT of the two),
            // so the Payment History table can filter its rows the way the totals do.
            'borrower_first_last' => $this->firstLastName(),
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
            'insurance_applied' => (float) $this->insurance_applied,
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
            ...$this->buildUpAndOverpaymentKept(),
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

    /**
     * The stored overpayment, split into what this payment credited to share
     * capital (its ledger credit rows, gross, as the Ledger reads them) and
     * what stayed as advance or overpayment. Read from the stored rows only;
     * nothing is recalculated.
     *
     * An overpayment with no credit naming the payment stayed as overpayment
     * when the stored data proves the payment couldn't have credited one: the
     * loan carries no build-up, or the member is a pending or rejected
     * applicant (RepaymentService::creditsBuildUp()'s rule) and the payment was
     * recorded once member status edits were locked
     * (self::MEMBER_STATUS_LOCKED_FROM). Otherwise the rows can't tell (a
     * payment from before the automatic credit, by a member whose status has
     * changed since, or one made before the member was approved), and both
     * are null.
     *
     * @return array{build_up_credited: string|null, overpayment_kept: string|null}
     */
    private function buildUpAndOverpaymentKept(): array
    {
        $unknown = ['build_up_credited' => null, 'overpayment_kept' => null];
        $overpayment = LoanService::toCentavos((float) $this->overpayment);
        $credits = $this->shareCapitalLedgerEntries->filter(fn ($row): bool => (float) $row->credit > 0);
        $credited = (int) $credits->sum(fn ($row): int => LoanService::toCentavos((float) $row->credit));

        // scb_amount is fixed once the loan is released.
        if ($credits->isEmpty() && $overpayment > 0 && ($this->loan === null || ((float) $this->loan->scb_amount > 0 && ! $this->provesNoCredit()))) {
            return $unknown;
        }

        $kept = $overpayment - $credited;

        if ($kept < 0) {
            return $unknown;
        }

        return ['build_up_credited' => self::pesos($credited), 'overpayment_kept' => self::pesos($kept)];
    }

    /**
     * Whether the stored data proves this build-up loan payment credited no
     * share capital: its member is a pending or rejected applicant now, and it
     * was recorded once a member's status could no longer be edited, so the
     * member was one then too.
     */
    private function provesNoCredit(): bool
    {
        $member = $this->loan?->borrower;

        return $member !== null
            && in_array($member->status, Borrower::NON_MEMBER_STATUSES, true)
            && $this->created_at !== null
            && $this->created_at->gte(CarbonImmutable::parse(self::MEMBER_STATUS_LOCKED_FROM, 'Asia/Manila'));
    }

    private static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }

    /** The borrower's "first last" name, or null when either part is missing (as SQL CONCAT gives). */
    private function firstLastName(): ?string
    {
        $borrower = $this->loan?->borrower;
        if ($borrower === null || $borrower->first_name === null || $borrower->last_name === null) {
            return null;
        }

        return $borrower->first_name.' '.$borrower->last_name;
    }
}
