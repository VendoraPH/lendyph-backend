<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use PHPUnit\Framework\TestCase;

/**
 * The backend's intent names are exactly the frontend's `LindaIntent` union
 * (lendyph-frontend src/types/linda.ts), so a reply never carries a name the
 * panel doesn't know.
 *
 * No database and no application boot.
 */
class LindaIntentTest extends TestCase
{
    public function test_the_intents_are_exactly_these(): void
    {
        $this->assertSame([
            'collection_summary',
            'collection_details',
            'loan_summary',
            'loan_details',
            'borrower_lookup',
            'borrower_summary',
            'overdue_accounts',
            'disbursement_summary',
            'transaction_lookup',
            'income_summary',
            'expense_summary',
            'branch_performance',
            'officer_performance',
            'collection_efficiency',
            'portfolio_summary',
            'general_lending_summary',
            'savings_summary',
            'share_capital_summary',
            'cda_summary',
            'unsupported_question',
        ], array_map(fn (LindaIntent $intent) => $intent->value, LindaIntent::cases()));
    }

    public function test_figures_over_a_period_default_to_this_month(): void
    {
        $defaulting = array_values(array_filter(
            LindaIntent::cases(),
            fn (LindaIntent $intent) => $intent->defaultsToThisMonth(),
        ));

        $this->assertSame([
            LindaIntent::CollectionSummary,
            LindaIntent::CollectionDetails,
            LindaIntent::DisbursementSummary,
            LindaIntent::IncomeSummary,
            LindaIntent::ExpenseSummary,
            LindaIntent::BranchPerformance,
            LindaIntent::OfficerPerformance,
            LindaIntent::CollectionEfficiency,
            LindaIntent::GeneralLendingSummary,
        ], $defaulting);
    }

    public function test_lookups_and_as_of_today_answers_take_no_period(): void
    {
        $timeless = array_values(array_filter(
            LindaIntent::cases(),
            fn (LindaIntent $intent) => ! $intent->takesPeriod(),
        ));

        $this->assertSame([
            LindaIntent::LoanSummary,
            LindaIntent::LoanDetails,
            LindaIntent::BorrowerLookup,
            LindaIntent::OverdueAccounts,
            LindaIntent::TransactionLookup,
            LindaIntent::PortfolioSummary,
            LindaIntent::SavingsSummary,
            LindaIntent::ShareCapitalSummary,
            LindaIntent::CdaSummary,
            LindaIntent::UnsupportedQuestion,
        ], $timeless);
    }
}
