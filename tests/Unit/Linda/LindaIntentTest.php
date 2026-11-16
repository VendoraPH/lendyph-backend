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
    public function test_the_intents_are_exactly_the_frontends_seventeen(): void
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
            'unsupported_question',
        ], array_map(fn (LindaIntent $intent) => $intent->value, LindaIntent::cases()));
    }

    public function test_collection_disbursement_and_summary_default_to_this_month(): void
    {
        $defaulting = array_values(array_filter(
            LindaIntent::cases(),
            fn (LindaIntent $intent) => $intent->defaultsToThisMonth(),
        ));

        $this->assertSame([
            LindaIntent::CollectionSummary,
            LindaIntent::DisbursementSummary,
            LindaIntent::GeneralLendingSummary,
        ], $defaulting);
    }
}
