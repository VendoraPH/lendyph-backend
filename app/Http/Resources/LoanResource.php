<?php

namespace App\Http\Resources;

use App\Models\AmortizationSchedule;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Services\BranchAccess;
use App\Services\CollateralSecurity;
use App\Services\LoanService;
use App\Services\PersistedScheduleFigures;
use App\Services\RepaymentService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
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
        new OA\Property(property: 'display_status', type: 'string', description: 'The status a screen shows: `past_due` for a released or ongoing loan holding an unpaid period past its due date and grace (the rule behind `status=past_due`), otherwise `status`. `status` itself never holds `past_due`.'),
        new OA\Property(property: 'current_approver', type: 'string', nullable: true, example: 'Manager', description: 'GET /loans and GET /loans/{id} only (requires `approvalSteps` eager-loaded). Name of the approval step a `for_review` loan is waiting on: the first `pending` step of `current_steps` in GET /loans/{id}/approval-steps. Null when the loan is not `for_review`, no step is pending, or the step name is blank. Never an empty string.'),
        new OA\Property(property: 'current_status_label', type: 'string', nullable: true, example: 'Credit Review', description: 'GET /loans and GET /loans/{id} only (requires `approvalSteps` eager-loaded). The configured status label, snapshotted when the loan was submitted: on a `for_review` loan, of the step `current_approver` names; on an `approved` loan, of its release step. Null for every other status, and when that step has no label or a blank one. Never an empty string.'),
        new OA\Property(property: 'outstanding_balance', type: 'number', deprecated: true, description: 'Deprecated: read `total_outstanding` (everything owed) or `outstanding_principal` (principal only) instead; removed once the frontend has moved. Unpaid principal plus `insurance_owed` (Loan::$outstanding_balance, the reports\' figure), never interest or penalty.'),
        new OA\Property(property: 'total_outstanding', type: 'number', nullable: true, description: 'Everything still owed: the periods\' unpaid principal, interest and penalty plus `insurance_owed`, in whole centavos. Equals GET /loans/{id}/summary `total_outstanding`. 0 before release. Null where the periods were not loaded.'),
        new OA\Property(property: 'outstanding_principal', type: 'number', nullable: true, description: 'Unpaid principal only, in whole centavos; the premium owed is `insurance_owed`. Equals GET /loans/{id}/summary `outstanding_principal`. Null where the periods were not loaded.'),
        new OA\Property(property: 'next_due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'current_due', type: 'number'),
        new OA\Property(property: 'overdue_amount', type: 'number'),
        new OA\Property(property: 'penalty_amount', type: 'number'),
        new OA\Property(property: 'total_payable', type: 'number', nullable: true, description: 'Principal, interest and penalty still owed on the periods, plus `insurance_owed`. Never includes the Share Capital Build-Up. Null before release, when nothing is owed yet (see `contract_total`).'),
        new OA\Property(property: 'contract_total', type: 'number', nullable: true, description: 'Principal plus interest over the whole schedule, in whole centavos, never the Share Capital Build-Up or penalty. After release: the stored schedule\'s (equals `amortization_schedule_totals.principal` + `.interest`). Before release: the schedule GET /loans/{id}/amortization-preview builds, on GET /loans/{id} only; null on lists and wherever the periods (after release) or the collaterals (before release, the single-loan view) were not loaded.'),
        new OA\Property(property: 'unitemised_deductions', type: 'number', description: '`total_deductions` less the itemised `deductions`, in whole centavos, signed: positive when the total is more than the items, negative when the items add up to more, 0 when they agree. The release voucher prints it as its own line.'),
        new OA\Property(property: 'amortization_schedule_totals', type: 'object', description: 'Only with `amortization_schedules`. Each column of the schedule added up in whole centavos.', properties: [
            new OA\Property(property: 'principal', type: 'number'),
            new OA\Property(property: 'interest', type: 'number'),
            new OA\Property(property: 'penalty', type: 'number'),
            new OA\Property(property: 'total_due', type: 'number'),
            new OA\Property(property: 'amount_paid', type: 'number'),
            new OA\Property(property: 'share_capital_build_up', type: 'number', description: 'One `scb_amount` per period, none on a period an extension or adjustment closed'),
            new OA\Property(property: 'total_payment', type: 'number', description: '`total_due` plus `share_capital_build_up`; equals POST /loans/preview\'s `amortization.totals.total_payment` for the same loan'),
        ]),
        new OA\Property(property: 'collateral_summary', type: 'object', nullable: true, description: 'GET /loans/{id} only; null where the loan\'s collaterals are not loaded (lists). The values the server pledged each collateral at (`snapshot_value`: its `amount`, or for share capital its member\'s balance) added up against the principal, by the rule POST /loans/preview\'s `collateral` uses. When the loan holds a share capital pledge and the caller lacks `share_capital:view`, `total_value` and `short_by` are null and `value_unknown` is true, because they would reveal the balance.', properties: [
            new OA\Property(property: 'total_value', type: 'number', nullable: true),
            new OA\Property(property: 'security_status', type: 'string', enum: ['secured', 'partially_secured', 'unsecured']),
            new OA\Property(property: 'short_by', type: 'number', nullable: true),
            new OA\Property(property: 'value_unknown', type: 'boolean', description: 'Present, and true, only when the totals are withheld'),
        ]),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true, description: 'When the loan was first submitted for approval: the submit step of its approval chain, requires `approvalSteps` eager-loaded (GET /loans and GET /loans/{id}). A loan from before the chain existed reads the audit row its submit wrote, on GET /loans/{id} only. Null when no record of a submission exists. A send-back and re-submit does not move it.'),
        new OA\Property(property: 'auto_pay_enabled', type: 'boolean'),
        new OA\Property(property: 'auto_pay_enabled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'auto_pay_enabled_by', type: 'integer', nullable: true, description: 'The id of the user who enabled auto-pay'),
        new OA\Property(property: 'auto_pay_cbs_reference', type: 'string', nullable: true, description: 'The CBS reference auto-pay was enabled with. Null while auto-pay is off, and for a caller holding neither `auto_pay:view` nor `auto_pay:toggle`.'),
        new OA\Property(property: 'borrower', type: 'object', description: 'The whole member, or only `id`, `full_name` and `borrower_code` for a caller without `borrowers:view` or who cannot see the member\'s branch.'),
        new OA\Property(property: 'co_makers', type: 'array', items: new OA\Items(type: 'object'), description: 'The co-makers; by the same rule as `borrower`, without their address, contact number, occupation, employer and income.'),
        new OA\Property(property: 'borrower_name', type: 'string', nullable: true),
        new OA\Property(property: 'loan_product_name', type: 'string', nullable: true),
        new OA\Property(property: 'account_officer_id', type: 'integer', nullable: true),
        new OA\Property(property: 'release_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'release_method', type: 'string', nullable: true, enum: ['cash', 'gcash', 'maya', 'bank_transfer', 'online'], description: 'How the release went out (a restructure: how its deductions were collected). Null for a loan released before it was recorded, which screens read as "Not recorded".'),
        new OA\Property(property: 'release_reference', type: 'string', nullable: true, description: 'The release\'s reference number; null for a cash release without one and for loans released before it was recorded.'),
        new OA\Property(property: 'rejection_remarks', type: 'string', nullable: true),
        new OA\Property(property: 'insurance_premium_percentage', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_premium_amount', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_payment_type', type: 'string', nullable: true, enum: ['full', 'partial']),
        new OA\Property(property: 'insurance_partial_amount', type: 'number', nullable: true),
        new OA\Property(property: 'insurance_remaining_balance', type: 'number'),
        new OA\Property(property: 'amount_due_now', type: 'object', nullable: true, description: 'What a payment must be today to clear the insurance premium owed and every open period due on or before today in full (the first open period when none is due yet), in the order the allocation takes money, plus the share capital build-up the payment credits. Null unless the loan is released or ongoing. `period_number` and `schedule_id` are the first of those periods and `last_period_number` the last; all null when no period is open.', properties: [
            new OA\Property(property: 'amount', type: 'number'),
            new OA\Property(property: 'insurance', type: 'number'),
            new OA\Property(property: 'principal', type: 'number'),
            new OA\Property(property: 'interest', type: 'number'),
            new OA\Property(property: 'penalty', type: 'number'),
            new OA\Property(property: 'share_capital_build_up', type: 'number'),
            new OA\Property(property: 'period_number', type: 'integer', nullable: true),
            new OA\Property(property: 'last_period_number', type: 'integer', nullable: true),
            new OA\Property(property: 'schedule_id', type: 'integer', nullable: true),
        ]),
        new OA\Property(property: 'restructurable_balance', type: 'number', nullable: true, description: 'The most a restructure may take as the new loan\'s principal: the open periods\' unpaid principal, interest and penalty, never the insurance premium owed (that moves to the new loan as premium owed). Null where the periods were not loaded.'),
        new OA\Property(property: 'insurance_owed', type: 'number', description: 'Insurance premium the release left unpaid and still owed: interest-free, never principal, collected first from each payment (same figure as `insurance_remaining_balance`)'),
        new OA\Property(property: 'source_loan_id', type: 'integer', nullable: true, description: 'The loan this one was restructured out of'),
        new OA\Property(property: 'is_restructure', type: 'boolean', description: 'True when source_loan_id is set'),
        new OA\Property(property: 'can_send_back_approved', type: 'boolean', description: 'True only for an approved restructure (not released yet) and a caller holding loans:release: the loan page may offer PATCH /api/loans/{id}/send-back-approved.'),
        new OA\Property(property: 'restructured_at', type: 'string', format: 'date-time', nullable: true, description: 'When this loan was closed because its balance moved to a restructure'),
        new OA\Property(property: 'restructured_balance', type: 'number', nullable: true, description: 'What was still owed on this loan when the restructure closed it'),
        new OA\Property(property: 'write_off_amount', type: 'number', nullable: true, description: 'On a source loan: the forgiveness approved, the approved balance (principal, interest and penalty) minus the new loan\'s principal, when the restructure took on less than the full balance. Not what the books wrote off: that is the new loan\'s `restructure_written_off_principal`.'),
        new OA\Property(property: 'restructure_written_off_principal', type: 'number', nullable: true, description: 'On a restructure released since Phase 2: the source\'s unpaid principal it did not carry, written off in the books (J2 on the source). Principal only, so it differs from the source\'s approved forgiveness `write_off_amount`. Null for other loans and for restructures released before it was recorded.'),
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
                    new OA\Property(property: 'restructure_written_off_principal', type: 'number', nullable: true, description: 'Principal written off in the books when that restructure released'),
                ],
            ),
        ),
    ],
)]
class LoanResource extends JsonResource
{
    /**
     * What this loan's periods still owe, set by self::summaryCollection():
     * present only on a GET /loans?view=summary row.
     *
     * @var array{principal: int, interest: int, penalty: int}|null
     */
    private ?array $summaryPeriods = null;

