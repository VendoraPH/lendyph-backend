<?php

namespace App\Http\Resources;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\AccountingReconciliationLine;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\Collateral;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogModules;
use App\Services\AuditLogService;
use App\Services\PersonalDataKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class AuditLogResource extends JsonResource
{
    /**
     * Change-list fields that hold a user id, shown as that user's name.
     */
    private const USER_FOREIGN_KEYS = [
        'account_officer_id', 'acted_by', 'added_by', 'adjusted_by', 'approved_by',
        'auto_pay_enabled_by', 'checked_by', 'closed_by', 'created_by', 'decided_by',
        'first_checker_id', 'initiated_by', 'paid_by_user_id', 'posted_by', 'processed_by',
        'received_by', 'rejected_by', 'released_by', 'reopened_by', 'requested_by',
        'reversed_by', 'settled_by', 'transactor_user_id', 'updated_by', 'user_id', 'voided_by',
    ];

    /**
     * Never listed as a change: every save moves them.
     */
    private const TIMESTAMP_KEYS = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Never listed as a change either: a hash or an internal key that means
     * nothing to a reader (a savings approval's or interest run's `live_key`,
     * an account's `open_key`, an idempotency key's `request_hash`).
     */
    private const TECHNICAL_KEYS = ['fee_fingerprint', 'fingerprint', 'live_key', 'open_key', 'request_hash'];

    /**
     * On a savings row only (self::isSavingsRow()), the fields holding the id
     * of another savings record, by the kind of record: read as a transaction's
     * reference, an account's number, a product's name, a time deposit's
     * certificate number or an interest run's period. Elsewhere the same names
     * (`account_id`, `product_id`, `run_id`) mean other things and read as
     * stored.
     */
    private const SAVINGS_ID_KEYS = [
        'savingsTransactions' => [
            'counterpart_transaction_id', 'funding_transaction_id', 'interest_transaction_id',
            'result_transaction_id', 'reverses_transaction_id', 'target_transaction_id', 'tax_transaction_id',
        ],
        'savingsAccounts' => ['account_id', 'funding_account_id', 'payout_account_id', 'to_account_id'],
        'savingsProducts' => ['product_id', 'pretermination_product_id'],
        'timeDeposits' => ['time_deposit_id', 'rolled_over_from_id'],
        'savingsRuns' => ['run_id', 'interest_run_id'],
    ];

    /** What a savings id reads as when its record is gone ("Transaction #12"). */
    private const SAVINGS_ID_KINDS = [
        'savingsTransactions' => 'Transaction',
        'savingsAccounts' => 'Savings account',
        'savingsProducts' => 'Savings product',
        'timeDeposits' => 'Time deposit',
        'savingsRuns' => 'Interest run',
    ];

    /**
     * Change-list fields that hold a loan product id or a list of them.
     */
    private const PRODUCT_KEYS = ['loan_product_id', 'applicable_product_ids'];

    /**
     * Approval-chain step ids (one, or the list an approval closed), read by
     * each step's name.
     */
    private const STEP_KEYS = ['step_id', 'steps_closed'];

    /**
     * A change-list field holding a loan id: `loan_id`, `source_loan_id`,
     * `restructured_into_loan_id` and the like.
     */
    private const LOAN_KEY = '/(^|_)loan_id$/';

    /**
     * Change-list fields that hold a collateral id or a list of them.
     */
    private const COLLATERAL_KEYS = ['collateral_id', 'inherited_collateral_ids'];

    /**
     * Kinds whose class name doesn't read as what it holds, in the Audit Trail
     * screen's words (the frontend's `TARGET_TYPE_LABELS`): the Accounting
     * menu's names ("Journal entry", "Expense"), "GCash walk-in" (never
     * "non-member") and the module records. A target with nothing to name it
     * by reads this way ("Journal entry #2"), so the screen's "Created:
     * <record>" and the target heading say the same; so does the CSV's Target
     * Type. Display only: stored rows keep the class name.
     */
    private const TYPE_WORDS = [
        'AccountingJournal' => 'Journal entry',
        'AccountingJournalLine' => 'Journal entry line',
        'AccountingAccount' => 'Account',
        'AccountingAccountMapping' => 'Account mapping',
        'AccountingAllowancePosting' => 'Allowance for credit losses posting',
        'AccountingExpense' => 'Expense',
        'AccountingExpensePayment' => 'Expense payment',
        'AccountingOpeningBalance' => 'Opening balance',
        'AccountingYearClose' => 'Year-end close',
        'GCashNonMember' => 'GCash walk-in',
        'CsvImportRun' => 'Data import',
        'SavingsApproval' => 'Savings approval request',
        'SavingsSetting' => 'Savings settings',
        'ReminderSetting' => 'Reminder settings',
        'ReminderBranchSetting' => 'Branch reminder settings',
        'BorrowerNotificationPreference' => 'Member reminder preferences',
        'CdaSetting' => 'CDA settings',
        'CdaReportValue' => 'CDA report figure',
        'CdaReportSnapshot' => 'CDA report version',
        'CdaSubmission' => 'CDA filing',
        'CdaOfficerTraining' => 'Officer training',
        'CdaSocialProgram' => 'Social program',
        'CdaMediationCase' => 'Mediation case',
        'CdaNoticeSent' => 'CDA notice',
        'PortalUser' => 'Portal account',
        'PortalCode' => 'Portal code',
        'PortalSetting' => 'Member Portal settings',
        'PortalTermsVersion' => 'Portal terms',
        'PortalRequest' => 'Portal request',
        'PortalAnnouncement' => 'Portal announcement',
        // Read by its name while it exists; this only when it is gone.
        'PenaltyConfig' => 'Penalty configuration',
    ];

    /**
     * Change-list fields that hold a journal's id: read by the journal's
     * number ("JE-000014"), else its reference, never by its database id.
     */
    private const JOURNAL_KEYS = ['journal_id', 'accounting_journal_id', 'reverses_journal_id', 'reversed_by_journal_id'];

    /**
     * Change-list fields that hold a journal line's id: read as its journal's
     * number and the line's own number ("JE-000027 line 2").
     */
    private const JOURNAL_LINE_KEYS = ['matched_journal_line_id'];

    /**
     * A loan product's columns stored as a percentage, checked against the
     * request rules (`max:100`) and LoanService::productFeeDeductions(), which
     * charges the three fees as percentages. Read as a percent, never rounded.
     */
    private const PRODUCT_PERCENT_FIELDS = [
        'interest_rate', 'min_interest_rate', 'penalty_rate', 'notarial_fee',
        'processing_fee', 'min_processing_fee', 'max_processing_fee',
        'service_fee', 'min_service_fee', 'max_service_fee',
    ];

    /**
     * Savings columns stored as a percentage (decimal:4), by the class of the
     * row's target. Read as a percent, never rounded, like a loan product's.
     */
    private const SAVINGS_PERCENT_FIELDS = [
        'SavingsProductRate' => ['annual_rate'],
        'SavingsTaxRule' => ['rate'],
        'TimeDeposit' => ['annual_rate', 'pretermination_rate', 'withholding_tax_rate'],
        'SavingsInterestRunLine' => ['rate', 'tax_rate'],
    ];

    /**
     * Change-list fields that hold a branch id or a list of them.
     */
    private const BRANCH_KEYS = ['branch_id', 'branch_ids'];

    /**
     * Columns stored in whole centavos, by the class of the row's target. Any
     * field named `*_centavos` is too, on any target.
     */
    private const CENTAVO_FIELDS = [
        'AccountingJournal' => ['total_debit', 'total_credit'],
        'AccountingExpense' => ['amount', 'amount_paid'],
        'AccountingExpensePayment' => ['amount'],
        'AccountingJournalLine' => ['debit', 'credit'],
        'AccountingReconciliation' => ['statement_balance'],
        'AccountingReconciliationLine' => ['amount'],
        'AccountingYearClose' => ['net_surplus'],
    ];

    /**
     * A money field's name, on a row a service wrote (whose keys aren't always
     * columns) or one with no target: a word for money anywhere in the name
     * (`outstanding_balance`, `collected_at_release`, `new_principal`), unless
     * NOT_MONEY_FIELD says it is about money rather than an amount of it.
     */
    private const MONEY_FIELD = '/(^|_)(amount|applied|balance|budget|charge|charged|collected|credit|debit|deductions|due|expense|income|interest|outstanding|overpayment|owed|paid|penalty|premium|principal|proceeds|shortfall|waived)(_|$)|^(snapshot_value|total_payment)$/';

    private const NOT_MONEY_FIELD = '/(_at|_by|_count|_date|_id|_ids|_method|_number|_pct|_percentage|_rate|_status|_type)$|^auto_credit$/';

    /**
     * Fields holding an enum key, shown in words. Never a status: the frontend
     * maps those from the raw value.
     */
    private const ENUM_FIELDS = [
        'adjustment_type', 'cash_flow_category', 'cash_kind', 'category', 'civil_status', 'frequencies',
        'frequency', 'funding_method', 'gender', 'holder_type', 'insurance_payment_type', 'interest_crediting',
        'interest_method', 'interest_option', 'interest_rate_frequency', 'interest_type', 'kind',
        'maturity_instruction', 'method', 'normal_balance', 'payment_frequency', 'payment_method', 'payment_type',
        'payout_method', 'retaken_on', 'source', 'term_unit', 'type',
        // Members and CDA Reports (Phase 3).
        'asset_class', 'asset_class_source', 'cooperative_category', 'funding_source', 'member_type', 'origin',
        'part', 'position', 'report_type', 'sex', 'training_type',
    ];

    /**
     * The enum keys Str::headline() doesn't read the way the app does.
     */
    private const ENUM_WORDS = [
        'bi_weekly' => 'Bi-Weekly',
        'gcash' => 'GCash',
        'semi_monthly' => 'Semi-Monthly',
        // Savings (Phase 3). A non-member reads "Walk-in", as on every screen.
        'non_member' => 'Walk-in',
        'semi_annual' => 'Semi-Annual',
        'td_payout' => 'Time Deposit Payout',
        'rollover_principal' => 'Roll Over Principal',
        'rollover_all' => 'Roll Over Principal and Interest',
        // CDA Reports (Phase 3): report types, halves, funds and filing methods.
        'capr' => 'CAPR',
        'atir' => 'ATIR',
        'abr' => 'ABR',
        'pesos' => 'Enhanced PESOS',
        'officers_trainings' => 'Officers and Trainings',
        'h1' => 'First half (January–June)',
        'h2' => 'Second half (July–December)',
        'cdf' => 'CDF',
        'cetf' => 'CETF',
        'cais' => 'CAIS',
        'ecoophub' => 'e-CoopHub',
    ];

    private const TIMESTAMP_WITH_SECONDS = 'M j, Y, g:i:s A';

    /**
     * Load, in ONE query each, the users, roles, branches, loan products,
     * loans, collaterals, journals, journal lines, approval steps and savings
     * records (self::savingsNames()) these rows' old and new values name, and
     * hand the sets to every row so the change list can show names instead of
     * ids and keys without a query per row.
     *
     * @param  iterable<AuditLog>  $logs
     */
    public static function loadChangeNames(iterable $logs): void
    {
        $logs = collect($logs);

        $userIds = self::changeIds($logs, self::USER_FOREIGN_KEYS);
        $branchIds = self::changeIds($logs, self::BRANCH_KEYS);
        $productIds = self::changeIds($logs, self::PRODUCT_KEYS);
        $collateralIds = self::changeIds($logs, self::COLLATERAL_KEYS);
        $journalIds = self::changeIds($logs, self::JOURNAL_KEYS);
        $journalLineIds = self::changeIds($logs, self::JOURNAL_LINE_KEYS);
        $loanIds = $logs->flatMap(fn (AuditLog $log) => self::changeIds(collect([$log]), self::loanKeysOf($log)))->unique()->values();
        $roleKeys = self::changeValues($logs, ['role', ...self::STEP_KEYS])->filter(fn ($key) => is_string($key))->unique()->values();
        $stepLoanIds = $logs
            ->filter(fn (AuditLog $log) => $log->auditable_type === Loan::class && self::changeValues(collect([$log]), self::STEP_KEYS)->isNotEmpty())
            ->pluck('auditable_id')
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $branches = $branchIds->isEmpty()
            ? collect()
            : Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        $roles = $roleKeys->isEmpty()
            ? collect()
            : Role::query()->whereIn('name', $roleKeys)->where('guard_name', 'web')->get()->keyBy('name');

        $products = $productIds->isEmpty()
            ? collect()
            : LoanProduct::query()->whereIn('id', $productIds)->pluck('name', 'id');

        $loans = $loanIds->isEmpty()
            ? collect()
            : Loan::query()->whereIn('id', $loanIds)->get(['id', 'loan_account_number', 'application_number'])
                ->mapWithKeys(fn (Loan $loan) => [$loan->id => $loan->loan_account_number ?? $loan->application_number]);

        // "Vehicle: ABC 1234", in one query with the type's name.
        $collaterals = $collateralIds->isEmpty()
            ? collect()
            : Collateral::query()
                ->leftJoin('collateral_types', 'collateral_types.id', '=', 'collaterals.collateral_type_id')
                ->whereIn('collaterals.id', $collateralIds)
                ->get(['collaterals.id', 'collaterals.detail_value', 'collateral_types.name as type_name'])
                ->mapWithKeys(fn (Collateral $collateral) => [$collateral->id => collect([$collateral->type_name, $collateral->detail_value])
                    ->filter(fn ($part) => filled($part))->implode(': ') ?: "Collateral #{$collateral->id}"]);

        $journals = $journalIds->isEmpty()
            ? collect()
            : AccountingJournal::query()->whereIn('id', $journalIds)->get(['id', 'journal_no', 'reference'])
                ->mapWithKeys(fn (AccountingJournal $journal) => [$journal->id => self::journalName($journal->journal_no, $journal->reference)])
                ->filter();

        $journalLines = $journalLineIds->isEmpty()
            ? collect()
            : AccountingJournalLine::query()
                ->join('accounting_journals', 'accounting_journals.id', '=', 'accounting_journal_lines.accounting_journal_id')
                ->whereIn('accounting_journal_lines.id', $journalLineIds)
                ->get(['accounting_journal_lines.id', 'accounting_journal_lines.accounting_journal_id', 'accounting_journal_lines.line_no', 'accounting_journals.journal_no', 'accounting_journals.reference'])
                ->mapWithKeys(fn (AccountingJournalLine $line) => [$line->id => (self::journalName($line->journal_no, $line->reference) ?? "Journal #{$line->accounting_journal_id}")." line {$line->line_no}"]);

        // Keyed by loan and step id; a later round's snapshot wins.
        $steps = $stepLoanIds->isEmpty()
            ? collect()
            : LoanApprovalStep::query()->whereIn('loan_id', $stepLoanIds)->orderBy('round')->orderBy('id')
                ->get(['loan_id', 'step_id', 'name'])
                ->mapWithKeys(fn (LoanApprovalStep $step) => ["{$step->loan_id}:{$step->step_id}" => $step->name]);

        $savingsNames = self::savingsNames($logs);

        $logs->each(fn (AuditLog $log) => $log->setRelation('changeUsers', $users)
            ->setRelation('changeSavings', $savingsNames)
            ->setRelation('changeBranches', $branches)
            ->setRelation('changeRoles', $roles)
            ->setRelation('changeProducts', $products)
            ->setRelation('changeSteps', $steps)
            ->setRelation('changeLoans', $loans)
            ->setRelation('changeCollaterals', $collaterals)
            ->setRelation('changeJournals', $journals)
            ->setRelation('changeJournalLines', $journalLines));
    }

    /**
     * The savings records the savings rows' values name, by kind and id, in
     * one query per kind that appears.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return Collection<string, Collection<int, string>>
     */
    private static function savingsNames(Collection $logs): Collection
    {
        $savingsLogs = $logs->filter(fn (AuditLog $log) => self::isSavingsRow($log->auditable_type))->values();

        return collect(self::SAVINGS_ID_KEYS)->map(function (array $fields, string $kind) use ($savingsLogs): Collection {
            $ids = self::changeIds($savingsLogs, $fields);

            if ($ids->isEmpty()) {
                return collect();
            }

            return match ($kind) {
                'savingsTransactions' => SavingsTransaction::query()->whereIn('id', $ids)->pluck('reference', 'id'),
                'savingsAccounts' => SavingsAccount::query()->whereIn('id', $ids)->pluck('account_number', 'id'),
                'savingsProducts' => SavingsProduct::query()->whereIn('id', $ids)->pluck('name', 'id'),
                'timeDeposits' => TimeDeposit::query()->whereIn('id', $ids)->pluck('certificate_number', 'id'),
                'savingsRuns' => SavingsInterestRun::query()->whereIn('id', $ids)->get(['id', 'period_start', 'period_end'])
                    ->mapWithKeys(fn (SavingsInterestRun $run) => [$run->id => "Interest run #{$run->id} ({$run->period_start->format('M j, Y')} to {$run->period_end->format('M j, Y')})"]),
            };
        });
    }

    /**
     * Whether a row's target is one of the savings models (`Savings…` or
     * `TimeDeposit`), whose `account_id`, `product_id` and `run_id` name
     * savings records.
     */
    private static function isSavingsRow(?string $type): bool
    {
        $basename = $type === null ? '' : class_basename($type);

        return str_starts_with($basename, 'Savings') || $basename === 'TimeDeposit';
    }

    /**
     * A journal as people know it: its number once posted, else its
     * reference, else null (the caller then reads it by id in words).
     */
    private static function journalName(?string $number, ?string $reference): ?string
    {
        return filled($number) ? $number : (filled($reference) ? $reference : null);
    }

    /**
     * The fields of a row's old and new values that hold a loan id.
     *
     * @return list<string>
     */
    private static function loanKeysOf(AuditLog $log): array
    {
        $keys = [
            ...array_keys(is_array($log->old_values) ? $log->old_values : []),
            ...array_keys(is_array($log->new_values) ? $log->new_values : []),
        ];

        return array_values(array_unique(array_filter(array_map('strval', $keys), fn (string $key) => preg_match(self::LOAN_KEY, $key) === 1)));
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @param  list<string>  $fields
     * @return Collection<int, mixed>
     */
    private static function changeValues(Collection $logs, array $fields): Collection
    {
        return $logs->flatMap(fn (AuditLog $log) => [
            ...(is_array($log->old_values) ? array_values(array_intersect_key($log->old_values, array_flip($fields))) : []),
            ...(is_array($log->new_values) ? array_values(array_intersect_key($log->new_values, array_flip($fields))) : []),
        ])->map(self::decoded(...))->flatten();
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @param  list<string>  $fields
     * @return Collection<int, int>
     */
    private static function changeIds(Collection $logs, array $fields): Collection
    {
        return self::changeValues($logs, $fields)
            ->filter(self::isId(...))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private static function isId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * A row's old and new values as $user may read them: without the personal
     * fields of a member, co-maker, contact preference, reminder pause or
     * credit row for a
     * caller without `borrowers:view` (and, for credit rows,
     * `credit_scoring:view`) (PersonalDataKeys). The list, the detail and the
     * CSV all read them through here. The stored row is never changed.
     *
     * @return array{0: mixed, 1: mixed}
     */
    public static function valuesShownTo(AuditLog $log, ?User $user): array
    {
        return array_map(
            fn (mixed $values) => is_array($values) ? PersonalDataKeys::auditValuesFor($user, $log->auditable_type, $values) : $values,
            [$log->old_values, $log->new_values],
        );
    }

    public function toArray(Request $request): array
    {
        $basename = $this->auditable_type ? class_basename($this->auditable_type) : null;
        [$oldValues, $newValues] = self::valuesShownTo($this->resource, $request->user());

        return [
            'id' => $this->id,
            'user' => new UserResource($this->resource->inferredActor() ?? $this->whenLoaded('user')),
            // True when `user` is the signed-in user of an old login row that
            // recorded no user_id, rather than the row's own actor.
            'actor_inferred' => $this->resource->inferredActor() !== null,
            // Who wrote the row: `staff` (`user`), `member` (a member's own
            // portal action; `user` is null and `actor_label` names them) or
            // `system` (neither).
            'actor_type' => $this->actorType(),
            'actor_label' => self::memberActorLabel($this->resource),
            'action' => $this->action,
            // Frontend-canonical module slug; the list's `module` filter uses
            // the same rules (AuditLogModules).
            'module' => AuditLogModules::for($this->action, $this->auditable_type, $this->description),
            // Target summary (frontend renders as "type #id — label")
            'target' => $this->auditable_id ? [
                'id' => $this->auditable_id,
                'type' => $basename ?? 'Unknown',
                'label' => $this->resolveAuditableLabel(),
            ] : null,
            // Structured diff of old vs new values
            'changes' => $this->buildChanges($oldValues, $newValues),
            // Legacy fields kept for backward compat
            'auditable_type' => $basename,
            'auditable_id' => $this->auditable_id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $this->ip_address,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }

    private function actorType(): string
    {
        if ($this->resource->actor_type === AuditLogService::ACTOR_MEMBER) {
            return 'member';
        }

        return $this->resource->user_id !== null || $this->resource->inferredActor() !== null ? 'staff' : 'system';
    }

    /**
     * "Member: <name> (<code>)" for a row a member's own portal action wrote
     * (portal design §5), else null. Name and member number only, which round
     * fz allows any audit reader.
     */
    public static function memberActorLabel(AuditLog $log): ?string
    {
        if ($log->actor_type !== AuditLogService::ACTOR_MEMBER) {
            return null;
        }

        $member = $log->portalUser?->borrower;

        if ($member === null) {
            return 'Member';
        }

        return sprintf('Member: %s (%s)', $member->full_name, $member->membership_number ?: $member->borrower_code);
    }

    private function resolveAuditableLabel(): string
    {
        if (! $this->relationLoaded('auditable') || ! $this->auditable) {
            return $this->fallbackLabel();
        }

        $model = $this->auditable;

        if ($model instanceof Role) {
            return $model->auditLabel();
        }

        if ($model instanceof AccountingReconciliationLine) {
            return self::statementLineLabel($model);
        }

        // A mediation document's title (or a version's) can name the parties
        // to a dispute, so it reads by type and id only (C1 re-review, M1).
        $document = $model instanceof CdaDocumentVersion ? $model->cdaDocument : $model;

        if ($document instanceof CdaDocument && $document->isMediation()) {
            return $this->fallbackLabel();
        }

        // Prefer the most human-friendly attribute available on the related model.
        foreach (['full_name', 'name', 'application_number', 'loan_account_number', 'receipt_number', 'borrower_code', 'code', 'title', 'journal_no', 'reference', 'account_number', 'certificate_number'] as $attr) {
            if (isset($model->{$attr}) && $model->{$attr} !== null && $model->{$attr} !== '') {
                return (string) $model->{$attr};
            }
        }

        return $this->fallbackLabel();
    }

    /**
     * A statement line by its own date and amount ("Statement line, Sep 3,
     * 2026, ₱1,500.00"; money out reads -₱). It has no name or number, and
     * the class-and-id fallback only repeated the type and id the detail
     * already shows beneath the label.
     */
    private static function statementLineLabel(AccountingReconciliationLine $line): string
    {
        $centavos = (int) $line->amount;
        $amount = ($centavos < 0 ? '-' : '').'₱'.number_format(abs($centavos) / 100, 2);

        return "Statement line, {$line->date->format('M j, Y')}, {$amount}";
    }

    /**
     * A target with nothing to name it by: its kind in words and its id, e.g.
     * "Journal entry #557" or "Member #12".
     */
    private function fallbackLabel(): string
    {
        $type = $this->auditable_type ? self::typeInWords(class_basename($this->auditable_type)) : 'Record';

        return "{$type} #{$this->auditable_id}";
    }

    /**
     * A class name in words, the rule of the frontend's `auditTargetType()`:
     * `Borrower…` reads "Member…" and `CoMaker` reads "Co-maker", CamelCase is
     * split into words, and only the first word keeps its capitals ("GCash"
     * keeps them anywhere). The CDA models read "CDA document", "CDA officer".
     * A kind in self::TYPE_WORDS reads the screen's own words instead. The CSV
     * export reads the same.
     */
    public static function typeInWords(string $basename): string
    {
        if (isset(self::TYPE_WORDS[$basename])) {
            return self::TYPE_WORDS[$basename];
        }

        $named = str_replace('CoMaker', 'Co-maker', (string) preg_replace('/^Borrower/', 'Member', $basename));
        $words = explode(' ', (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $named));

        foreach ($words as $i => $word) {
            if ($i > 0 && $word !== 'GCash') {
                $words[$i] = strtolower($word);
            }
        }

        if ($words[0] === 'Cda' && count($words) > 1) {
            $words[0] = 'CDA';
        }

        return implode(' ', $words);
    }

    /**
     * The fields this row changed, for display.
     *
     * Only keys present in the new values are listed, and only when their
     * stored value differs from the old one (compared with associative keys
     * sorted, as a MySQL JSON column keeps its own key order). An `updated` row's old values hold
     * the WHOLE original row while its new values hold only the changed
     * columns, so comparing every key listed each untouched field as changed
     * to nothing. Timestamps are never listed, and a `created` row lists
     * nothing: its new values are the whole inserted row and the action
     * already says what happened. A row with no new values (a deletion) lists
     * its old fields, already redacted when written, with `new: null`. Values
     * read in plain words (self::shownFor()); when both sides read the same, in
     * detail ($this->detailed()), else as JSON, unless the same yes or no, moment
     * or date was only written two ways, which isn't a change (two moments in
     * one minute show their seconds). Display only;
     * old_values and new_values are returned as stored, less what the caller
     * may not read (self::valuesShownTo()).
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function buildChanges(mixed $oldValues, mixed $newValues): array
    {
        if ($this->action === 'created') {
            return [];
        }

        $old = is_array($oldValues) ? $oldValues : [];
        $new = is_array($newValues) ? $newValues : null;

        $changes = [];

        // A row with no new values (a deletion) lists what was removed.
        foreach (array_keys($new ?? $old) as $field) {
            $field = (string) $field;

            if (in_array($field, self::TIMESTAMP_KEYS, true) || in_array($field, self::TECHNICAL_KEYS, true)) {
                continue;
            }

            $oldValue = self::decoded($old[$field] ?? null);
            $newValue = self::decoded($new[$field] ?? null);

            if (self::canonical($oldValue) === self::canonical($newValue)) {
                continue;
            }

            // Each side read with its own row: an `updated` row's old values
            // hold the whole original row and its new values the columns that
            // changed, so the new row is the old one with the changes over it.
            $oldShown = $this->shownFor($field, $oldValue, $old);
            $newShown = $this->shownFor($field, $newValue, [...$old, ...($new ?? [])]);

            // Two different values can read the same in words (a fee's amount
            // changed inside a deductions list): show the detail, and JSON as a
            // last resort, so a real change is never hidden.
            if ($oldShown === $newShown && $this->isOneValueWrittenTwoWays($field, $oldValue, $newValue)) {
                continue;
            }

            // Two moments in one minute: the seconds tell them apart.
            if ($oldShown === $newShown && $this->timeOf($field, $oldValue) !== null && $this->timeOf($field, $newValue) !== null) {
                $oldShown = $this->timeOf($field, $oldValue)->format(self::TIMESTAMP_WITH_SECONDS);
                $newShown = $this->timeOf($field, $newValue)->format(self::TIMESTAMP_WITH_SECONDS);
            }

            if ($oldShown === $newShown) {
                $oldShown = $this->detailed($oldValue);
                $newShown = $this->detailed($newValue);
            }

            if ($oldShown === $newShown) {
                $oldShown = self::asJson($oldValue);
                $newShown = self::asJson($newValue);
            }

            $changes[] = ['field' => $field, 'old' => $oldShown, 'new' => $newShown];
        }

        return $changes;
    }

    /**
     * A value in plain words: a user, branch, role, product, loan, collateral
     * or approval step by name, a journal or journal line by the journal's
     * number, a fee's value by its row's type, a product's
     * rate as a percent, a boolean as Yes or No, a
     * timestamp in Manila time, a date as its date, money in pesos and an enum
     * key in words. Anything else, a status and a permission included, as
     * stored.
     *
     * @param  array<string, mixed>|null  $row  the values this side belongs to, when known
     */
    private function shownFor(string $field, mixed $value, ?array $row = null): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->namedBy($field, $value)
            ?? $this->feeValueInWords($field, $value, $row)
            ?? $this->productRateInWords($field, $value)
            ?? self::booleanInWords($field, $value, $this->castOf($field))
            ?? $this->timeInWords($field, $value)
            ?? $this->pesosFor($field, $value)
            ?? self::enumInWords($field, $value)
            ?? self::shown($value);
    }

    private function namedBy(string $field, mixed $value): ?string
    {
        $isUser = in_array($field, self::USER_FOREIGN_KEYS, true) && self::isId($value);
        $isBranch = in_array($field, self::BRANCH_KEYS, true);
        $isRole = $field === 'role' && is_string($value);
        $isProduct = in_array($field, self::PRODUCT_KEYS, true);
        $isStep = in_array($field, self::STEP_KEYS, true)
            && (is_string($value) || (is_array($value) && $value !== [] && array_all($value, fn (mixed $item): bool => is_string($item))));
        $isLoan = preg_match(self::LOAN_KEY, $field) === 1;
        $isCollateral = in_array($field, self::COLLATERAL_KEYS, true);
        $isJournal = in_array($field, self::JOURNAL_KEYS, true);
        $isJournalLine = in_array($field, self::JOURNAL_LINE_KEYS, true);
        $savingsKind = $this->savingsKindOf($field);

        if (! $isUser && ! $isBranch && ! $isRole && ! $isProduct && ! $isStep && ! $isLoan && ! $isCollateral && ! $isJournal && ! $isJournalLine && $savingsKind === null) {
            return null;
        }

        if (! $this->relationLoaded('changeUsers')) {
            self::loadChangeNames([$this->resource]);
        }

        if ($savingsKind !== null) {
            $names = $this->getRelation('changeSavings')->get($savingsKind, collect());
            $kind = self::SAVINGS_ID_KINDS[$savingsKind];

            if (self::isId($value)) {
                return $names->get((int) $value) ?? "{$kind} #{$value}";
            }

            return null;
        }

        if ($isStep) {
            return implode(', ', array_map($this->stepName(...), (array) $value));
        }

        if ($isLoan) {
            return $this->idsByName($value, 'changeLoans', 'Loan');
        }

        if ($isCollateral) {
            return $this->idsByName($value, 'changeCollaterals', 'Collateral');
        }

        if ($isJournal) {
            return $this->idsByName($value, 'changeJournals', 'Journal');
        }

        if ($isJournalLine) {
            return $this->idsByName($value, 'changeJournalLines', 'Journal line');
        }

        if ($isProduct) {
            return $this->idsByName($value, 'changeProducts', 'Product');
        }

        if ($isUser) {
            return $this->getRelation('changeUsers')->get((int) $value)?->full_name ?? "User #{$value}";
        }

        if ($isRole) {
            return ($this->getRelation('changeRoles')->get($value) ?? new Role(['name' => $value]))->auditName();
        }

        return $this->idsByName($value, 'changeBranches', 'Branch');
    }

    /**
     * The kind of savings record a savings row's field names, or null.
     */
    private function savingsKindOf(string $field): ?string
    {
        if (! self::isSavingsRow($this->auditable_type)) {
            return null;
        }

        foreach (self::SAVINGS_ID_KEYS as $kind => $fields) {
            if (in_array($field, $fields, true)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * An id, or a list of them, by the names in `$relation`: "Branch #5" for
     * one that is gone. Null for anything else.
     */
    private function idsByName(mixed $value, string $relation, string $kind): ?string
    {
        $names = $this->getRelation($relation);

        if (self::isId($value)) {
            return $names->get((int) $value) ?? "{$kind} #{$value}";
        }

        if (! is_array($value) || $value === [] || ! array_all($value, self::isId(...))) {
            return null;
        }

        return implode(', ', array_map(fn ($id) => $names->get((int) $id) ?? "{$kind} #{$id}", $value));
    }

    /**
     * An approval step id by the name the loan's own chain gave it, else as a
     * role when a role has that key, else in words.
     */
    private function stepName(string $stepId): string
    {
        $name = $this->auditable_type === Loan::class
            ? $this->getRelation('changeSteps')->get("{$this->auditable_id}:{$stepId}")
            : null;

        if (filled($name)) {
            return (string) $name;
        }

        return $this->getRelation('changeRoles')->get($stepId)?->auditName() ?? Str::headline($stepId);
    }

    /**
     * A fee's `value` read by the `type` of the row it belongs to: a percentage
     * or a formula's rate as a percent, a fixed fee in pesos, and a formula
     * written out as "Formula: <the formula>" (its stored 0 is never shown as
     * "0%"). Null (so it reads as stored) when that row's type isn't known.
     * `formula_expression` itself reads as the text.
     *
     * @param  array<string, mixed>|null  $row
     */
    private function feeValueInWords(string $field, mixed $value, ?array $row): ?string
    {
        if ($this->auditable_type !== Fee::class) {
            return null;
        }

        if ($field === 'formula_expression') {
            return is_string($value) ? $value : null;
        }

        if ($field !== 'value' || ! is_numeric($value)) {
            return null;
        }

        $expression = $row['formula_expression'] ?? null;

        if (($row['type'] ?? null) === 'formula' && is_string($expression) && trim($expression) !== '') {
            return 'Formula: '.$expression;
        }

        return match ($row['type'] ?? null) {
            'percentage', 'formula' => self::plainNumber((string) $value).'%',
            'fixed' => '₱'.number_format((float) $value, 2),
            default => null,
        };
    }

    /**
     * A loan product's or a savings rate as a percent, as written without trailing zeros
     * ("0.0000" reads "0%", "1.5" reads "1.5%").
     */
    private function productRateInWords(string $field, mixed $value): ?string
    {
        $savingsPercent = in_array($field, self::SAVINGS_PERCENT_FIELDS[class_basename((string) $this->auditable_type)] ?? [], true);
        $productPercent = $this->auditable_type === LoanProduct::class && in_array($field, self::PRODUCT_PERCENT_FIELDS, true);

        if (! ($savingsPercent || $productPercent) || ! is_numeric($value)) {
            return null;
        }

        return self::plainNumber((string) $value).'%';
    }

    /**
     * A rate as written, without trailing zeros: "1.2500" reads "1.25". Never
     * rounded.
     */
    private static function plainNumber(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    /**
     * Whether a field that reads the same on both sides is one value written
     * two ways, which isn't a change: `true` and `1`, a date in two forms, or a
     * UTC and a Manila timestamp of the same instant, to the second.
     */
    private function isOneValueWrittenTwoWays(string $field, mixed $old, mixed $new): bool
    {
        $cast = $this->castOf($field);

        if (self::booleanInWords($field, $old, $cast) !== null && self::booleanInWords($field, $new, $cast) !== null) {
            return true;
        }

        $oldTime = $this->timeOf($field, $old);
        $newTime = $this->timeOf($field, $new);

        if ($oldTime === null || $newTime === null) {
            return false;
        }

        return $this->isDateColumn($field) || $oldTime->getTimestamp() === $newTime->getTimestamp();
    }

    /**
     * The cast the row's target class declares for this field, or null.
     */
    private function castOf(string $field): ?string
    {
        $cast = self::castsOf($this->auditable_type)[$field] ?? null;

        return is_string($cast) ? $cast : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function castsOf(?string $type): array
    {
        static $casts = [];

        if ($type === null) {
            return [];
        }

        return $casts[$type] ??= is_subclass_of($type, Model::class) ? (new $type)->getCasts() : [];
    }

    private static function booleanInWords(string $field, mixed $value, ?string $cast): ?string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $isBooleanField = $cast === 'boolean' || $field === 'auto_credit' || preg_match('/^(is|has)_/', $field) === 1;

        if (! $isBooleanField || ! in_array($value, [0, 1, '0', '1'], true)) {
            return null;
        }

        return (int) $value === 1 ? 'Yes' : 'No';
    }

    /**
     * A timestamp in Manila time, a date column as its Manila date with no
     * time. A value that isn't a date reads as stored.
     */
    private function timeInWords(string $field, mixed $value): ?string
    {
        return $this->timeOf($field, $value)?->format($this->isDateColumn($field) ? 'M j, Y' : 'M j, Y, g:i A');
    }

    /**
     * A time field's value in Manila time, whichever way it was stored: JSON
     * writes a cast model's value in UTC, a raw attribute has the app
     * timezone's wall clock. Null for any other field or a value that isn't a
     * date.
     */
    private function timeOf(string $field, mixed $value): ?CarbonImmutable
    {
        $cast = (string) $this->castOf($field);
        $isTimestamp = str_contains($cast, 'datetime') || str_ends_with($field, '_at');

        if ((! $this->isDateColumn($field) && ! $isTimestamp) || ! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone'))->setTimezone('Asia/Manila');
        } catch (Throwable) {
            return null;
        }
    }

    private function isDateColumn(string $field): bool
    {
        $cast = (string) $this->castOf($field);

        return str_starts_with($cast, 'date') && ! str_starts_with($cast, 'datetime');
    }

    /**
     * Money in pesos with two decimals, in whichever form it was stored
     * (`1500`, `"1500.0"`, `"1,500.00"`), a column stored in centavos converted.
     * A rate, a count, a code or a value that isn't a number reads as stored.
     */
    private function pesosFor(string $field, mixed $value): ?string
    {
        $plain = is_string($value) ? str_replace([',', '₱', ' '], '', $value) : $value;

        if (is_bool($plain) || ! is_numeric($plain)) {
            return null;
        }

        $inCentavos = str_ends_with($field, '_centavos')
            || in_array($field, self::CENTAVO_FIELDS[class_basename((string) $this->auditable_type)] ?? [], true);

        if ($inCentavos) {
            $amount = (float) $plain / 100;
        } elseif ($this->isPesoField($field)) {
            $amount = (float) $plain;
        } else {
            return null;
        }

        return ($amount < 0 ? '-' : '').'₱'.number_format(abs($amount), 2);
    }

    /**
     * A column the target casts to two decimals is money, unless named as a
     * rate. Otherwise (a service's own key, a row with no target) the name says.
     */
    private function isPesoField(string $field): bool
    {
        if (preg_match(self::NOT_MONEY_FIELD, $field) === 1) {
            return false;
        }

        return $this->castOf($field) === 'decimal:2' || preg_match(self::MONEY_FIELD, $field) === 1;
    }

    /**
     * An enum key, or a list of them, in words: "semi_monthly" reads
     * "Semi-Monthly". A value that isn't written as a key reads as stored.
     */
    private static function enumInWords(string $field, mixed $value): ?string
    {
        if (! in_array($field, self::ENUM_FIELDS, true)) {
            return null;
        }

        if (is_string($value)) {
            return self::enumKeyInWords($value);
        }

        if (! is_array($value) || $value === [] || ! array_all($value, fn ($item) => is_string($item) && self::enumKeyInWords($item) !== null)) {
            return null;
        }

        return implode(', ', array_map(self::enumKeyInWords(...), $value));
    }

    private static function enumKeyInWords(string $key): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            return null;
        }

        return self::ENUM_WORDS[$key] ?? Str::headline($key);
    }

    private static function shown(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return self::arrayInWords($value);
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /**
     * An array value in words: a list of scalars joined with ", ", a list of
     * records by each one's `name` (else `label`, else `title`, else its
     * `period_number` as "Period 3"), any other
     * list as "N entries", an associative array as "N fields", and an empty
     * one as "none".
     *
     * @param  array<mixed>  $value
     */
    private static function arrayInWords(array $value): string
    {
        if ($value === []) {
            return 'none';
        }

        $count = count($value);

        if (! array_is_list($value)) {
            return $count === 1 ? '1 field' : "{$count} fields";
        }

        if (array_all($value, fn (mixed $item): bool => is_scalar($item))) {
            return implode(', ', array_map(fn (mixed $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, $value));
        }

        $names = array_map(fn (mixed $item): ?string => self::itemName($item), $value);

        if (! in_array(null, $names, true)) {
            return implode(', ', $names);
        }

        return $count === 1 ? '1 entry' : "{$count} entries";
    }

    /**
     * A string holding a JSON array or object, decoded; anything else as it is.
     *
     * An Auditable `updated` row takes its old values from getOriginal(), which
     * casts an array field to an array, and its new values from getChanges(),
     * which holds the raw JSON string. Decoding lets both sides compare and
     * read the same way.
     */
    private static function decoded(mixed $value): mixed
    {
        if (! is_string($value) || ! json_validate($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * A value with every associative array's keys sorted and every number in
     * one form, at every depth, so two values compare equal exactly when they
     * hold the same content: `5` and `"5"`, or `1500` and `"1500.0"`, are not
     * a change. Only an int, a float, or a string already written as a plain
     * number counts as a number; any other string (a mobile number's leading
     * zero, a padded code, a long reference) is compared exactly as stored.
     */
    private static function canonical(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)
            || (is_string($value) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d+)?$/', $value) === 1)) {
            return (string) ($value + 0);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * A value in more detail than self::shown(), for two values that read the
     * same in words: a list of records as each one's name and its peso figure
     * (self::pesosOf()), e.g. "Processing Fee
     * ₱2,000.00, Notarial Fee ₱100.00"; an associative array as "key: value"
     * pairs, keys in words and sorted (a MySQL JSON column keeps its own key
     * order), joined with "; ". Anything else as self::shown().
     */
    private function detailed(mixed $value): ?string
    {
        if (! is_array($value) || $value === []) {
            return self::shown($value);
        }

        if (! array_is_list($value)) {
            $pairs = [];
            ksort($value);

            foreach ($value as $key => $item) {
                $pairs[] = str_replace('_', ' ', (string) $key).': '.(is_array($item) ? self::arrayInWords($item) : ($this->shownFor((string) $key, $item) ?? 'none'));
            }

            return implode('; ', $pairs);
        }

        if (array_all($value, fn (mixed $item): bool => is_array($item) && self::isPeriod($item))) {
            return implode('; ', array_map($this->periodInDetail(...), $value));
        }

        $items = [];

        foreach ($value as $item) {
            $name = self::itemName($item);

            if ($name === null) {
                return self::shown($value);
            }

            $pesos = self::pesosOf($item);
            $items[] = $pesos === null ? $name : $name.' ₱'.number_format((float) $pesos, 2);
        }

        return implode(', ', $items);
    }

    /**
     * A period record with its peso figures, keys in words and sorted (a MySQL
     * JSON column keeps its own key order), e.g. "Period 2 (penalty amount
     * ₱0.00, waived ₱59.44)". Its ids and numbers are left out.
     *
     * @param  array<mixed>  $period
     */
    private function periodInDetail(array $period): string
    {
        ksort($period);
        $figures = [];

        foreach ($period as $key => $item) {
            $pesos = is_scalar($item) ? $this->pesosFor((string) $key, $item) : null;

            if ($pesos !== null) {
                $figures[] = str_replace('_', ' ', (string) $key).' '.$pesos;
            }
        }

        $name = "Period {$period['period_number']}";

        return $figures === [] ? $name : $name.' ('.implode(', ', $figures).')';
    }

    /**
     * A record's peso figure: its numeric `amount`, or its numeric `value`
     * when its `type` is `fixed`. A record typed as a percentage has one only
     * when it carries `original_value`: a saved loan deduction keeps the rate
     * there and the computed pesos in `amount` (LoanService::deductionItems()),
     * while a stated rate without it keeps the rate in `amount`, which must
     * never read as pesos.
     *
     * @param  array<mixed>  $item
     */
    private static function pesosOf(array $item): int|float|string|null
    {
        $type = is_string($item['type'] ?? null) ? strtolower($item['type']) : null;

        if ($type !== null && str_contains($type, 'percent') && ! array_key_exists('original_value', $item)) {
            return null;
        }

        if (is_numeric($item['amount'] ?? null)) {
            return $item['amount'];
        }

        return $type === 'fixed' && is_numeric($item['value'] ?? null) ? $item['value'] : null;
    }

    private static function asJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private static function itemName(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        foreach (['name', 'label', 'title'] as $key) {
            if (isset($item[$key]) && is_scalar($item[$key]) && (string) $item[$key] !== '') {
                return (string) $item[$key];
            }
        }

        return self::isPeriod($item) ? "Period {$item['period_number']}" : null;
    }

    /**
     * A schedule period's record (a waiver's or an extension's `periods`):
     * one with a period number and no name.
     *
     * @param  array<mixed>  $item
     */
    private static function isPeriod(array $item): bool
    {
        return self::isId($item['period_number'] ?? null) && ! isset($item['name']);
    }
}
