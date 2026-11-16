<?php

namespace App\Services\Linda;

use App\Models\User;

/**
 * The pages a Linda answer may link to: a fixed map, each with the
 * permissions the page itself needs, so a link the user can't open is left
 * out. Labels use the names the menu and the report catalog show
 * (lendyph-frontend src/lib/page-titles.ts).
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
     * The report an intent Linda can't answer yet is about, when there is one.
     *
     * @var array<string, string>
     */
    private const NOT_YET_PAGES = [
        'income_summary' => 'income_report',
        'collection_efficiency' => 'collection_efficiency',
        'portfolio_summary' => 'portfolio_summary',
        'branch_performance' => 'performance',
        'officer_performance' => 'performance',
        'collection_details' => 'repayments_list',
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