    /**
     * The rows of GET /loans?view=summary: what a list shows, with no schedule.
     * Each loan needs `openSchedules`, `borrower`, `loanProduct`, `branch` and
     * `approvalSteps` loaded. What the loans owe on all their periods, settled
     * ones included, comes from one grouped query for the page.
     *
     * @param  LengthAwarePaginator<int, Loan>  $loans
     */
    public static function summaryCollection(LengthAwarePaginator $loans): AnonymousResourceCollection
    {
        $owedOnPeriods = Loan::owedOnPeriodsByLoan($loans->getCollection()->modelKeys());
        $rows = static::collection($loans);

        $rows->collection->each(function (self $row) use ($owedOnPeriods): void {
            $row->summaryPeriods = $owedOnPeriods[$row->resource->getKey()] ?? ['principal' => 0, 'interest' => 0, 'penalty' => 0];
        });

        return $rows;
    }

    public function toArray(Request $request): array
    {
        if ($this->summaryPeriods !== null) {
            return $this->summaryRow();
        }

        // Compute payment-related fields from loaded schedules to avoid N+1
        $nextDueDate = null;
        $currentDue = 0.0;
        $overdueAmount = 0.0;
        $totalPenalty = 0.0;
        $totalPayable = 0.0;
        $owed = null;
        $scheduleTotals = null;
        $isReleased = $this->released_at !== null;

        if ($this->relationLoaded('amortizationSchedules')) {
            $owed = $this->resource->owedCentavos($this->amortizationSchedules);
            $scheduleTotals = PersistedScheduleFigures::totals($this->resource, $this->amortizationSchedules);

            $today = now()->startOfDay();
            $unpaidSchedules = $this->amortizationSchedules
                ->whereIn('status', ['pending', 'partial', 'overdue']);

            ['next_due_date' => $nextDueDate, 'current_due' => $currentDue, 'overdue_amount' => $overdueAmount]
                = $this->openPeriodFigures($unpaidSchedules, $today);

            // Total penalty remaining
            $totalPenalty = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
            }), 2);

            // Total payable = all remaining amounts (principal + interest +
            // penalty) plus the insurance premium still owed on the loan:
            // "Total Payable" is the amount still owed.
            $totalPayable = round($this->amortizationSchedules->sum(function ($s) {
                return max(0, (float) $s->principal_due - (float) $s->principal_paid)
                    + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                    + max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
            }) + (float) $this->insurance_remaining_balance, 2);
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
            // Past due is a property of the schedule, not a status. See
            // Loan::displayStatus().
            'display_status' => $this->resource->displayStatus(),
            // Only GET /loans and GET /loans/{id} load the chain, so every
            // other endpoint leaves the key out rather than paying a query per
            // loan. See Loan::currentApprover().
            'current_approver' => $this->whenLoaded('approvalSteps', fn () => $this->currentApprover()),
            // The configured label for that step, or an approved loan's release
            // step. See Loan::currentStatusLabel().
            'current_status_label' => $this->whenLoaded('approvalSteps', fn () => $this->currentStatusLabel()),
            // Read from the model accessor rather than recomputed here: this is
            // the same figure the reports and exports use, it includes any
            // uncollected insurance premium, and it stays correct when the
            // caller did not eager-load `amortizationSchedules` (this used to
            // silently serialise 0 — which is why every Releases List row read
            // as fully paid).
            //
            // Deprecated alias, kept one release: `total_outstanding` and
            // `outstanding_principal` below say which figure they are.
            'outstanding_balance' => $this->outstanding_balance,
            // Loan::owedCentavos(), the rule GET /loans/{id}/summary states
            // them by, so the list and the loan page agree to the centavo.
            'total_outstanding' => $owed === null ? null : $owed['total'] / 100,
            'outstanding_principal' => $owed === null ? null : $owed['principal'] / 100,
            'next_due_date' => $nextDueDate,
            'current_due' => $currentDue,
            'overdue_amount' => $overdueAmount,
            'penalty_amount' => $totalPenalty,
            // Nothing is owed before release, so no figure rather than 0.
            'total_payable' => $isReleased ? $totalPayable : null,
            'contract_total' => $this->contractTotal($isReleased, $scheduleTotals),
            // What a payment must be today to clear the insurance premium owed
            // and every open period due by today, from the server
            // (RepaymentService::amountDueNow()). Only for a loan that takes
            // payments, and only where the periods were loaded.
            // The restructure cap (LoanService::restructurableCentavos()),
            // where the periods were loaded.
            'restructurable_balance' => $this->relationLoaded('amortizationSchedules')
                ? LoanService::restructurableCentavos($this->amortizationSchedules) / 100
                : null,
            'amount_due_now' => $this->relationLoaded('amortizationSchedules') && in_array($this->status, Loan::ACTIVE_STATUSES, true)
                ? app(RepaymentService::class)->amountDueNow($this->resource, $this->amortizationSchedules, now())
                : null,
            // What the loan page's Auto-Pay card shows, so it is right after a
            // reload. The bank reference only for those who work with auto-pay.
            'auto_pay_enabled' => (bool) $this->auto_pay,
            'auto_pay_enabled_at' => $this->auto_pay_enabled_at,
            'auto_pay_enabled_by' => $this->auto_pay_enabled_by,
            'auto_pay_cbs_reference' => $request->user()?->canAny(['auto_pay:view', 'auto_pay:toggle'])
                ? $this->cbs_reference
                : null,
            // Where the chain is loaded. See Loan::submittedAt(). The audit trail
            // is read only on the single-loan view (the `collaterals` proxy
            // contractTotal() uses), so a list never pays a query per row.
            'submitted_at' => $this->whenLoaded('approvalSteps', fn () => $this->resource->submittedAt(
                readAuditTrail: $this->relationLoaded('collaterals'),
            )),
            'approval_remarks' => $this->approval_remarks,
            'approved_at' => $this->approved_at,
            'rejection_remarks' => $this->rejection_remarks,
            'rejected_by' => $this->rejected_by,
            'rejected_by_user' => new UserResource($this->whenLoaded('rejectedByUser')),
            'rejected_at' => $this->rejected_at,
            'released_at' => $this->released_at,
            'release_date' => $this->released_at?->toDateString(),
            'release_method' => $this->release_method,
            'release_reference' => $this->release_reference,
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
            'insurance_owed' => (float) $this->insurance_remaining_balance,
            'is_editable' => $this->is_editable,
            'is_releasable' => $this->is_releasable,
            'borrower' => $this->memberOnRow($request),
            'borrower_name' => $this->whenLoaded('borrower', fn () => $this->borrower->full_name),
            'borrower_id' => $this->borrower_id,
            'loan_product' => new LoanProductResource($this->whenLoaded('loanProduct')),
            'loan_product_id' => $this->loan_product_id,
            'loan_product_name' => $this->whenLoaded('loanProduct', fn () => $this->loanProduct->name),
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'co_makers' => $this->coMakersOnRow($request),
            'approved_by_user' => new UserResource($this->whenLoaded('approvedByUser')),
            'released_by_user' => new UserResource($this->whenLoaded('releasedByUser')),
            'created_by_user' => new UserResource($this->whenLoaded('createdByUser')),
            'account_officer_id' => $this->account_officer_id,
            'account_officer' => new UserResource($this->whenLoaded('accountOfficer')),
            'amortization_schedules' => $this->whenLoaded(
                'amortizationSchedules',
                fn () => AmortizationScheduleResource::forLoan($this->resource, $this->amortizationSchedules),
            ),
            // The schedule's column totals, in whole centavos, under the
            // column names AmortizationScheduleResource gives each row, so the
            // printed schedule never adds a column up itself. Only where the
            // schedule is loaded.
            'amortization_schedule_totals' => $this->whenLoaded('amortizationSchedules', fn () => $scheduleTotals),
            // The collaterals card's total, security status and shortfall
            // (CollateralSecurity, the rule the loan form's preview uses). Only
            // where `collaterals` is loaded (GET /loans/{id}); null elsewhere,
            // so a list pays nothing for it. For the user asking: a share
            // capital pledge's balance is withheld from one who cannot see it.
            'collateral_summary' => $this->relationLoaded('collaterals') ? CollateralSecurity::ofLoan($this->resource, $request->user()) : null,
            'source_loan_id' => $this->source_loan_id,
            'is_restructure' => $this->isRestructure(),
            'can_send_back_approved' => $this->isRestructure()
                && $this->status === 'approved'
                && (bool) $request->user()?->can('loans:release'),
            'restructured_at' => $this->restructured_at,
            'restructured_balance' => $this->restructured_balance !== null
                ? (float) $this->restructured_balance
                : null,
            'write_off_amount' => $this->write_off_amount !== null
                ? (float) $this->write_off_amount
                : null,
            'restructure_written_off_principal' => $this->restructure_written_off_principal !== null
                ? (float) $this->restructure_written_off_principal
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
                    'restructure_written_off_principal' => $child->restructure_written_off_principal !== null
                        ? (float) $child->restructure_written_off_principal
                        : null,
                ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * The member on a loan row. A caller without `borrowers:view` (the `manager`
     * and board roles, and any custom role like them) sees who the loan belongs
     * to and nothing else of theirs: the screens and printables that read this
     * row (loan page, release voucher, amortization schedule) use only these
     * three. The disclosure, the promissory note and the demand letter have
     * endpoints of their own.
     */
    private function memberOnRow(Request $request): mixed
    {
        return $this->whenLoaded('borrower', function () use ($request) {
            if ($this->borrower === null) {
                return null;
            }

            return $this->canSeeMember($request) ? new BorrowerResource($this->borrower) : [
                'id' => $this->borrower->id,
                'full_name' => $this->borrower->full_name,
                'borrower_code' => $this->borrower->borrower_code,
            ];
        });
    }

    /**
     * The co-makers on a loan row, by the same rule as memberOnRow(): their
     * names and relationship (what the loan page and its release card show),
     * never their address, contact number, work or income.
     */
    private function coMakersOnRow(Request $request): mixed
    {
        return $this->whenLoaded('coMakers', fn () => $this->canSeeMember($request)
            ? CoMakerResource::collection($this->coMakers)
            : $this->coMakers->map(fn (CoMaker $coMaker): array => CoMakerResource::slim($coMaker))->values()->all());
    }

    /**
     * Whether the caller may read this loan's member in full: `borrowers:view`
     * and the member's own branch, which a moved member's loan row doesn't
     * prove. A loan row otherwise would show what GET /borrowers/{id} answers
     * 404 for.
     */
    private function canSeeMember(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->can('borrowers:view')
            && app(BranchAccess::class)->canSee($user, $this->resource->borrower?->branch_id);
    }

    /**
     * A GET /loans?view=summary row. Each figure carries the meaning and equals
     * the value of the key of the same name on the full row; `next_due_amount`
     * is the full row's `current_due`.
     *
     * @return array<string, mixed>
     */
    private function summaryRow(): array
    {
        $today = now()->startOfDay();
        $open = $this->openSchedules;
        $figures = $this->openPeriodFigures($open, $today);
        $owed = $this->resource->owedWithInsurance($this->summaryPeriods);
        $borrower = $this->borrower;

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'loan_account_number' => $this->loan_account_number,
            'borrower' => $borrower === null ? null : [
                'id' => $borrower->id,
                'full_name' => $borrower->full_name,
                'borrower_code' => $borrower->borrower_code,
            ],
            'borrower_id' => $this->borrower_id,
            'borrower_name' => $borrower?->full_name,
            'loan_product' => $this->loanProduct === null ? null : [
                'id' => $this->loanProduct->id,
                'name' => $this->loanProduct->name,
            ],
            'loan_product_id' => $this->loan_product_id,
            'loan_product_name' => $this->loanProduct?->name,
            'branch' => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ],
            'principal_amount' => (float) $this->principal_amount,
            'interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'term' => $this->term,
            'term_unit' => $this->term_unit,
            'frequency' => $this->frequency,
            'maturity_date' => $this->maturity_date?->toDateString(),
            'status' => $this->status,
            'display_status' => $this->resource->displayStatus(),
            'current_approver' => $this->currentApprover(),
            'current_status_label' => $this->currentStatusLabel(),
            'is_restructure' => $this->isRestructure(),
            'released_at' => $this->released_at,
            'release_date' => $this->released_at?->toDateString(),
            'created_at' => $this->created_at,
            'next_due_date' => $figures['next_due_date'],
            'next_due_amount' => $figures['current_due'],
            'current_due' => $figures['current_due'],
            'amount_due_now' => in_array($this->status, Loan::ACTIVE_STATUSES, true)
                ? app(RepaymentService::class)->amountDueNow($this->resource, $open, now())
                : null,
            'total_outstanding' => $owed['total'] / 100,
            'outstanding_principal' => $owed['principal'] / 100,
            'overdue_amount' => $figures['overdue_amount'],
            'penalty_amount' => $owed['penalty'] / 100,
            'total_payable' => $this->released_at !== null ? $owed['total'] / 100 : null,
            'insurance_owed' => (float) $this->insurance_remaining_balance,
        ];
    }

    /**
     * The figures that come from a loan's open periods alone, for the full row
     * and the summary row alike.
     *
     * @param  Collection<int, AmortizationSchedule>  $open  its pending, partial and overdue periods
     * @return array{next_due_date: string|null, current_due: float, overdue_amount: float}
     */
    private function openPeriodFigures(Collection $open, Carbon $today): array
    {
        $nextSchedule = $open->sortBy('due_date')->first();

        // Current due = next unpaid schedule's remaining amount (explicit per-field for consistency)
        $currentDue = 0.0;

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
        $overdueAmount = round(AmortizationSchedule::lateUnpaid($open, $this->grace_period_days, $today)->sum(function ($s) {
            return max(0, (float) $s->principal_due - (float) $s->principal_paid)
                + max(0, (float) $s->interest_due - (float) $s->interest_paid)
                + max(0, (float) ($s->penalty_amount ?? 0) - (float) ($s->penalty_paid ?? 0));
        }), 2);

        return [
            'next_due_date' => $nextSchedule?->due_date?->toDateString(),
            'current_due' => $currentDue,
            'overdue_amount' => $overdueAmount,
        ];
    }

    /**
     * Principal plus interest over the whole schedule, in whole centavos,
     * never the Share Capital Build-Up (decision 7(a): SCB is not debt).
     *
     * After release, the stored schedule's (`$scheduleTotals`, null where the
     * periods were not loaded). Before release, the schedule the pre-release
     * amortization preview builds (LoanService::buildAmortizationPreview()),
     * but only where `collaterals` is loaded: GET /loans/{id}, the same proxy
     * `collateral_summary` uses. A list would otherwise build a schedule for
     * every unreleased row on the page, so it gets null.
     *
     * @param  array<string, float|int>|null  $scheduleTotals  PersistedScheduleFigures::totals()
     */
    private function contractTotal(bool $isReleased, ?array $scheduleTotals): ?float
    {
        if ($isReleased) {
            return $scheduleTotals === null
                ? null
                : (LoanService::toCentavos((float) $scheduleTotals['principal']) + LoanService::toCentavos((float) $scheduleTotals['interest'])) / 100;
        }

        if (! $this->relationLoaded('collaterals')) {
            return null;
        }

        $centavos = 0;

        foreach (app(LoanService::class)->buildAmortizationPreview($this->resource) as $row) {
            $centavos += LoanService::toCentavos((float) $row['principal_due']) + LoanService::toCentavos((float) $row['interest_due']);
        }

        return $centavos / 100;
    }
}
