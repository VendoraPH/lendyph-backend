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
    ];

    /**
     * The reports whose page reads `?from=&to=`: the dated reports. The
     * Portfolio Summary is as of today and has no date controls.
     */
    private const DATED_REPORTS = [
        'daily_collection', 'disbursement_report', 'income_report', 'collection_efficiency', 'performance', 'repayments_list',
    ];

    /** The reports whose page reads `?branch=`. */
    private const BRANCH_REPORTS = [
        'daily_collection', 'disbursement_report', 'portfolio_summary', 'income_report', 'collection_efficiency', 'performance', 'repayments_list',
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

            if ($entry !== null && self::canOpen($user, $entry['needs'])) {
                $links[] = ['label' => $entry['label'], 'url' => $entry['url']];
            }
        }

        return $links;
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

            if ($entry === null || ! self::canOpen($user, $entry['needs'])) {
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
