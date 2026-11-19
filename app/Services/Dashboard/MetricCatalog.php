<?php

namespace App\Services\Dashboard;

/**
 * The executive dashboard's metric layer (spec §15): each figure's
 * definition, formula, unit, date rule, source report, the filters it
 * honours, the permissions it needs, and whether it has history (snapshot
 * lines, EB-B). Tooltips and exports read it; no figure is worked out here.
 *
 * self::REPORTS says, per report endpoint (`/api/reports/<key>`), which
 * filters every figure follows (`full`) and which only some figures follow
 * (`partial`, filter => the response paths it changes, `*` for each row of a
 * list, a block's path covering everything in it), for the response's
 * `filters_applied` and `filters_applied_to` (LoanScope::applied()). Every
 * total built from a narrowed figure is listed with it. A report not listed
 * honours the branch filter only.
 */
final class MetricCatalog
{
    private const LOAN_FILTERS = ['branch_id', 'product_id', 'officer_id'];

    /**
     * The books' figures come from the accounting dashboard's sources (the
     * income statement), `/api/accounting/dashboard`, not a lending report.
     */
    public const BOOKS_SOURCE = 'accounting/dashboard';

    /**
     * Sources outside `/api/reports`, as self::REPORTS describes a report: the
     * books follow the branch filter only (by journal branch).
     *
     * @var array<string, array{full: list<string>, partial: array<string, list<string>>}>
     */
    public const OTHER_SOURCES = [
        self::BOOKS_SOURCE => ['full' => ['branch_id'], 'partial' => []],
    ];

    /**
     * Cash Flow's repayment cash and every total built from it, as response
     * paths (`*` is each `by_branch` row): what the collector filter narrows,
     * and part of what product and officer narrow. Share capital
     * (`share_capital`, `inflows.share_capital_credit`, a row's
     * `inflow_share_capital`) has no loan and stays whole, so a total that
     * adds it is part filtered.
     */
    private const CASH_FLOW_RECEIPT_FIGURES = [
        'inflows.repayments',
        'inflows.total',
        'net_movement',
        'by_branch.*.inflow_principal',
        'by_branch.*.inflow_interest',
        'by_branch.*.inflow_penalty',
        'by_branch.*.inflow_insurance',
        'by_branch.*.inflow_overpayment',
        'by_branch.*.inflow_repayments',
        'by_branch.*.inflow_total',
        'by_branch.*.repayment_count',
        'by_branch.*.net_movement',
        'by_branch_totals.inflow_repayments',
        'by_branch_totals.inflow_total',
        'by_branch_totals.net_movement',
    ];

    /** Cash Flow's release figures and the totals built from them: the rest of what product and officer narrow. */
    private const CASH_FLOW_RELEASE_FIGURES = [
        'outflows.releases',
        'outflows.total',
        'non_cash.principal_released',
        'non_cash.total_deductions',
        'by_branch.*.outflow_net_proceeds',
        'by_branch.*.outflow_total',
        'by_branch.*.release_count',
        'by_branch.*.total_deductions',
        'by_branch.*.principal_released',
        'by_branch_totals.outflow_net_proceeds',
        'by_branch_totals.principal_released',
        'by_branch_totals.outflow_total',
    ];

    /** Cash Flow's loan cash: every figure the product and officer filters narrow. */
    private const CASH_FLOW_LOAN_FIGURES = [...self::CASH_FLOW_RECEIPT_FIGURES, ...self::CASH_FLOW_RELEASE_FIGURES];

    /**
     * Income by Loan's figures built from receipts: which loans are listed
     * (`data`, a loan with only receipts by someone else drops out), their
     * totals, and the page counts that follow the listed loans.
     */
    private const INCOME_BY_LOAN_RECEIPT_FIGURES = [
        'data',
        'totals.count',
        'totals.payments',
        'totals.interest_income',
        'totals.penalty_income',
        'totals.total_income',
        'meta.totals.count',
        'meta.totals.payments',
        'meta.totals.interest_income',
        'meta.totals.penalty_income',
        'meta.totals.total_income',
        'meta.total',
        'meta.to',
        'meta.last_page',
    ];

