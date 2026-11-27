<?php

namespace Tests\Feature\Linda;

use App\Models\Repayment;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use App\Services\ReportService;

/**
 * Money review L1 and L5: `payment_count` counts only the receipts that add
 * to `total_received`, and a period with no receipts but principal and
 * interest already paid on its due periods says so in words.
 */
class LindaReviewFiguresTest extends LindaAnswersTestCase
{
    public function test_a_receipt_that_only_paid_the_insurance_premium_isnt_counted(): void
    {
        $loan = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $this->pay($loan, 1500);
        Repayment::factory()->create([
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount_paid' => 500,
            'insurance_applied' => 500,
            'principal_applied' => 0,
            'interest_applied' => 0,
            'penalty_applied' => 0,
            'received_by' => $this->admin->id,
        ]);
        $today = now()->toDateString();

        $daily = app(ReportService::class)->dailyCollection(['branch_ids' => null, 'date_from' => $today, 'date_to' => $today]);
        $efficiency = app(ReportService::class)->collectionEfficiency(['branch_ids' => null, 'date_from' => $today, 'date_to' => $today]);

        $this->assertEquals(1500, $daily['total_received']);
        $this->assertSame(1, $daily['payment_count']);
        $this->assertSame(1, $efficiency['payment_count']);
    }

    public function test_a_period_with_no_receipts_but_principal_and_interest_paid_earlier_says_so(): void
    {
        // The first instalment falls due today and was paid yesterday.
        $loan = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000, now()->subMonthNoOverflow()->toDateString());
        $this->pay($loan, 11800, now()->subDay()->toDateString());
        $today = LindaPeriod::named('today');
        $report = app(ReportService::class)->dailyCollection(['branch_ids' => null, 'date_from' => $today->from->toDateString(), 'date_to' => $today->to->toDateString()]);

        $this->assertSame(0, $report['payment_count']);
        $this->assertGreaterThan(0, (float) $report['total_collected']);

        $reply = app(LindaAnswerer::class)->answer($this->headOffice(), LindaIntent::CollectionSummary, $today, null);

        $this->assertStringStartsWith('No payments were received '.LindaWording::during($today).'; ', $reply->answer);
        $this->assertStringContainsString(
            LindaWording::money($report['total_collected']).' of principal and interest due in that period was paid earlier.',
            $reply->answer,
        );
        $stats = collect($reply->blocks[0]['items'])->pluck('value', 'label');
        $this->assertEquals((float) $report['total_collected'], $stats['Collected (Principal + Interest)']);
        $this->assertSame(0, $stats['Payments']);
    }

    public function test_a_period_with_nothing_received_or_paid_keeps_its_plain_sentence(): void
    {
        $today = LindaPeriod::named('today');

        $reply = app(LindaAnswerer::class)->answer($this->headOffice(), LindaIntent::CollectionSummary, $today, null);

        $this->assertSame('No payments were received '.LindaWording::during($today).'. This covers all branches.', $reply->answer);
    }

    public function test_answers_read_across_all_branches_or_for_a_named_branch(): void
    {
        $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        $answerer = app(LindaAnswerer::class);

        // Round 4 adds the Outstanding sentence after the count's.
        $this->assertStringContainsString('1 active loan across all branches.', $answerer->answer($this->headOffice(), LindaIntent::LoanSummary, null, null)->answer);
        $this->assertStringContainsString('1 active loan across all your branches.', $answerer->answer($this->userWith(['reports:view', 'loans:view'], $this->branch, $this->far), LindaIntent::LoanSummary, null, null)->answer);
        $this->assertStringContainsString('1 active loan for Main Branch.', $answerer->answer($this->headOffice(), LindaIntent::LoanSummary, null, 'main')->answer);
    }
}
