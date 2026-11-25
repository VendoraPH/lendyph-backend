<?php

namespace App\Services\MenuVisibility;

/**
 * The side menu's modules for Settings → Menu Visibility (finish run round 6),
 * and the API each one owns: the contract's keys, labels and parents, in the
 * side menu's order. Settings isn't a module: it is always on.
 *
 * A hidden module's own API answers 404 (App\Http\Middleware\BlockHiddenModules)
 * on staff, member portal and public routes alike. Its `blocks` entries are
 * matched against the matched route's URI, segment by segment, never as a
 * substring: `savings` covers `/savings` and everything under it, never
 * `/savingsx` or `/reports/savings`. A `*` stands for any one segment (a
 * route parameter), and a leading method (`PATCH loans/x/auto-pay`) limits the
 * entry to that method.
 *
 * What counts as a module's own API:
 * - the routes its menu pages and its Settings pages call;
 * - the routes its own parts on other screens call (a member's Savings tab, a
 *   loan's credit assessment or reminders card, a loan's Auto-Pay switch, a
 *   pledge's Auto-Credit switch, a member's portal access card, savings reports
 *   and documents): they are that module's functions, so they go with it, and
 *   the screens hide those parts with it.
 *
 * A route another visible menu needs for its own work stays open while the
 * module is hidden (`open`, method and full path, with the page that needs it).
 * That is also why the core menus (Members, Loans, Payments, Share Capital,
 * Collateral, Reports, Documents) block little or nothing: every screen reads
 * members, loans, payments, share capital balances and collaterals, and the
 * dashboards and printables read the reports. Background jobs are unchanged.
 *
 * Pure: no framework calls, so tests/Unit can read it without booting the app.
 */
final class MenuModules
{
    /**
     * First path segments never blocked, whatever is hidden: signing in and
     * the user's own details, health, notifications and Menu Visibility itself.
     */
    public const ALWAYS_OPEN = ['auth', 'health', 'notifications', 'menu-visibility'];

    /**
     * @var array<string, array{label: string, parent: string|null, blocks: list<string>, open?: array<string, string>}>
     */
    private const MODULES = [
        'dashboard' => [
            'label' => 'Dashboard',
            'parent' => null,
            'blocks' => ['dashboard/stats', 'dashboard/collections-trend', 'dashboard/daily-dues', 'dashboard/recent-transactions'],
        ],
        'executive_dashboard' => [
            'label' => 'Executive Dashboard',
            'parent' => null,
            'blocks' => ['dashboard/executive', 'dashboard/history', 'dashboard/settings'],
        ],
        'linda' => [
            'label' => 'Linda',
            'parent' => null,
            'blocks' => ['linda'],
        ],
        'members' => [
            // Every screen reads members, and public registration creates them.
            'label' => 'Members',
            'parent' => null,
            'blocks' => [],
        ],
        'loans' => [
            // Payments, Collateral, Members, Reports and the dashboards read loans.
            'label' => 'Loans',
            'parent' => null,
            'blocks' => ['loan-adjustments', 'loans/amortization-calculator'],
        ],
        'loans.reminders' => [
            'label' => 'Reminders',
            'parent' => 'loans',
            'blocks' => ['reminders', 'loans/*/reminders', 'borrowers/*/notification-preferences'],
        ],
        'loans.origination' => [
            'label' => 'Loan Origination',
            'parent' => 'loans',
            'blocks' => ['loan-origination', 'public-apply'],
        ],
        'payments' => [
            // A loan's page records, previews and voids its payments.
            'label' => 'Payments',
            'parent' => null,
            'blocks' => [],
        ],
        'payments.auto_pay' => [
            'label' => 'Auto-Pay',
            'parent' => 'payments',
            'blocks' => ['auto-pay', 'PATCH loans/*/auto-pay'],
        ],
        'share_capital' => [
            // GET share-capital/ledger stays open: the Dashboard's Share Capital
            // card, the loan and restructure forms, Collateral and a member's
            // collaterals read balances from it.
            'label' => 'Share Capital',
            'parent' => null,
            'blocks' => ['share-capital/members', 'share-capital/cash-reference', 'POST share-capital/ledger', 'pledges'],
        ],
        'share_capital.auto_credit' => [
            'label' => 'Auto-Credit',
            'parent' => 'share_capital',
            'blocks' => ['auto-credit', 'PATCH pledges/*/auto-credit'],
        ],
        'savings' => [
            'label' => 'Savings',
            'parent' => null,
            'blocks' => ['savings', 'reports/savings', 'portal/savings', 'portal/time-deposits'],
            'open' => [
                'GET savings/accounts' => 'AMLA Compliance → Orders Register: recording an order lists the member\'s savings accounts to hold.',
            ],
        ],
        'collateral' => [
            // The loan forms and a member's collaterals tab list and attach them.
            'label' => 'Collateral',
            'parent' => null,
            'blocks' => ['collaterals/register', 'POST collaterals', 'PUT collaterals/*', 'DELETE collaterals/*'],
        ],
        'gcash' => [
            'label' => 'GCash',
            'parent' => null,
            'blocks' => ['gcash'],
        ],
        'credit_scoring' => [
            'label' => 'Credit Scoring',
            'parent' => null,
            'blocks' => ['credit-scoring'],
        ],
        'cda' => [
            'label' => 'CDA Compliance',
            'parent' => null,
            'blocks' => ['cda'],
        ],
        'member_portal' => [
            'label' => 'Member Portal',
            'parent' => null,
            'blocks' => ['portal-admin', 'portal'],
        ],
        'amla' => [
            'label' => 'AMLA Compliance',
            'parent' => null,
            'blocks' => ['amla'],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'parent' => null,
            'blocks' => ['accounting', 'interbranch-transfers'],
            'open' => [
                'GET accounting/dashboard' => 'Executive Dashboard: its Financial Performance section reads the books\' figures.',
            ],
        ],
        'users' => [
            'label' => 'User Management',
            'parent' => null,
            'blocks' => ['users'],
        ],
        'reports' => [
            // The dashboards, a loan's Statement of Account, Past Due Loans and
            // the printables read the other reports.
            'label' => 'Reports',
            'parent' => null,
            'blocks' => [
                'reports/daily-collection', 'reports/income', 'reports/profit-sharing', 'reports/provisioning',
                'reports/releases', 'reports/repayments', 'reports/due-past-due/export',
            ],
        ],
        'documents' => [
            // The printables read other modules' API only.
            'label' => 'Documents',
            'parent' => null,
            'blocks' => [],
        ],
        'audit_trail' => [
            'label' => 'Audit Trail',
            'parent' => null,
            'blocks' => ['audit-logs'],
        ],
    ];