    /**
     * @var array<string, array{full: list<string>, partial: array<string, list<string>>}>
     */
    public const REPORTS = [
        'loan-balance-summary' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'portfolio-by-product' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'performance' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'collection-efficiency' => [
            'full' => self::LOAN_FILTERS,
            'partial' => ['collector_id' => ['total_received', 'penalty_collected', 'payment_count']],
        ],
        'cash-flow' => [
            'full' => ['branch_id'],
            'partial' => [
                'product_id' => self::CASH_FLOW_LOAN_FIGURES,
                'officer_id' => self::CASH_FLOW_LOAN_FIGURES,
                'collector_id' => self::CASH_FLOW_RECEIPT_FIGURES,
            ],
        ],
        'income' => [
            'full' => self::LOAN_FILTERS,
            'partial' => ['collector_id' => ['interest_income', 'penalty_income', 'total']],
        ],
        'income/by-loan' => [
            'full' => self::LOAN_FILTERS,
            'partial' => ['collector_id' => self::INCOME_BY_LOAN_RECEIPT_FIGURES],
        ],
        'daily-collection' => ['full' => ['branch_id'], 'partial' => []],
        'disbursements' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'releases' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'borrowers/released' => ['full' => self::LOAN_FILTERS, 'partial' => []],
        'repayments' => ['full' => [...self::LOAN_FILTERS, 'collector_id'], 'partial' => []],
        'aging' => ['full' => ['branch_id'], 'partial' => []],
        'provisioning' => ['full' => ['branch_id'], 'partial' => []],
        'share-capital' => ['full' => ['branch_id'], 'partial' => []],
        'borrowers' => ['full' => ['branch_id'], 'partial' => []],
        'due-past-due' => ['full' => ['branch_id'], 'partial' => []],
    ];

    /**
     * What a report did with the filters `$filters` carry.
     *
     * @param  array<string, mixed>  $filters  validated report filters
     * @return array{filters_applied: list<string>, filters_applied_to: array<string, list<string>>}
     */
    public static function filtersApplied(string $report, array $filters): array
    {
        $honours = self::REPORTS[$report] ?? ['full' => ['branch_id'], 'partial' => []];

        return LoanScope::fromFilters($filters)->applied($honours['full'], $honours['partial']);
    }

    /**
     * Every metric, keyed by its key.
     *
     * @return array<string, array{key: string, label: string, definition: string, formula: string, unit: 'money'|'percent'|'count', date_rule: 'as_of_today'|'period', source: string, filters: list<string>, permissions: list<string>, has_history: bool}>
     */
    public static function all(): array
    {
        $metrics = [];

        foreach (self::definitions() as $key => $metric) {
            $metrics[$key] = [
                'key' => $key,
                'label' => $metric['label'],
                'definition' => $metric['definition'],
                'formula' => $metric['formula'],
                'unit' => $metric['unit'],
                'date_rule' => $metric['date_rule'],
                'source' => $metric['source'],
                'filters' => $metric['filters'],
                'permissions' => $metric['permissions'],
                'has_history' => $metric['has_history'],
            ];
        }

        return $metrics;
    }

