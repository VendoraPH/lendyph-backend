<?php

namespace App\Http\Resources;

use App\Models\AmortizationSchedule;
use App\Services\CollateralSecurity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Loan',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'application_number', type: 'string'),
        new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
        new OA\Property(property: 'external_loan_no', type: 'string', nullable: true, description: "The cooperative's own reference for a loan migrated in by the CSV importer. Kept separate from `loan_account_number`, which is a generated LN- sequence."),
        new OA\Property(property: 'is_imported', type: 'boolean', description: 'True when this loan was migrated in from an existing book rather than originated here, so its amortization schedule is reconstructed and its pre-import arrears are not penalised.'),
        new OA\Property(property: 'interest_rate', type: 'number'),
        new OA\Property(property: 'interest_rate_frequency', type: 'string', enum: ['daily', 'weekly', 'bi_weekly', 'semi_monthly', 'monthly']),
        new OA\Property(property: 'interest_method', type: 'string'),
        new OA\Property(property: 'term', type: 'integer'),
        new OA\Property(property: 'term_unit', type: 'string', enum: ['months', 'days']),
        new OA\Property(property: 'is_one_month_term', type: 'boolean', description: 'Eligible for POST /loans/{id}/extend'),
        new OA\Property(property: 'extension_count', type: 'integer', description: 'Number of times this loan has been rolled forward via POST /loans/{id}/extend'),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'principal_amount', type: 'number'),
        new OA\Property(property: 'purpose', type: 'string', nullable: true),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'maturity_date', type: 'string', format: 'date'),
        new OA\Property(property: 'scb_amount', type: 'number', description: 'Share capital build-up amount per payment'),
        new OA\Property(property: 'policy_exception', type: 'boolean'),
        new OA\Property(property: 'policy_exception_details', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'current_approver', type: 'string', nullable: true, example: 'Manager', description: 'GET /loans and GET /loans/{id} only (requires `approvalSteps` eager-loaded). Name of the approval step a `for_review` loan is waiting on: the first `pending` step of `current_steps` in GET /loans/{id}/approval-steps. Null when the loan is not `for_review`, no step is pending, or the step name is blank. Never an empty string.'),
        new OA\Property(property: 'outstanding_balance', type: 'number'),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'current_due', type: 'number'),
        new OA\Property(property: 'overdue_amount', type: 'number'),
        new OA\Property(property: 'penalty_amount', type: 'number'),
        new OA\Property(property: 'total_payable', type: 'number'),
        new OA\Property(property: 'unitemised_deductions', type: 'number', description: '`total_deductions` less the itemised `deductions`, in whole centavos, signed: positive when the total is more than the items, negative when the items add up to more, 0 when they agree. The release voucher prints it as its own line.'),
        new OA\Property(property: 'amortization_schedule_totals', type: 'object', description: 'Only with `amortization_schedules`. Each column of the schedule added up in whole centavos.', properties: [
            new OA\Property(property: 'principal', type: 'number'),
            new OA\Property(property: 'interest', type: 'number'),
            new OA\Property(property: 'penalty', type: 'number'),
            new OA\Property(property: 'total_due', type: 'number'),
            new OA\Property(property: 'amount_paid', type: 'number'),
        ]),
        new OA\Property(property: 'collateral_summary', type: 'object', nullable: true, description: 'GET /loans/{id} only; null where the loan\'s collaterals are not loaded (lists). The pledged `snapshot_value`s added up against the principal, by the rule POST /loans/preview\'s `collateral` uses.', properties: [
            new OA\Property(property: 'total_value', type: 'number'),
            new OA\Property(property: 'security_status', type: 'string', enum: ['secured', 'partially_secured', 'unsecured']),
            new OA\Property(property: 'short_by', type: 'number'),
        ]),
        new OA\Property(property: 'borrower_name', type: 'string', nullable: true),
        new OA\Property(property: 'loan_product_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_officer_id', type: 'integer', nullable: true),
        new OA\Property(property: 'release_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'rejection_remarks', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_premium_percentage', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_premium_amount', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_payment_type', type: 'string', nullable: true, enum: ['full', 'partial']),
        new OA\Property(property: 'insurance_partial_amount', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_remaining_balance', type: 'number'),
        new OA\Property(property: 'source_loan_id', type: 'integer', nullable: true, description: 'The loan this one was restructured out of'),
        new OA\Property(property: 'is_restructure', type: 'boolean', description: 'True when source_loan_id is set'),
        new OA\Property(property: 'restructured_at', type: 'string', format: 'date-time', nullable: true, description: 'When this loan was closed because its balance moved to a restructure'),
        new OA\Property(property: 'restructured_balance', type: 'number', nullable: true, description: 'What was still owed on this loan when the restructure closed it'),
        new OA\Property(property: 'write_off_amount', type: 'number', nullable: true, description: 'restructured_balance minus the new loan\'s principal, when the restructure took on less than the full balance'),
        new OA\Property(
            property: 'source_loan',
            type: 'object',
            nullable: true,
            description: 'Flat summary of the source loan (requires `sourceLoan` eager-loaded). Flat rather than a nested Loan to keep a chain of restructures from recursing.',
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'application_number', type: 'string'),
                new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'principal_amount', type: 'number'),
                new OA\Property(property: 'restructured_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'restructured_balance', type: 'number', nullable: true),
                new OA\Property(property: 'write_off_amount', type: 'number', nullable: true),
            ],
        ),
        new OA\Property(
            property: 'restructured_into',
            type: 'array',
            description: 'Flat summaries of restructure applications derived from this loan (requires `restructuredInto` eager-loaded).',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'application_number', type: 'string'),
                    new OA\Property(property: 'loan_account_number', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'principal_amount', type: 'number'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
                ],
            ),
        ),
    ],
)]
class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Compute payment-related fields from loaded schedules to avoid N+1
        $nextDueDate = null;
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

            // Overdue = sum of remaining amounts on schedules that are LATE:
            // past the due date AND past the grace period this loan grants.
            //
            // This used to be a bare `due_date < today`, which put a row on the
            // loans list reading "₱X overdue" for a loan the Past Due tab
            // correctly excluded, because that tab honours grace. Shares the
            // cutoff with RepaymentService::getLoanSummary(), which computes
            // the same figure for the loan detail screen.
            $overdueSchedules = AmortizationSchedule::lateUnpaid(
                $unpaidSchedules,
                $this->grace_period_days,
                $today,
            );
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
            'external_loan_no' => $this->external_loan_no,
            // Lets the UI label a reconstructed schedule — see
            // Loan::isImported(). A label only: the penalty and default paths
            // read the baseline through
            // AmortizationSchedule::isPenalisable(), never this flag.
            'is_imported' => $this->isImported(),
            'interest_rate' => $this->interest_rate,
            'interest_rate_frequency' => $this->interest_rate_frequency,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'term_unit' => $this->term_unit,
            // Eligibility for the Extend Loan action, computed here so the
            // frontend doesn't need to duplicate the frequency + term rule
            // from Loan::isOneMonthTerm().
            'is_one_month_term' => $this->isOneMonthTerm(),
            // Distinct from `term`, which stays fixed at the agreed value —
            // see Loan::extensionCount().
            'extension_count' => $this->extension_count,
            'frequency' => $this->frequency,
            'principal_amount' => (float) $this->principal_amount,
            'purpose' => $this->purpose,
            'start_date' => $this->start_date?->toDateString(),
            'maturity_date' => $this->maturity_date?->toDateString(),
            'deductions' => $this->deductions,
            'total_deductions' => $this->total_deductions,
            // The total less the items, signed, for the release voucher's
            // "Other deductions" or "Adjustment" line. See
            // Loan::unitemisedDeductions().
            'unitemised_deductions' => $this->resource->unitemisedDeductions(),
            'net_proceeds' => $this->net_proceeds,
            'scb_amount' => (float) $this->scb_amount,
            'penalty_rate' => $this->penalty_rate,
            'grace_period_days' => $this->grace_period_days,
            'policy_exception' => (bool) $this->policy_exception,
            'policy_exception_details' => $this->policy_exception_details,
            // URL of the policy exception letter Document, if uploaded (requires `documents` eager-loaded).
            'policy_exception_letter' => $this->whenLoaded('documents', function () {
                $doc = $this->documents->firstWhere('type', 'policy_exception_letter');

                return $doc?->url;
            }),
            'status' => $this->status,
            // Only GET /loans and GET /loans/{id} load the chain, so every
            // other endpoint leaves the key out rather than paying a query per
            // loan. See Loan::currentApprover().
            'current_approver' => $this->whenLoaded('approvalSteps', fn () => $this->currentApprover()),
            // Read from the model accessor rather than recomputed here: this is
            // the same figure the reports and exports use, it includes any
            // uncollected insurance premium, and it stays correct when the
            // caller did not eager-load `amortizationSchedules` (this used to
            // silently serialise 0 — which is why every Releases List row read
            // as fully paid).
            'outstanding_balance' => $this->outstanding_balance,
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
            'insurance_premium_percentage' => $this->insurance_premium_pct !== null
                ? (float) $this->insurance_premium_pct
                : null,
            'insurance_premium_amount' => $this->insurance_premium_amount !== null
                ? (float) $this->insurance_premium_amount
                : null,
            'insurance_payment_type' => $this->insurance_payment_type,
            'insurance_partial_amount' => $this->insurance_partial_amount !== null
                ? (float) $this->insurance_partial_amount
                : null,
            'insurance_remaining_balance' => (float) $this->insurance_remaining_balance,
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
            // The schedule's column totals, in whole centavos, under the
            // column names AmortizationScheduleResource gives each row, so the
            // printed schedule never adds a column up itself. Only where the
            // schedule is loaded.
            'amortization_schedule_totals' => $this->whenLoaded('amortizationSchedules', fn () => $this->scheduleTotals()),
            // The collaterals card's total, security status and shortfall
            // (CollateralSecurity, the rule the loan form's preview uses). Only
            // where `collaterals` is loaded (GET /loans/{id}); null elsewhere,
            // so a list pays nothing for it.
            'collateral_summary' => $this->relationLoaded('collaterals') ? CollateralSecurity::ofLoan($this->resource) : null,
            'source_loan_id' => $this->source_loan_id,
            'is_restructure' => $this->isRestructure(),
            'restructured_at' => $this->restructured_at,
            'restructured_balance' => $this->restructured_balance !== null
                ? (float) $this->restructured_balance
                : null,
            'write_off_amount' => $this->write_off_amount !== null
                ? (float) $this->write_off_amount
                : null,
            // Lineage is FLAT, not a nested LoanResource: a chain of
            // restructures would recurse, and LoanResource's totals need
            // `amortizationSchedules` loaded, which these summaries never are.
            'source_loan' => $this->whenLoaded('sourceLoan', fn () => $this->sourceLoan ? [
                'id' => $this->sourceLoan->id,
                'application_number' => $this->sourceLoan->application_number,
                'loan_account_number' => $this->sourceLoan->loan_account_number,
                'status' => $this->sourceLoan->status,
                'principal_amount' => (float) $this->sourceLoan->principal_amount,
                'restructured_at' => $this->sourceLoan->restructured_at,
                'restructured_balance' => $this->sourceLoan->restructured_balance !== null
                    ? (float) $this->sourceLoan->restructured_balance
                    : null,
                'write_off_amount' => $this->sourceLoan->write_off_amount !== null
                    ? (float) $this->sourceLoan->write_off_amount
                    : null,
            ] : null),
            'restructured_into' => $this->whenLoaded('restructuredInto', fn () => $this->restructuredInto
                ->map(fn ($child) => [
                    'id' => $child->id,
                    'application_number' => $child->application_number,
                    'loan_account_number' => $child->loan_account_number,
                    'status' => $child->status,
                    'principal_amount' => (float) $child->principal_amount,
                    'start_date' => $child->start_date?->toDateString(),
                ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * @return array{principal: float|int, interest: float|int, penalty: float|int, total_due: float|int, amount_paid: float|int}
     */
    private function scheduleTotals(): array
    {
        $totals = ['principal' => 0, 'interest' => 0, 'penalty' => 0, 'total_due' => 0, 'amount_paid' => 0];
        $centavos = fn (mixed $pesos): int => (int) round((float) ($pesos ?? 0) * 100);

        foreach ($this->amortizationSchedules as $row) {
            $totals['principal'] += $centavos($row->principal_due);
            $totals['interest'] += $centavos($row->interest_due);
            $totals['penalty'] += $centavos($row->penalty_amount);
            $totals['total_due'] += $centavos($row->total_due);
            $totals['amount_paid'] += $centavos($row->principal_paid) + $centavos($row->interest_paid) + $centavos($row->penalty_paid);
        }

        return array_map(fn (int $sum): float|int => $sum / 100, $totals);
    }
}
