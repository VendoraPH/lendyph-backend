<?php

namespace App\Services\Linda;

use App\Models\User;

/**
 * The app's side menu, kept on the server for "How do I…" and "Where is…"
 * questions: each page's titles and route as lendyph-frontend
 * src/constants/navigation.ts (SIDEBAR_NAV) shows them, with the permissions
 * that menu and the page itself need (the section's, the page's own and any
 * it also requires).
 *
 * Every page is listed, the module pages shown only while their screens are
 * switched on (Savings, CDA, Credit Scoring, Member Portal, Linda,
 * Penalties) included: the backend can't read the frontend's build flags.
 * Two pages are left out: Amortization BINHS (one cooperative's page) and
 * Loan Origination (no permission behind it on the server yet).
 *
 * find() answers only a question that starts by asking how or where ("how do
 * I", "where is", "paano", "saan", "unsaon", "asa") and whose every other word
 * is understood: a filler, a "see" or "add" word, or part of a page's name or
 * one of its task phrases. A name or a record number is never understood, so
 * a lookup never lands here. No model is ever asked.
 */
final class LindaMenu
{
    /**
     * [section, page (null for a section with no pages), route, the
     * permissions needed, extra task phrases].
     *
     * @var list<array{0: string, 1: ?string, 2: string, 3: list<string>, 4?: list<string>}>
     */
    private const ENTRIES = [
        ['Dashboard', null, '/dashboard', ['dashboard:view'], ['home']],
        ['Linda', null, '/linda', ['linda:use']],
        ['Members', 'All Members', '/borrowers', ['borrowers:view'], ['member list', 'list of member', 'miyembro', 'listahan ng member']],
        ['Members', 'New Member', '/borrowers/new', ['borrowers:view', 'borrowers:create'], ['add member', 'register member', 'add miyembro', 'register miyembro']],
        ['Loans', 'All Loans', '/loans', ['loans:view'], ['loan list', 'list of loan']],
        ['Loans', 'New Application', '/loans/new', ['loans:view', 'loans:create'], ['add loan', 'apply loan', 'loan application', 'add loan application', 'create loan application']],
        ['Loans', 'Amortization Calculator', '/loans/amortization', ['loans:view'], ['amortization', 'compute amortization', 'amortization schedule calculator']],
        ['Loans', 'Restructure', '/loans/restructure', ['loans:view', 'loans:restructure'], ['restructure loan']],
        ['Loans', 'Reminders', '/loans/reminders', ['loans:view', 'reminders:view'], ['payment reminder', 'send reminder']],
        ['Payments', 'New Payment', '/payments', ['payments:view'], ['add payment', 'payment entry', 'post payment', 'accept payment', 'receive payment', 'add bayad']],
        ['Payments', 'Payment History', '/payments/history', ['payments:view'], ['past payment', 'payment list', 'list of payment']],
        ['Payments', 'Auto-Pay', '/payments/auto-pay', ['payments:view', 'auto_pay:view'], ['autopay']],
        ['Share Capital', null, '/share-capital', ['share_capital:view'], ['share capital member', 'share capital member list']],
        ['Share Capital', 'Subsidiary Ledger', '/share-capital/ledger', ['share_capital:view'], ['share capital ledger']],
        ['Share Capital', 'Pledge Entry', '/share-capital/pledges', ['share_capital:view'], ['pledge', 'add pledge']],
        ['Share Capital', 'Auto-Credit', '/share-capital/auto-credit', ['share_capital:view', 'share_capital:create'], ['share capital auto credit']],
        ['Savings', 'Dashboard', '/savings/dashboard', ['savings:view']],
        ['Savings', 'Savings Accounts', '/savings', ['savings:view'], ['add savings account', 'open savings account']],
        ['Savings', 'Time Deposits', '/savings/time-deposits', ['savings:view'], ['add time deposit', 'place time deposit']],
        ['Savings', 'Savings Transactions', '/savings/transactions', ['savings:view'], ['add deposit', 'deposit', 'withdrawal', 'withdraw', 'add withdrawal']],
        ['Savings', 'Savings Approvals', '/savings/approvals', ['savings:view']],
        ['Savings', 'Savings Interest', '/savings/interest', ['savings:view'], ['interest run', 'savings interest run']],
        ['Savings', 'Savings Auto-Credit', '/savings/auto-credit', ['savings:view']],
        ['Savings', 'Dormant Accounts', '/savings/dormant', ['savings:view', 'reports:view'], ['dormant savings account']],
        ['Savings', 'Savings Products', '/savings/products', ['savings:view', 'savings:settings'], ['add savings product']],
        ['Savings', 'Savings Settings', '/savings/settings', ['savings:view', 'savings:settings']],
        ['Collateral', 'Collateral Listing', '/collaterals', ['collaterals:view'], ['collateral list', 'list of collateral']],
        ['Collateral', 'Collateral Entry', '/collaterals/new', ['collaterals:view', 'collaterals:create'], ['add collateral']],
        ['GCash', null, '/gcash', ['gcash:view'], ['cash in', 'cash out', 'gcash transaction']],
        ['Credit Scoring', 'Dashboard', '/credit-scoring', ['credit_scoring:view']],
        ['Credit Scoring', 'Member Scores', '/credit-scoring/borrowers', ['credit_scoring:view'], ['credit score', 'member credit score']],
        ['Credit Scoring', 'Credit Assessment', '/credit-scoring/assessment', ['credit_scoring:view']],
        ['Credit Scoring', 'Scorecard Configuration', '/credit-scoring/scorecard-configuration', ['credit_scoring:view', 'credit_scoring:settings'], ['scorecard']],
        ['Credit Scoring', 'Risk Monitoring', '/credit-scoring/risk-monitoring', ['credit_scoring:view']],
        ['Credit Scoring', 'Score History', '/credit-scoring/score-history', ['credit_scoring:view']],
        ['Credit Scoring', 'Settings', '/credit-scoring/settings', ['credit_scoring:view', 'credit_scoring:settings']],
        ['CDA Compliance', 'CDA Dashboard', '/cda', ['cda:view', 'branches:view_all']],
        ['CDA Compliance', 'Compliance Calendar', '/cda/calendar', ['cda:view', 'branches:view_all'], ['cda calendar']],
        ['CDA Compliance', 'CAPR', '/cda/capr', ['cda:view', 'branches:view_all']],
        ['CDA Compliance', 'Financial Statements', '/cda/financial-statements', ['cda:view', 'branches:view_all', 'cda:view_audit'], ['cda financial statement']],
        ['CDA Compliance', 'Performance Audit', '/cda/performance-audit', ['cda:view', 'branches:view_all', 'cda:view_audit']],
        ['CDA Compliance', 'Social Audit', '/cda/social-audit', ['cda:view', 'branches:view_all']],
        ['CDA Compliance', 'Enhanced PESOS', '/cda/pesos', ['cda:view', 'branches:view_all'], ['pesos', 'pesos rating']],
        ['CDA Compliance', 'Loan Classification', '/cda/loan-classification', ['cda:view', 'branches:view_all']],
        ['CDA Compliance', 'Officers & Trainings', '/cda/officers', ['cda:view', 'branches:view_all'], ['officer', 'training', 'cda officer', 'cda training']],
        ['CDA Compliance', 'Mediation & Conciliation', '/cda/mediation', ['cda:view', 'branches:view_all', 'cda:view_mediation'], ['mediation']],
        ['CDA Compliance', 'Sworn Statement', '/cda/sworn-statement', ['cda:view', 'branches:view_all']],
        ['CDA Compliance', 'ATIR & ABR', '/cda/tax-reports', ['cda:view', 'branches:view_all', 'cda:view_tax'], ['atir', 'abr', 'cda tax report']],
        ['CDA Compliance', 'Compliance Documents', '/cda/documents', ['cda:view', 'branches:view_all'], ['cda document']],
        ['CDA Compliance', 'Submission History', '/cda/submissions', ['cda:view', 'branches:view_all'], ['cda submission']],
        ['CDA Compliance', 'CDA Settings', '/cda/settings', ['cda:view', 'branches:view_all']],
        ['Member Portal', 'Portal Access', '/member-portal', ['member_portal:view'], ['activation code', 'portal activation code']],
        ['Member Portal', 'Portal Requests', '/member-portal/requests', ['member_portal:requests']],
        ['Member Portal', 'Portal Announcements', '/member-portal/announcements', ['member_portal:announcements'], ['add announcement']],
        ['Accounting', 'Dashboard', '/accounting', ['accounting:view']],
        ['Accounting', 'Chart of Accounts', '/accounting/chart-of-accounts', ['accounting:view', 'chart_of_accounts:view']],
        ['Accounting', 'Journal Entries', '/accounting/journals', ['accounting:view', 'journals:view'], ['journal', 'journal entry', 'add journal entry']],
        ['Accounting', 'Cash & Bank', '/accounting/cash-bank', ['accounting:view', 'cash_accounts:view'], ['bank account', 'cash transfer', 'fund transfer']],
        ['Accounting', 'Expenses & Payables', '/accounting/expenses', ['accounting:view', 'expenses:view'], ['expense', 'add expense', 'record expense', 'payable']],
        ['Accounting', 'Loan Accounting', '/accounting/loans', ['accounting:view']],
        ['Accounting', 'General Ledger', '/accounting/general-ledger', ['accounting:view']],
        ['Accounting', 'Trial Balance', '/accounting/trial-balance', ['accounting:view']],
        ['Accounting', 'Reconciliation', '/accounting/reconciliation', ['accounting:view', 'accounting:reconcile'], ['bank reconciliation']],
        ['Accounting', 'Financial Statements', '/accounting/statements', ['accounting:view'], ['balance sheet', 'income statement']],
        ['Accounting', 'Accounting Books', '/accounting/books', ['accounting:view'], ['book', 'book of account']],
        ['Accounting', 'Period Closing', '/accounting/period-closing', ['accounting:view', 'accounting:close'], ['close period', 'close month', 'year end close']],
        ['Accounting', 'Opening Balances', '/accounting/opening-balances', ['accounting:view', 'accounting:settings'], ['opening balance']],
        ['Accounting', 'Allowance for Credit Losses', '/accounting/allowance', ['accounting:view'], ['allowance']],
        ['Accounting', 'Settings', '/accounting/settings', ['accounting:view', 'accounting:settings']],
        ['User Management', null, '/users', ['users:view'], ['user', 'add user', 'staff account', 'add staff account']],
        ['Reports', null, '/reports', ['reports:view'], ['report']],
        ['Documents', null, '/printables', ['reports:view'], ['document', 'printable', 'print document']],
        ['Audit Trail', null, '/audit-trail', ['audit_logs:view'], ['audit log']],
        ['Settings', null, '/settings', ['settings:view']],
        ['Settings', 'Profile', '/settings/profile', ['settings:view'], ['my profile']],
        ['Settings', 'Branding', '/settings/branding', ['settings:view'], ['logo']],
        ['Settings', 'Branches', '/settings/branches', ['settings:view'], ['branch', 'add branch']],
        ['Settings', 'Loan Products', '/settings/loan-products', ['settings:view'], ['loan product', 'add loan product']],
        ['Settings', 'Fees', '/settings/fees', ['settings:view', 'fees:view'], ['fee', 'add fee']],
        ['Settings', 'Penalties', '/settings/penalties', ['settings:view', 'penalties:view'], ['penalty', 'penalty setting']],
        ['Settings', 'Collateral Types', '/settings/collateral-types', ['settings:view'], ['collateral type']],
        ['Settings', 'Role and Permissions', '/settings/user-roles', ['settings:view'], ['role', 'permission', 'user role', 'add role']],
        ['Settings', 'Approval Workflow', '/settings/approval-workflow', ['settings:view'], ['approval chain', 'approver']],
        ['Settings', 'GCash', '/settings/gcash', ['settings:view'], ['gcash setting', 'gcash charge']],
        ['Settings', 'Member Portal', '/settings/member-portal', ['settings:view', 'member_portal:settings', 'branches:view_all'], ['member portal setting']],
        ['Settings', 'Linda', '/settings/linda', ['settings:view', 'linda:settings', 'branches:view_all'], ['linda setting', 'linda model']],
        ['Settings', 'Data Import', '/settings/data-import', ['settings:view', 'imports:process'], ['import', 'csv import', 'import member', 'import loan']],
    ];