    /**
     * @return array<string, array{label: string, definition: string, formula: string, unit: 'money'|'percent'|'count', date_rule: 'as_of_today'|'period', source: string, filters: list<string>, permissions: list<string>, has_history: bool}>
     */
    private static function definitions(): array
    {
        $reports = ['reports:view'];
        $balance = fn (string $label, string $definition, string $formula, string $unit = 'money', bool $history = true): array => [
            'label' => $label,
            'definition' => $definition,
            'formula' => $formula,
            'unit' => $unit,
            'date_rule' => 'as_of_today',
            'source' => 'loan-balance-summary',
            'filters' => self::LOAN_FILTERS,
            'permissions' => $reports,
            'has_history' => $history,
        ];
        $late = fn (int $days): string => $days === 1 ? '1 day or more' : "more than {$days} days";
        // PAR's denominator is the Portfolio Summary's par_portfolio_principal (loan-balance-summary),
        // always equal to its outstanding.principal. The wording below stays in words for users.
        $par = fn (int $days): array => $balance(
            "Portfolio at Risk {$days}",
            "Over every loan ever released in the scope (released, ongoing, completed, defaulted or restructured): the unpaid principal of the loans whose oldest period still marked unpaid is {$late($days)} past its due date, as a share of the unpaid principal of every loan ever released in the scope. It equals the gross loan portfolio unless a completed or restructured loan still has a period marked unpaid. The whole unpaid principal of each late loan counts, not just the late period. Grace is not applied. A period marked unpaid counts as the oldest even when nothing is left owing on it.",
            "Unpaid principal of loans {$late($days)} past due ÷ unpaid principal of every ever-released loan in the scope × 100",
            'percent',
        );

        return [
            'gross_loan_portfolio' => $balance(
                'Gross Loan Portfolio',
                'The principal still unpaid on released, ongoing and defaulted loans, as of today. Interest, penalty and the insurance premium owed are not in it.',
                'Sum of each unpaid period\'s principal still owed, on collectible loans',
            ),
            'total_outstanding' => $balance(
                'Outstanding',
                'Everything still owed on released, ongoing and defaulted loans, as of today: principal, interest and penalty still unpaid, plus the insurance premium owed.',
                'Unpaid principal + unpaid interest + unpaid penalty + insurance premium owed',
            ),
            'active_loans' => $balance(
                'Active Loans',
                'Loans released or ongoing, as of today. Defaulted loans are counted apart.',
                'Count of loans whose status is released or ongoing',
                'count',
                false,
            ),
            'past_due_amount' => $balance(
                'Past Due Amount',
                'What the collectible loans\' periods past their due date and the loan\'s grace still owe (principal, interest and penalty), as of today. Release dates never narrow it, so without a product or officer filter it is the Past Due Loans page\'s figure.',
                'Sum of the unpaid principal, interest and penalty on periods past due date + grace',
            ),
            'par_1' => $par(1),
            'par_30' => $par(30),
            'par_60' => $par(60),
            'par_90' => $par(90),
            'aging_by_loan' => $balance(
                'Loan Aging',
                'Each collectible loan (released, ongoing or defaulted) in one bucket by its oldest period with something left owing (current, 1–30, 31–60, 61–90, 91–120 and over 120 days past its due date), with the loans and their unpaid principal in each and each bucket\'s share of the gross loan portfolio. Unlike PAR, a period marked unpaid with nothing left owing is passed over, and completed and restructured loans are not aged.',
                'Bucket principal ÷ gross loan portfolio × 100',
                'money',
                false,
            ),
            'product_share' => [
                'label' => 'Portfolio by Loan Product',
                'definition' => 'Each loan product\'s share of the gross loan portfolio, as of today. The four largest are named and the rest form one Other row.',
                'formula' => 'Product unpaid principal ÷ gross loan portfolio × 100',
                'unit' => 'percent',
                'date_rule' => 'as_of_today',
                'source' => 'portfolio-by-product',
                'filters' => self::LOAN_FILTERS,
                'permissions' => $reports,
                'has_history' => false,
            ],
            'active_members' => [
                'label' => 'Active Members',
                'definition' => 'Members with at least one collectible loan, each member counted once.',
                'formula' => 'Count of distinct members with a released, ongoing or defaulted loan',
                'unit' => 'count',
                'date_rule' => 'as_of_today',
                'source' => 'borrowers',
                'filters' => ['branch_id'],
                'permissions' => $reports,
                'has_history' => false,
            ],
            'loans_released' => [
                'label' => 'Loans Released',
                'definition' => 'The principal of the loans released in the period, restructures included. Deductions withheld at release are part of it; it is not the cash paid out.',
                'formula' => 'Sum of principal of loans released in the period',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => 'cash-flow',
                'filters' => self::LOAN_FILTERS,
                'permissions' => $reports,
                'has_history' => false,
            ],
            'collections' => [
                'label' => 'Collections',
                'definition' => 'Posted loan payments dated in the period: principal, interest, penalty, build-up and overpayment. Voided payments are left out. The insurance premium collected is held for the insurer and counted apart.',
                'formula' => 'Sum of posted payments in the period − insurance premium collected',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => 'cash-flow',
                'filters' => [...self::LOAN_FILTERS, 'collector_id'],
                'permissions' => $reports,
                'has_history' => false,
            ],
            'collected_insurance' => [
                'label' => 'Collected Insurance',
                'definition' => 'The insurance premium owed that posted loan payments in the period collected first. It is held for the insurer and is never income.',
                'formula' => 'Sum of the insurance premium applied by posted payments in the period',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => 'cash-flow',
                'filters' => [...self::LOAN_FILTERS, 'collector_id'],
                'permissions' => $reports,
                'has_history' => false,
            ],
            'collection_efficiency' => [
                'label' => 'Collection Efficiency',
                'definition' => 'The principal and interest paid on the periods that fell due in the period, each capped at what the period owes, as a share of what fell due. Penalty never counts, so it never exceeds 100%.',
                'formula' => 'Principal and interest collected on periods due ÷ amount due × 100',
                'unit' => 'percent',
                'date_rule' => 'period',
                'source' => 'collection-efficiency',
                'filters' => self::LOAN_FILTERS,
                'permissions' => $reports,
                'has_history' => false,
            ],
            'required_allowance' => [
                'label' => 'Required Allowance',
                'definition' => 'The allowance for probable loan losses the provisioning rates require on the past-due buckets, as of today.',
                'formula' => 'Sum over aging buckets of bucket amount × provisioning rate',
                'unit' => 'money',
                'date_rule' => 'as_of_today',
                'source' => 'provisioning',
                'filters' => ['branch_id'],
                'permissions' => $reports,
                'has_history' => false,
            ],
            'share_capital' => [
                'label' => 'Share Capital',
                'definition' => 'The members\' share capital at the end of the period, from the share capital ledger. It is the members\' equity, never revenue.',
                'formula' => 'Opening balance + credits − debits in the period',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => 'share-capital',
                'filters' => ['branch_id'],
                'permissions' => ['reports:view', 'share_capital:view'],
                'has_history' => false,
            ],
            'interest_income' => [
                'label' => 'Interest income (cash basis)',
                'definition' => 'The interest posted to the books in the period, on the account set for interest income, by journal branch. The books are kept on a cash basis, so this is interest received; it differs from the Income report\'s collected interest by payments that posted no journal and by deferred interest recognised later. It is never added to collected interest.',
                'formula' => 'Credits less debits on the interest income account in the period',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => self::BOOKS_SOURCE,
                'filters' => ['branch_id'],
                'permissions' => ['accounting:view'],
                'has_history' => false,
            ],
            'net_income' => [
                'label' => 'Net Income',
                'definition' => 'Income less expenses posted to the books in the period, by journal branch, the income statement\'s own figure; year-end closing entries are left out. It reads Net Surplus on the cooperative chart of accounts.',
                'formula' => 'Total income − total expenses on the income statement for the period',
                'unit' => 'money',
                'date_rule' => 'period',
                'source' => self::BOOKS_SOURCE,
                'filters' => ['branch_id'],
                'permissions' => ['accounting:view'],
                'has_history' => false,
            ],
        ];
    }
}
