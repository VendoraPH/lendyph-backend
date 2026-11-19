<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaMenu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Linda round 3, "How do I…" and "Where is…": the server's copy of the side
 * menu (lendyph-frontend src/constants/navigation.ts) and the questions that
 * find a page in it, in English, Tagalog/Taglish and Bisaya/Cebuano.
 *
 * No database and no application boot.
 */
class LindaMenuTest extends TestCase
{
    public function test_the_menu_mirrors_the_frontend_navigation(): void
    {
        $this->assertSame([
            'Dashboard' => '/dashboard',
            'Linda' => '/linda',
            'Members → All Members' => '/borrowers',
            'Members → New Member' => '/borrowers/new',
            'Loans → All Loans' => '/loans',
            'Loans → New Application' => '/loans/new',
            'Loans → Amortization Calculator' => '/loans/amortization',
            'Loans → Restructure' => '/loans/restructure',
            'Loans → Reminders' => '/loans/reminders',
            'Payments → New Payment' => '/payments',
            'Payments → Payment History' => '/payments/history',
            'Payments → Auto-Pay' => '/payments/auto-pay',
            'Share Capital' => '/share-capital',
            'Share Capital → Subsidiary Ledger' => '/share-capital/ledger',
            'Share Capital → Pledge Entry' => '/share-capital/pledges',
            'Share Capital → Auto-Credit' => '/share-capital/auto-credit',
            'Savings → Dashboard' => '/savings/dashboard',
            'Savings → Savings Accounts' => '/savings',
            'Savings → Time Deposits' => '/savings/time-deposits',
            'Savings → Savings Transactions' => '/savings/transactions',
            'Savings → Savings Approvals' => '/savings/approvals',
            'Savings → Savings Interest' => '/savings/interest',
            'Savings → Savings Auto-Credit' => '/savings/auto-credit',
            'Savings → Dormant Accounts' => '/savings/dormant',
            'Savings → Savings Products' => '/savings/products',
            'Savings → Savings Settings' => '/savings/settings',
            'Collateral → Collateral Listing' => '/collaterals',
            'Collateral → Collateral Entry' => '/collaterals/new',
            'GCash' => '/gcash',
            'Credit Scoring → Dashboard' => '/credit-scoring',
            'Credit Scoring → Member Scores' => '/credit-scoring/borrowers',
            'Credit Scoring → Credit Assessment' => '/credit-scoring/assessment',
            'Credit Scoring → Scorecard Configuration' => '/credit-scoring/scorecard-configuration',
            'Credit Scoring → Risk Monitoring' => '/credit-scoring/risk-monitoring',
            'Credit Scoring → Score History' => '/credit-scoring/score-history',
            'Credit Scoring → Settings' => '/credit-scoring/settings',
            'CDA Compliance → CDA Dashboard' => '/cda',
            'CDA Compliance → Compliance Calendar' => '/cda/calendar',
            'CDA Compliance → CAPR' => '/cda/capr',
            'CDA Compliance → Financial Statements' => '/cda/financial-statements',
            'CDA Compliance → Performance Audit' => '/cda/performance-audit',
            'CDA Compliance → Social Audit' => '/cda/social-audit',
            'CDA Compliance → Enhanced PESOS' => '/cda/pesos',
            'CDA Compliance → Loan Classification' => '/cda/loan-classification',
            'CDA Compliance → Officers & Trainings' => '/cda/officers',
            'CDA Compliance → Mediation & Conciliation' => '/cda/mediation',
            'CDA Compliance → Sworn Statement' => '/cda/sworn-statement',
            'CDA Compliance → ATIR & ABR' => '/cda/tax-reports',
            'CDA Compliance → Compliance Documents' => '/cda/documents',
            'CDA Compliance → Submission History' => '/cda/submissions',
            'CDA Compliance → CDA Settings' => '/cda/settings',
            'Member Portal → Portal Access' => '/member-portal',
            'Member Portal → Portal Requests' => '/member-portal/requests',
            'Member Portal → Portal Announcements' => '/member-portal/announcements',
            'Accounting → Dashboard' => '/accounting',
            'Accounting → Chart of Accounts' => '/accounting/chart-of-accounts',
            'Accounting → Journal Entries' => '/accounting/journals',
            'Accounting → Cash & Bank' => '/accounting/cash-bank',
            'Accounting → Expenses & Payables' => '/accounting/expenses',
            'Accounting → Loan Accounting' => '/accounting/loans',
            'Accounting → General Ledger' => '/accounting/general-ledger',
            'Accounting → Trial Balance' => '/accounting/trial-balance',
            'Accounting → Reconciliation' => '/accounting/reconciliation',
            'Accounting → Financial Statements' => '/accounting/statements',
            'Accounting → Accounting Books' => '/accounting/books',
            'Accounting → Period Closing' => '/accounting/period-closing',
            'Accounting → Opening Balances' => '/accounting/opening-balances',
            'Accounting → Allowance for Credit Losses' => '/accounting/allowance',
            'Accounting → Settings' => '/accounting/settings',
            'User Management' => '/users',
            'Reports' => '/reports',
            'Documents' => '/printables',
            'Audit Trail' => '/audit-trail',
            'Settings' => '/settings',
            'Settings → Profile' => '/settings/profile',
            'Settings → Branding' => '/settings/branding',
            'Settings → Branches' => '/settings/branches',
            'Settings → Loan Products' => '/settings/loan-products',
            'Settings → Fees' => '/settings/fees',
            'Settings → Penalties' => '/settings/penalties',
            'Settings → Collateral Types' => '/settings/collateral-types',
            'Settings → Role and Permissions' => '/settings/user-roles',
            'Settings → Approval Workflow' => '/settings/approval-workflow',
            'Settings → GCash' => '/settings/gcash',
            'Settings → Member Portal' => '/settings/member-portal',
            'Settings → Linda' => '/settings/linda',
            'Settings → Data Import' => '/settings/data-import',
        ], array_column(LindaMenu::entries(), 'url', 'path'));
    }