    /** The words a how or where question starts with, after a greeting. */
    private const ASKING = ['how', 'where', 'paano', 'pano', 'papaano', 'saan', 'nasaan', 'unsaon', 'unsay', 'asa', 'diin'];

    /** Words that only say "show me" or "go to": the page is what's left. */
    private const SEEING = [
        'see', 'view', 'find', 'open', 'check', 'look', 'go', 'get', 'access', 'navigate', 'reach', 'show',
        'makita', 'makikita', 'makakita', 'tingnan', 'tignan', 'makita', 'pumunta', 'puntahan', 'buksan', 'ablihan',
        'lantaw', 'tan', 'aw', 'adto', 'moadto', 'makaadto',
    ];

    /** Words that mean "add" or "new", read as "add". */
    private const ADDING = [
        'add', 'new', 'create', 'register', 'make', 'encode', 'enter', 'record', 'start',
        'magdagdag', 'dagdag', 'idagdag', 'gumawa', 'gawa', 'maglagay', 'ilagay', 'bagong',
        'himo', 'maghimo', 'mohimo', 'paghimo', 'dugang', 'idugang', 'magdugang', 'bag ong',
    ];

    /** Words that carry no meaning in a how or where question. */
    private const FILLERS = [
        'a', 'an', 'the', 'i', 'we', 'you', 'do', 'does', 'can', 'could', 'should', 'would', 'to', 'is', 'are', 'my',
        'our', 'for', 'of', 'in', 'on', 'at', 'it', 'there', 'me', 'page', 'screen', 'menu', 'section', 'tab', 'app',
        'system', 'lendy', 'please', 'pls', 'kindly', 'hi', 'hello', 'and', 'or', 'up', 'set', 'find',
        'po', 'ba', 'ang', 'ng', 'mga', 'sa', 'ko', 'ako', 'natin', 'namin', 'tayo', 'kami', 'yung', 'ung', 'na',
        'mag', 'pag', 'para', 'nang', 'makapag', 'maka', 'ito', 'iyan',
        'og', 'ug', 'nga', 'nako', 'ta', 'nato', 'ka', 'man', 'lang', 'kay', 'diay', 'ko', 'mo', 'kini', 'kana',
        'naa', 'akong', 'among', 'atong',
    ];

