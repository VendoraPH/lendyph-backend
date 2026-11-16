<?php

namespace Tests\Feature\Linda;

use App\Models\Branch;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording;
use App\Services\RepaymentService;

/**
 * How round 2's answers read: a loan names its product once, an instalment
 * due before today reads as still unpaid, and a ranking says when rows are
 * level or tied instead of naming one of them first.
 */
class LindaRound2WordingTest extends LindaAnswersTestCase
{
    private function answerer(): LindaAnswerer
    {
        return app(LindaAnswerer::class);
    }

    private function findLoan(string $number): LindaReply
    {
        return $this->answerer()->lookup(
            $this->branchUser(['loans:view']),
            new LindaLookupRequest(LindaLookupRequest::LOAN, LindaIntent::LoanDetails, $number),
        );
    }

    private function findMember(string $code): LindaReply
    {
        return $this->answerer()->lookup(
            $this->branchUser(['loans:view', 'borrowers:view']),
            new LindaLookupRequest(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup, $code),
        );
    }

    public function test_a_loan_names_its_product_once(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $salary = $this->releaseTo($ana, 60000);
        $salary->loanProduct->update(['name' => 'Salary Loan']);
        $ben = $this->member($this->branch, 'Ben', 'Reyes');
        $emergency = $this->releaseTo($ben, 20000);
        $emergency->loanProduct->update(['name' => 'Emergency']);

        $reply = $this->findLoan($salary->loan_account_number);
        $this->assertStringContainsString('is a ₱60,000.00 Salary Loan, now Past Due.', $reply->answer);
        $this->assertStringNotContainsStringIgnoringCase('loan loan', $reply->answer);

        $reply = $this->findLoan($emergency->loan_account_number);
        $this->assertStringContainsString('is a ₱20,000.00 Emergency loan, now Past Due.', $reply->answer);
    }

    public function test_an_instalment_due_before_today_reads_as_still_unpaid(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $late = $this->releaseTo($ana);
        $summary = app(RepaymentService::class)->getLoanSummary($late->fresh());
        $this->assertLessThan(now()->toDateString(), $summary['next_due_date']);
        $owed = LindaWording::money($summary['next_due_amount']).' was due on '.LindaWording::date($summary['next_due_date']).' and is still unpaid.';

        $loanReply = $this->findLoan($late->loan_account_number);
        $memberReply = $this->findMember($ana->borrower_code);

        foreach ([$loanReply, $memberReply] as $reply) {
            $this->assertStringContainsString($owed, $reply->answer);
            $this->assertStringNotContainsString('The next payment', $reply->answer);
        }
    }

    public function test_an_instalment_due_later_reads_as_the_next_payment(): void
    {
        $ben = $this->member($this->branch, 'Ben', 'Reyes');
        $fresh = $this->releaseTo($ben, 30000, now()->toDateString());
        $summary = app(RepaymentService::class)->getLoanSummary($fresh->fresh());
        $this->assertGreaterThan(now()->toDateString(), $summary['next_due_date']);

        $reply = $this->findLoan($fresh->loan_account_number);

        $this->assertStringContainsString(
            'The next payment, '.LindaWording::money($summary['next_due_amount']).', is due on '.LindaWording::date($summary['next_due_date']).'.',
            $reply->answer,
        );
        $this->assertStringNotContainsString('still unpaid', $reply->answer);
    }

    public function test_a_ranking_where_every_row_is_level_says_so(): void
    {
        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::BranchPerformance, null, null, 'Which branch collected the most?');

        $this->assertStringContainsString('both branches have ₱0.00 collected', $reply->answer);
        $this->assertStringNotContainsString('comes first', $reply->answer);
    }

    public function test_a_ranking_with_a_tie_at_the_top_names_every_tied_row(): void
    {
        Branch::factory()->create(['name' => 'Near Branch', 'code' => 'NEAR']);
        $main = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000);
        $far = $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'), 60000);
        $this->pay($main, 1500);
        $this->pay($far, 1500);

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::BranchPerformance, null, null, 'Which branch collected the most?');

        $this->assertStringContainsString('Far Branch and Main Branch are tied first with ₱1,500.00.', $reply->answer);
        $this->assertStringNotContainsString('comes first', $reply->answer);
    }

    public function test_a_ranking_of_one_row_describes_that_row(): void
    {
        $this->pay($this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000), 1500);

        $reply = $this->answerer()->answer($this->branchUser(), LindaIntent::BranchPerformance, null, null, 'Which branch collected the most?');

        $this->assertStringStartsWith($this->branch->name.', from ', $reply->answer);
        $this->assertStringContainsString('collected ₱1,500.00', $reply->answer);
        $this->assertStringNotContainsString('comes first', $reply->answer);
    }
}