    public function test_the_permissions_follow_the_menu(): void
    {
        $needs = array_column(LindaMenu::entries(), 'needs', 'path');

        $this->assertSame(['borrowers:view', 'borrowers:create'], $needs['Members → New Member']);
        $this->assertSame(['cda:view', 'branches:view_all', 'cda:view_mediation'], $needs['CDA Compliance → Mediation & Conciliation']);
        $this->assertSame(['settings:view', 'imports:process'], $needs['Settings → Data Import']);
        $this->assertSame(['accounting:view', 'expenses:view'], $needs['Accounting → Expenses & Payables']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function questions(): array
    {
        return [
            'en add a member' => ['How do I add a member?', ['Members → New Member']],
            'en register a new member' => ['How can I register a new member?', ['Members → New Member']],
            'en record a payment' => ['How do I record a payment?', ['Payments → New Payment']],
            'en where is the trial balance' => ['Where is the trial balance?', ['Accounting → Trial Balance']],
            'en where can I see payment history' => ['Where can I see the payment history?', ['Payments → Payment History']],
            'en restructure a loan' => ['How do I restructure a loan?', ['Loans → Restructure']],
            'en new loan application' => ['How to create a new loan application?', ['Loans → New Application']],
            'en where are the reports' => ['Where are the reports?', ['Reports']],
            'en savings dashboard' => ['Where is the savings dashboard?', ['Savings → Dashboard']],
            'en financial statements, two places' => ['Where are the financial statements?', ['CDA Compliance → Financial Statements', 'Accounting → Financial Statements']],
            'tl mag-add ng member' => ['Paano mag-add ng member?', ['Members → New Member']],
            'tl saan makikita payment history' => ['Saan makikita ang payment history?', ['Payments → Payment History']],
            'tl paano gumawa ng journal entry' => ['Paano gumawa ng journal entry?', ['Accounting → Journal Entries']],
            'ceb unsaon pag-add og bag-ong member' => ['Unsaon pag-add og bag-ong member?', ['Members → New Member']],
            'ceb asa makita ang trial balance' => ['Asa nako makita ang trial balance?', ['Accounting → Trial Balance']],
            'ceb unsaon paghimo og loan application' => ['Unsaon paghimo og loan application?', ['Loans → New Application']],
        ];
    }

    /** @param  list<string>  $paths */
    #[DataProvider('questions')]
    public function test_how_and_where_questions_find_their_page(string $question, array $paths): void
    {
        $this->assertSame($paths, array_column(LindaMenu::find($question) ?? [], 'path'), $question);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notMenuQuestions(): array
    {
        return [
            'a figure' => ['How many members do we have?'],
            'not asking how' => ['Add a member'],
            'a name' => ['How do I add Juan Dela Cruz?'],
            'a record number' => ['Where is LN-000123?'],
            'an unknown task' => ['How do I approve a loan?'],
            'nothing asked' => ['Where?'],
        ];
    }

    #[DataProvider('notMenuQuestions')]
    public function test_other_questions_find_no_page(string $question): void
    {
        $this->assertNull(LindaMenu::find($question), $question);
    }
}
