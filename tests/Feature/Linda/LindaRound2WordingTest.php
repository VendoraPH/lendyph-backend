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
 * due before today reads (and is labelled) as still unpaid, a member's loans
 * are counted as still owing, a rate reads with one decimal as the reports
 * show it, and a ranking says when rows are level or tied instead of naming
 * one of them first.
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

    /** @return array<string, mixed> label => value, over every stats block */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'stats') {
                foreach ($block['items'] as $item) {
                    $values[$item['label']] = $item['value'];
                }
            }
        }

        return $values;
    }

    /** @return list<string> the titles of the reply's first list */
    private function listTitles(LindaReply $reply): array
    {
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'list') {
                return array_column($block['items'], 'title');
            }
        }

        return [];
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

    public function test_an_instalment_due_before_today_reads_and_is_labelled_as_still_unpaid(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $late = $this->releaseTo($ana);
        $summary = app(RepaymentService::class)->getLoanSummary($late->fresh());
        $this->assertLessThan(now()->toDateString(), $summary['next_due_date']);
        $owed = LindaWording::money($summary['next_due_amount']).' was due on '.LindaWording::date($summary['next_due_date']).' and is still unpaid.';

        foreach ([$this->findLoan($late->loan_account_number), $this->findMember($ana->borrower_code)] as $reply) {
            $this->assertStringContainsString($owed, $reply->answer);
            $this->assertStringNotContainsString('The next payment', $reply->answer);

            $stats = $this->stats($reply);
            $this->assertSame($summary['next_due_date'], $stats['Unpaid Since']);
            $this->assertEquals($summary['next_due_amount'], $stats['Amount Unpaid']);
            $this->assertArrayNotHasKey('Next Due Date', $stats);
        }
    }

    public function test_an_instalment_due_later_reads_and_is_labelled_as_the_next_payment(): void
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
        $stats = $this->stats($reply);
        $this->assertSame($summary['next_due_date'], $stats['Next Due Date']);
        $this->assertEquals($summary['next_due_amount'], $stats['Next Due Amount']);
        $this->assertArrayNotHasKey('Unpaid Since', $stats);
    }

    public function test_a_member_counts_the_loans_still_owing(): void
    {
        $ana = $this->member($this->branch, 'Ana', 'Cruz');
        $this->releaseTo($ana);

        $reply = $this->findMember($ana->borrower_code);

        $this->assertStringContainsString('Ana Cruz ('.$ana->borrower_code.'), '.$this->branch->name.', has 1 loan still owing.', $reply->answer);
        $this->assertStringNotContainsString('current loan', $reply->answer);
    }

    public function test_a_rate_reads_with_one_decimal_as_the_reports_show_it(): void
    {
        $this->assertSame('54.4%', LindaWording::rate(54.35));
        $this->assertSame('0.0%', LindaWording::rate(0));
        $this->assertSame('100.0%', LindaWording::rate(100));

        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::CollectionEfficiency, null, null, 'What is our collection rate?');

        $this->assertStringContainsString(' is 0.0%: ', $reply->answer);
    }

    public function test_a_ranking_where_every_row_is_level_says_so_and_lists_the_busier_row_first(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000);

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::BranchPerformance, null, null, 'Which branch collected the most?');

        $this->assertStringContainsString('both branches have ₱0.00 collected.', $reply->answer);
        $this->assertStringNotContainsString('comes first', $reply->answer);
        $this->assertSame([$this->branch->name, $this->far->name], $this->listTitles($reply));
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

    public function test_loans_with_no_account_officer_read_in_words_on_their_own(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000);

        $reply = $this->answerer()->answer($this->branchUser(), LindaIntent::OfficerPerformance, null, null, 'Which officer collected the most?');

        $this->assertStringStartsWith('Loans with no account officer, from ', $reply->answer);
        $this->assertStringNotContainsString('Unassigned, from', $reply->answer);
    }
}
