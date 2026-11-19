<?php

namespace App\Services\Linda;

/**
 * What a Linda question is about.
 *
 * The names of the frontend's `LindaIntent` union (lendyph-frontend
 * src/types/linda.ts), plus round 3's own, which the union accepts as plain
 * strings and the panel treats alike. The deterministic matcher answers five of them; the
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
    case SavingsSummary = 'savings_summary';
    case ShareCapitalSummary = 'share_capital_summary';
    case CdaSummary = 'cda_summary';
    case HowTo = 'how_to';
    case UnsupportedQuestion = 'unsupported_question';

    /**
     * Whether a question of this kind is read for a period at all. Overdue
     * accounts, active loans and the portfolio are as of today, so any period words a
     * question or the model gives them are ignored, never refused or called
     * unclear; an unsupported question has no period either, and nor does a
     * lookup (a member, a loan or a receipt), which is about one record.
     */
    public function takesPeriod(): bool
    {
        return match ($this) {
            self::OverdueAccounts, self::LoanSummary, self::PortfolioSummary, self::SavingsSummary, self::ShareCapitalSummary,
            self::CdaSummary, self::HowTo, self::UnsupportedQuestion,
            self::BorrowerLookup, self::LoanDetails, self::TransactionLookup => false,
            default => true,
        };
    }

    /**
     * The three lookups (a member, a loan or a receipt): the server answers
     * them itself (LindaLookup), and a follow-up never carries one on, since
     * a lookup is about one record and takes no period.
     */
    public function isLookup(): bool
    {
        return match ($this) {
            self::BorrowerLookup, self::LoanDetails, self::TransactionLookup => true,
            default => false,
        };
    }

    /**
     * The LindaLookupRequest kind a lookup intent asks for (a member, a loan
     * or a receipt), or null for any other intent.
     *
     * @return 'member'|'loan'|'receipt'|null
     */
    public function lookupKind(): ?string
    {
        return match ($this) {
            self::BorrowerLookup => LindaLookupRequest::MEMBER,
            self::LoanDetails => LindaLookupRequest::LOAN,
            self::TransactionLookup => LindaLookupRequest::RECEIPT,
            default => null,
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
            self::GeneralLendingSummary, self::IncomeSummary, self::ExpenseSummary => true,
            default => false,
        };
    }
}
