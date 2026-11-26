<?php

namespace App\Services\Linda;

use App\Models\User;

/**
 * The pages a Linda answer may link to: a fixed map, each with the
 * permissions the page itself needs, so a link the user can't open is left
 * out. Labels use the names the menu and the report catalog show
 * (lendyph-frontend src/lib/page-titles.ts).
 *
 * A report link opens on the answer's own period and branch
 * (`?from=YYYY-MM-DD&to=YYYY-MM-DD&branch=<id>`, forPeriod()): the period for
 * a report with date controls, the branch only when the question named one.
 * The report page checks both again and ignores what it can't use.
 */
final class LindaLinks
{
    /** @var array<string, array{label: string, url: string, needs: list<string>}> */
    private const PAGES = [
        'daily_collection' => ['label' => 'Open Daily Collection Report', 'url' => '/reports/daily_collection', 'needs' => ['reports:view']],
        'disbursement_report' => ['label' => 'Open Disbursement Summary', 'url' => '/reports/disbursement_report', 'needs' => ['reports:view']],
        'portfolio_summary' => ['label' => 'Open Portfolio Summary', 'url' => '/reports/portfolio_summary', 'needs' => ['reports:view']],
        'past_due_loans' => ['label' => 'Open Past Due Loans', 'url' => '/loans/past-due', 'needs' => ['loans:view', 'reports:view']],
        'active_loans' => ['label' => 'Open Active Loans', 'url' => '/loans?tab=active', 'needs' => ['loans:view']],
        'income_report' => ['label' => 'Open Income Report', 'url' => '/reports/income_report', 'needs' => ['reports:view']],
        'collection_efficiency' => ['label' => 'Open Collection Efficiency', 'url' => '/reports/collection_efficiency', 'needs' => ['reports:view']],
        'performance' => ['label' => 'Open Officer / Branch Performance', 'url' => '/reports/performance', 'needs' => ['reports:view']],
        'repayments_list' => ['label' => 'Open Repayments List', 'url' => '/reports/repayments_list', 'needs' => ['reports:view']],
        'members' => ['label' => 'Open Members', 'url' => '/borrowers', 'needs' => ['borrowers:view']],
        'expenses' => ['label' => 'Open Expenses & Payables', 'url' => '/accounting/expenses', 'needs' => ['accounting:view', 'expenses:view']],
        'share_capital' => ['label' => 'Open Share Capital', 'url' => '/share-capital', 'needs' => ['share_capital:view']],
        'savings_dashboard' => ['label' => 'Open Savings Dashboard', 'url' => '/savings/dashboard', 'needs' => ['savings:view']],
        'cda_dashboard' => ['label' => 'Open CDA Dashboard', 'url' => '/cda', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_calendar' => ['label' => 'Open Compliance Calendar', 'url' => '/cda/calendar', 'needs' => ['cda:view', 'branches:view_all']],
        'member_report' => ['label' => 'Open Member Report', 'url' => '/reports/borrower_report', 'needs' => ['reports:view']],
        // Round 4: the reminders page, and each CDA report's page (a restricted
        // report's needs its view key), Officers & Trainings and Compliance Documents.
        'reminders' => ['label' => 'Open Reminders', 'url' => '/loans/reminders', 'needs' => ['loans:view', 'reminders:view']],
        'cda_capr' => ['label' => 'Open CAPR', 'url' => '/cda/capr', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_financial_statements' => ['label' => 'Open Financial Statements', 'url' => '/cda/financial-statements', 'needs' => ['cda:view', 'branches:view_all', 'cda:view_audit']],
        'cda_performance_audit' => ['label' => 'Open Performance Audit', 'url' => '/cda/performance-audit', 'needs' => ['cda:view', 'branches:view_all', 'cda:view_audit']],
        'cda_social_audit' => ['label' => 'Open Social Audit', 'url' => '/cda/social-audit', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_pesos' => ['label' => 'Open Enhanced PESOS', 'url' => '/cda/pesos', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_officers' => ['label' => 'Open Officers & Trainings', 'url' => '/cda/officers', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_mediation' => ['label' => 'Open Mediation & Conciliation', 'url' => '/cda/mediation', 'needs' => ['cda:view', 'branches:view_all', 'cda:view_mediation']],
        'cda_sworn_statement' => ['label' => 'Open Sworn Statement', 'url' => '/cda/sworn-statement', 'needs' => ['cda:view', 'branches:view_all']],
        'cda_tax_reports' => ['label' => 'Open ATIR & ABR', 'url' => '/cda/tax-reports', 'needs' => ['cda:view', 'branches:view_all', 'cda:view_tax']],
        'cda_documents' => ['label' => 'Open Compliance Documents', 'url' => '/cda/documents', 'needs' => ['cda:view', 'branches:view_all']],
        // Finish run round 2: the Credit Scoring dashboard.
        'credit_scoring' => ['label' => 'Open Credit Scoring Dashboard', 'url' => '/credit-scoring', 'needs' => ['credit_scoring:view']],
    ];

    /**
     * Each CDA report type's page in self::PAGES (ATIR and ABR share one).
     *
     * @var array<string, string>
     */
    public const CDA_REPORT_PAGES = [
        'capr' => 'cda_capr', 'financial_statements' => 'cda_financial_statements', 'performance_audit' => 'cda_performance_audit',
        'social_audit' => 'cda_social_audit', 'pesos' => 'cda_pesos', 'officers_trainings' => 'cda_officers', 'mediation' => 'cda_mediation',
        'sworn_statement' => 'cda_sworn_statement', 'atir' => 'cda_tax_reports', 'abr' => 'cda_tax_reports',
    ];

    /**
     * The reports whose page reads `?from=&to=`: the dated reports. The
     * Portfolio Summary is as of today and has no date controls.
     */
    private const DATED_REPORTS = [
        'daily_collection', 'disbursement_report', 'income_report', 'collection_efficiency', 'performance', 'repayments_list',
        'member_report',
    ];

    /** The reports whose page reads `?branch=`. */
    private const BRANCH_REPORTS = [
        'daily_collection', 'disbursement_report', 'portfolio_summary', 'income_report', 'collection_efficiency', 'performance', 'repayments_list',
        'member_report',
    ];

    /**
     * The report an intent Linda can't answer yet is about, when there is one.
     *
     * @var array<string, string>
     */
    private const NOT_YET_PAGES = [
        'income_summary' => 'income_report',
        'portfolio_summary' => 'portfolio_summary',
    ];

    /**
     * The links for $pages that $user may open, in the order named.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function for(User $user, string ...$pages): array
    {
        $links = [];

        foreach ($pages as $page) {
            $entry = self::PAGES[$page] ?? null;

            if ($entry !== null && self::canOpen($user, $entry['needs']) && ! self::hidden($entry['url'])) {
                $links[] = ['label' => $entry['label'], 'url' => $entry['url']];
            }
        }

        return $links;
    }

    /** Whether the page belongs to a module hidden in Menu Visibility, so no answer links it. */
    private static function hidden(string $url): bool
    {
        return app(LindaVisibility::class)->hidesPage($url);
    }

    /**
     * The links for $pages that $user may open, each report opening on
     * $period (a dated report) and $branchId (a named branch), in the order
     * named. Other pages keep their plain link.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function forPeriod(User $user, ?LindaPeriod $period, ?int $branchId, string ...$pages): array
    {
        $links = [];

        foreach ($pages as $page) {
            $entry = self::PAGES[$page] ?? null;

            if ($entry === null || ! self::canOpen($user, $entry['needs']) || self::hidden($entry['url'])) {
                continue;
            }

            $query = [
                ...($period !== null && in_array($page, self::DATED_REPORTS, true)
                    ? ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString()]
                    : []),
                ...($branchId !== null && in_array($page, self::BRANCH_REPORTS, true) ? ['branch' => $branchId] : []),
            ];

            $links[] = ['label' => $entry['label'], 'url' => $entry['url'].($query === [] ? '' : '?'.http_build_query($query))];
        }

        return $links;
    }

    /**
     * The links for $pages that $user may open, each opening on the CDA
     * reporting year $year (`?year=`, which the CDA pages read).
     *
     * @return list<array{label: string, url: string}>
     */
    public static function forYear(User $user, int $year, string ...$pages): array
    {
        return array_map(
            fn (array $link): array => ['label' => $link['label'], 'url' => $link['url'].'?'.http_build_query(['year' => $year])],
            self::for($user, ...$pages),
        );
    }

    /** @return list<array{label: string, url: string}> */
    public static function forNotYet(?User $user, LindaIntent $intent): array
    {
        $page = self::NOT_YET_PAGES[$intent->value] ?? null;

        return $user === null || $page === null ? [] : self::for($user, $page);
    }

    /** @param  list<string>  $permissions */
    private static function canOpen(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
