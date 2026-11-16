<?php

namespace App\Services\Linda;

/**
 * What a Linda question is about.
 *
 * Exactly the names of the frontend's `LindaIntent` union
 * (lendyph-frontend src/types/linda.ts), so a reply never carries a name the
 * panel doesn't know. The deterministic matcher answers five of them; the
 * model may choose any.
 */
enum LindaIntent: string
{
    case CollectionSummary = 'collection_summary';
    case CollectionDetails = 'collection_details';
    case LoanSummary = 'loan_summary';
    case LoanDetails = 'loan_details';
    case BorrowerLookup = 'borrower_lookup';
    case BorrowerSummary = 'borrower_summary';
    case OverdueAccounts = 'overdue_accounts';
    case DisbursementSummary = 'disbursement_summary';
    case TransactionLookup = 'transaction_lookup';
    case IncomeSummary = 'income_summary';
    case ExpenseSummary = 'expense_summary';
    case BranchPerformance = 'branch_performance';
    case OfficerPerformance = 'officer_performance';
    case CollectionEfficiency = 'collection_efficiency';
    case PortfolioSummary = 'portfolio_summary';
    case GeneralLendingSummary = 'general_lending_summary';
    case UnsupportedQuestion = 'unsupported_question';

    /**
     * Whether a question of this kind is read for a period at all. Overdue
     * accounts and active loans are as of today, so any period words a
     * question or the model gives them are ignored, never refused or called
     * unclear; an unsupported question has no period either, and nor does a
     * lookup (a member, a loan or a receipt), which is about one record.
     */
    public function takesPeriod(): bool
    {
        return match ($this) {
            self::OverdueAccounts, self::LoanSummary, self::UnsupportedQuestion,
            self::BorrowerLookup, self::LoanDetails, self::TransactionLookup => false,
            default => true,
        };
    }

    /**
     * Whether a question of this kind that names no period means this month:
     * the figures over a period Linda answers (collections, payments,
     * disbursements, collection efficiency, branch and officer performance
     * and the summary). Overdue accounts and active loans are as of now, so
     * they have none.
     */
    public function defaultsToThisMonth(): bool
    {
        return match ($this) {
            self::CollectionSummary, self::CollectionDetails, self::DisbursementSummary,
            self::BranchPerformance, self::OfficerPerformance, self::CollectionEfficiency,
            self::GeneralLendingSummary => true,
            default => false,
        };
    }
}
