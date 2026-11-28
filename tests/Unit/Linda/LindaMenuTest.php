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
            'Collateral → Collateral Listing' => '/collaterals',
            'Collateral → Collateral Entry' => '/collaterals/new',
            'GCash' => '/gcash',
            'Credit Scoring → Dashboard' => '/credit-scoring',
            'Credit Scoring → Member Scores' => '/credit-scoring/borrowers',
            'Credit Scoring → Credit Assessment' => '/credit-scoring/assessment',
            'Credit Scoring → Risk Monitoring' => '/credit-scoring/risk-monitoring',
            'Credit Scoring → Score History' => '/credit-scoring/score-history',
            'CIC Reporting → Data Readiness' => '/cic',
            'CIC Reporting → Subject Records' => '/cic/subjects',
            'CIC Reporting → Contract Records' => '/cic/contracts',
            'CIC Reporting → Issues' => '/cic/issues',
            'CIC Reporting → Generate Report' => '/cic/generate',
            'CIC Reporting → Corrections' => '/cic/corrections',
            'CIC Reporting → Submission History' => '/cic/submissions',
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
            'Settings → Accounting' => '/settings/accounting',
            'Settings → Savings Products' => '/settings/savings-products',
            'Settings → Savings' => '/settings/savings',
            'Settings → CDA' => '/settings/cda',
            'Settings → Scorecard Configuration' => '/settings/scorecard-configuration',
            'Settings → Credit Scoring' => '/settings/credit-scoring',
            'Settings → CIC' => '/settings/cic',
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

        // Module settings moved under Settings (frontend #650) keep their own permissions.
        $this->assertSame(['accounting:view', 'accounting:settings'], $needs['Settings → Accounting']);
        $this->assertSame(['savings:view', 'savings:settings'], $needs['Settings → Savings Products']);
        $this->assertSame(['savings:view', 'savings:settings'], $needs['Settings → Savings']);
        $this->assertSame(['cda:view', 'branches:view_all'], $needs['Settings → CDA']);
        $this->assertSame(['credit_scoring:view', 'credit_scoring:settings'], $needs['Settings → Scorecard Configuration']);
        $this->assertSame(['credit_scoring:view', 'credit_scoring:settings'], $needs['Settings → Credit Scoring']);

        // CIC: the menu needs cic:view and every branch, Generate Report also
        // cic:prepare, and Settings → CIC its own key and every branch.
        foreach (['Data Readiness', 'Subject Records', 'Contract Records', 'Issues', 'Corrections', 'Submission History'] as $page) {
            $this->assertSame(['cic:view', 'branches:view_all'], $needs['CIC Reporting → '.$page], $page);
        }
        $this->assertSame(['cic:view', 'branches:view_all', 'cic:prepare'], $needs['CIC Reporting → Generate Report']);
        $this->assertSame(['cic:settings', 'branches:view_all'], $needs['Settings → CIC']);
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
            // Module settings under Settings (frontend #650).
            'en savings settings' => ['Where are the savings settings?', ['Settings → Savings']],
            'en savings products' => ['How do I add a savings product?', ['Settings → Savings Products']],
            'en cda settings page' => ['Where is the CDA settings page?', ['Settings → CDA']],
            'en accounting settings' => ['Where are the accounting settings?', ['Settings → Accounting']],
            'en credit scoring settings' => ['Where are the credit scoring settings?', ['Settings → Credit Scoring']],
            'en scorecard' => ['Where is the scorecard?', ['Settings → Scorecard Configuration']],
            'tl savings settings' => ['Saan ang savings settings?', ['Settings → Savings']],
            'ceb cda settings' => ['Asa makita ang CDA settings?', ['Settings → CDA']],
            // A module's own name still finds the module, not its settings.
            'en savings' => ['Where is savings?', ['Savings → Savings Accounts']],
            'en accounting' => ['Where is accounting?', ['Accounting → Dashboard']],
            'en credit scoring' => ['Where is credit scoring?', ['Credit Scoring → Dashboard']],
            // CIC Reporting, in English, Taglish and Bisaya.
            'en submit to cic' => ['How do I submit to CIC?', ['CIC Reporting → Generate Report', 'CIC Reporting → Submission History']],
            'en where is cic' => ['Where is CIC?', ['CIC Reporting → Data Readiness']],
            'en cic reporting' => ['Where is CIC Reporting?', ['CIC Reporting → Data Readiness']],
            'en cic report' => ['Where is the CIC report?', ['CIC Reporting → Generate Report']],
            'en create a cic file' => ['How do I create a CIC file?', ['CIC Reporting → Generate Report']],
            'en credit information' => ['Where is credit information?', ['CIC Reporting → Data Readiness']],
            'en credit information corporation' => ['Where is the Credit Information Corporation page?', ['CIC Reporting → Data Readiness']],
            'en cic issues' => ['Where are the CIC issues?', ['CIC Reporting → Issues']],
            'en cic corrections' => ['How do I make a CIC correction?', ['CIC Reporting → Corrections']],
            'en subject records' => ['Where are the subject records?', ['CIC Reporting → Subject Records']],
            'en cic subject records' => ['Where are the CIC subject records?', ['CIC Reporting → Subject Records']],
            'en contract records' => ['Where can I see the CIC contract records?', ['CIC Reporting → Contract Records']],
            'en cic settings' => ['Where are the CIC settings?', ['Settings → CIC']],
            'en submission history, two places' => ['Where is the submission history?', ['CIC Reporting → Submission History', 'CDA Compliance → Submission History']],
            'tl paano mag-submit sa cic' => ['Paano mag-submit sa CIC?', ['CIC Reporting → Generate Report', 'CIC Reporting → Submission History']],
            'tl saan ang cic report' => ['Saan makikita ang CIC report?', ['CIC Reporting → Generate Report']],
            'tl saan ang cic settings' => ['Saan ang CIC settings?', ['Settings → CIC']],
            'ceb asa ang cic' => ['Asa ang CIC?', ['CIC Reporting → Data Readiness']],
            'ceb unsaon pag-submit sa cic' => ['Unsaon pag-submit sa CIC?', ['CIC Reporting → Generate Report', 'CIC Reporting → Submission History']],
            'ceb asa makita ang cic report' => ['Asa makita ang CIC report?', ['CIC Reporting → Generate Report']],
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
            'a module settings page by its bare name' => ['Where is CDA?'],
            // Generic page titles alone never send a user to CIC.
            'a generic report' => ['How do I generate a report?'],
            'generic issues' => ['Where are the issues?'],
            'generic corrections' => ['Where are the corrections?'],
        ];
    }

    #[DataProvider('notMenuQuestions')]
    public function test_other_questions_find_no_page(string $question): void
    {
        $this->assertNull(LindaMenu::find($question), $question);
    }
}