    /**
     * The pages a question asks how to reach, among every page in the menu
     * (whether or not $user may open them), or null when it isn't a how or
     * where question about a page.
     *
     * @return list<array{path: string, title: string, url: string, needs: list<string>}>|null
     */
    public static function find(#[\SensitiveParameter] string $question): ?array
    {
        $words = LindaText::words(LindaText::clean($question));

        while ($words !== [] && in_array($words[0], ['hi', 'hello', 'please', 'pls', 'kindly', 'po'], true)) {
            array_shift($words);
        }

        if ($words === [] || ! in_array($words[0], self::ASKING, true)) {
            return null;
        }

        $wanted = self::key(implode(' ', array_slice($words, 1)));

        if ($wanted === '') {
            return null;
        }

        $found = [];
        foreach (self::entries() as $entry) {
            if (in_array($wanted, $entry['keys'], true)) {
                $found[] = ['path' => $entry['path'], 'title' => $entry['title'], 'url' => $entry['url'], 'needs' => $entry['needs']];
            }
        }

        return $found === [] ? null : $found;
    }

    /**
     * Every page, in the menu's order: its path in words ("Members → New
     * Member"), its own title, its route, the permissions it needs and the
     * phrases that name it.
     *
     * @return list<array{path: string, title: string, url: string, needs: list<string>, keys: list<string>}>
     */
    public static function entries(): array
    {
        $sectionHome = [];
        foreach (self::ENTRIES as [$section, $page, $url]) {
            $sectionHome[$section] ??= null;
            if ($page === null || self::isSectionHome($section, $url)) {
                $sectionHome[$section] = $url;
            }
        }

        $entries = [];
        foreach (self::ENTRIES as $row) {
            [$section, $page, $url, $needs] = $row;
            $phrases = [...($row[4] ?? []), $page ?? $section];

            if ($page !== null) {
                $phrases[] = $section.' '.$page;
            }

            if ($sectionHome[$section] === $url) {
                $phrases[] = $section;
            }

            $entries[] = [
                'path' => $page === null ? $section : $section.' → '.$page,
                'title' => $page ?? $section,
                'url' => $url,
                'needs' => $needs,
                'keys' => array_values(array_unique(array_filter(array_map(self::key(...), $phrases), fn (string $key) => $key !== ''))),
            ];
        }

        return $entries;
    }

    /** Whether $user may open a page: every permission it needs. */
    public static function canOpen(User $user, array $page): bool
    {
        foreach ($page['needs'] as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /** The menu's own route for a section: the page it opens on. */
    private static function isSectionHome(string $section, string $url): bool
    {
        return in_array($section.' '.$url, [
            'Members /borrowers', 'Loans /loans', 'Payments /payments', 'Savings /savings', 'Collateral /collaterals',
            'Credit Scoring /credit-scoring', 'CDA Compliance /cda', 'Member Portal /member-portal', 'Accounting /accounting',
        ], true);
    }

    /**
     * A phrase as compared: cleaned, "add" and "new" words read as "add",
     * "see" words and fillers dropped, plurals made singular, and a repeated
     * word kept once.
     */
    private static function key(#[\SensitiveParameter] string $text): string
    {
        $clean = ' '.LindaText::clean($text).' ';

        foreach (self::ADDING as $word) {
            $clean = str_replace(' '.$word.' ', ' add ', $clean);
        }

        $kept = [];
        foreach (LindaText::words(trim($clean)) as $word) {
            if (in_array($word, self::FILLERS, true) || in_array($word, self::SEEING, true)) {
                continue;
            }

            $word = self::singular($word);

            if ($kept === [] || end($kept) !== $word) {
                $kept[] = $word;
            }
        }

        return implode(' ', $kept);
    }

    private static function singular(string $word): string
    {
        return mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
    }
}