    /** @return list<string> every module key, in the side menu's order */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }

    public static function has(string $key): bool
    {
        return isset(self::MODULES[$key]);
    }

    public static function label(string $key): string
    {
        return self::MODULES[$key]['label'] ?? ucwords(str_replace(['.', '_'], ' ', $key));
    }

    public static function parentOf(string $key): ?string
    {
        return self::MODULES[$key]['parent'] ?? null;
    }

    /** A module in words, a submenu with its menu: "Auto-Credit (Share Capital)". */
    public static function words(string $key): string
    {
        $parent = self::parentOf($key);

        return $parent === null ? self::label($key) : self::label($key).' ('.self::label($parent).')';
    }

    /** @return list<string> */
    public static function blocks(string $key): array
    {
        return self::MODULES[$key]['blocks'] ?? [];
    }

    /**
     * The routes this module keeps open while hidden, each with the page of
     * another menu that needs it.
     *
     * @return array<string, string>
     */
    public static function keptOpen(string $key): array
    {
        return self::MODULES[$key]['open'] ?? [];
    }

    /**
     * The modules hidden by these switches: each switched-off module and the
     * submenus of a switched-off menu, in the side menu's order. Unknown keys
     * count for nothing.
     *
     * @param  iterable<string>  $switchedOff
     * @return list<string>
     */
    public static function effective(iterable $switchedOff): array
    {
        $off = [];

        foreach ($switchedOff as $key) {
            $off[$key] = true;
        }

        return array_values(array_filter(
            self::keys(),
            fn (string $key) => isset($off[$key]) || (($parent = self::parentOf($key)) !== null && isset($off[$parent])),
        ));
    }

    /**
     * The hidden module whose own API this route is, or null when the route
     * stays open: no hidden module blocks it, or the module that does keeps it
     * open for another menu.
     *
     * @param  string  $uri  the matched route's URI, as Route::uri() gives it ("api/savings/accounts/{account}")
     * @param  list<string>  $hidden  the effective hidden list (self::effective())
     */
    public static function blockingModule(string $method, string $uri, array $hidden): ?string
    {
        $segments = self::segmentsOf($uri);

        if ($segments === [] || in_array($segments[0], self::ALWAYS_OPEN, true)) {
            return null;
        }

        foreach ($hidden as $key) {
            $blocked = array_any(self::blocks($key), fn (string $entry) => self::entryMatches($entry, $method, $segments));

            if (! $blocked) {
                continue;
            }

            $keptOpen = array_any(
                array_keys(self::keptOpen($key)),
                fn (string $entry) => self::entryMatches($entry, $method, $segments, exact: true),
            );

            if (! $keptOpen) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Whether an entry (`[METHOD ]segment/segment`) covers a route: its method
     * (HEAD counts as GET) and each of its segments equal to the route's at the
     * same place, `*` standing for any one. A prefix unless `$exact`.
     *
     * @param  list<string>  $segments  the route's own segments, after `api/` (self::segmentsOf())
     */
    public static function entryMatches(string $entry, string $method, array $segments, bool $exact = false): bool
    {
        [$entryMethod, $path] = str_contains($entry, ' ') ? explode(' ', $entry, 2) : [null, $entry];
        $requestMethod = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);

        if ($entryMethod !== null && $entryMethod !== $requestMethod) {
            return false;
        }

        $wanted = explode('/', $path);

        if ($segments === [] || count($wanted) > count($segments) || ($exact && count($wanted) !== count($segments))) {
            return false;
        }

        foreach ($wanted as $at => $segment) {
            if ($segment !== '*' && $segment !== $segments[$at]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A route URI's segments after `api/` ("api/loans/{loan}/reminders" →
     * loans, {loan}, reminders), or none for a route outside the API.
     *
     * @return list<string>
     */
    public static function segmentsOf(string $uri): array
    {
        $uri = trim($uri, '/');

        if (! str_starts_with($uri, 'api/')) {
            return [];
        }

        return array_values(array_filter(explode('/', substr($uri, 4)), fn (string $segment) => $segment !== ''));
    }

    /**
     * Every first segment any module blocks, so a request elsewhere is passed
     * without reading the hidden list.
     *
     * @return list<string>
     */
    public static function blockedFirstSegments(): array
    {
        $first = [];

        foreach (self::MODULES as $module) {
            foreach ($module['blocks'] as $entry) {
                $path = str_contains($entry, ' ') ? explode(' ', $entry, 2)[1] : $entry;
                $first[explode('/', $path)[0]] = true;
            }
        }

        return array_keys($first);
    }
}
