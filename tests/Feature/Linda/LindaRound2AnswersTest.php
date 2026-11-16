<?php

namespace Tests\Feature\Linda;

use App\Models\Loan;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\LoanListTotals;
use App\Services\ReportService;

/**
 * Linda round 2's figure answers: payments (Payment History's totals),
 * collection efficiency (the Collection Efficiency report), branch and
 * officer performance (the Performance report's rows, with past due by the
 * grace rule), and report links that open on the answer's period and branch.
 */
class LindaRound2AnswersTest extends LindaAnswersTestCase
{
    private function answerer(): LindaAnswerer
    {
        return app(LindaAnswerer::class);
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /** @return array<string, mixed> */
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

    /** @return list<array<string, mixed>> */
    private function listItems(LindaReply $reply): array
    {
        foreach ($reply->blocks as $block) {
            if ($block['type'] === 'list') {
                return $block['items'];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function fields(array $item): array
    {
        return array_column($item['fields'], 'value', 'label');
    }

    private function thisMonth(): LindaPeriod
    {
        return LindaPeriod::named('this_month');
    }

    private function periodQuery(LindaPeriod $period, array $extra = []): string
    {
        return http_build_query(['from' => $period->from->toDateString(), 'to' => $period->to->toDateString(), ...$extra]);
    }

    private function officer(string $first, string $last): User
    {
        return User::factory()->inBranches($this->branch)->create(['first_name' => $first, 'last_name' => $last]);
    }

    /**
     * Main Branch: Ana (officer Maria, past grace) and Carla (no officer).
     * Far Branch: Ben (officer Jose, past grace) and Dan (officer Maria, one
     * period due yesterday, still inside its grace). Payments this month.
     *
     * @return array{maria: User, jose: User, loans: array<string, Loan>}
     */
    private function book(): array
    {
        $maria = $this->officer('Maria', 'Santos');
        $jose = $this->officer('Jose', 'Rizal');

        $loans = [
            'ana' => $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000),
            'carla' => $this->releaseTo($this->member($this->branch, 'Carla', 'Diaz'), 30000, now()->startOfMonth()->toDateString()),
            'ben' => $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'), 90000),
            'dan' => $this->releaseTo($this->member($this->far, 'Dan', 'Lim'), 40000, now()->subMonth()->subDay()->toDateString()),
        ];
        $loans['ana']->update(['account_officer_id' => $maria->id]);
        $loans['ben']->update(['account_officer_id' => $jose->id]);
        $loans['dan']->update(['account_officer_id' => $maria->id]);

        $this->pay($loans['ana'], 1500);
        $this->pay($loans['carla'], 1250.5);
        $this->pay($loans['ben'], 2500);
        $this->pay($loans['ben'], 400);

        return ['maria' => $maria, 'jose' => $jose, 'loans' => $loans];
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    public function test_payments_equal_payment_historys_totals_for_head_office_and_a_branch_user(): void
    {
        $this->book();
        $period = $this->thisMonth();

        foreach ([$this->headOffice(['payments:view', 'reports:view']), $this->branchUser(['payments:view', 'reports:view'])] as $user) {
            $history = $this->actingAs($user)->getJson('/api/repayments?status=posted&per_page=100&date_from='.$period->from->toDateString().'&date_to='.$period->to->toDateString())->assertOk();

            $reply = $this->answerer()->answer($user, LindaIntent::CollectionDetails, null, null);

            $this->assertSame('answered', $reply->outcome);
            $this->assertSame('collection_details', $reply->intent);
            $this->assertSame($history->json('meta.total'), $this->stats($reply)['Payments']);
            $this->assertEquals($history->json('meta.totals.collected'), $this->stats($reply)['Total Collected']);

            $items = $this->listItems($reply);
            $this->assertSame(array_column($history->json('data'), 'receipt_number'), array_column($items, 'title'));
            foreach ($items as $i => $item) {
                $row = $history->json('data.'.$i);
                $this->assertSame('/payments/'.$row['id'], $item['url']);
                $this->assertSame($row['borrower_name'].' ('.$row['borrower_code'].')', $item['subtitle']);
                $this->assertEquals($row['amount_paid'], $this->fields($item)['Amount']);
                $this->assertSame($row['payment_date'], $this->fields($item)['Date']);
                $this->assertSame('Cash', $this->fields($item)['Method']);
            }
            $this->assertEqualsCanonicalizing(array_values(array_unique(array_column($history->json('data'), 'loan_id'))), $reply->loanIds);
            $this->assertEqualsCanonicalizing(array_values(array_unique(array_column($history->json('data'), 'borrower_id'))), $reply->memberIds);
            $this->assertSame([['label' => 'Open Repayments List', 'url' => '/reports/repayments_list?'.$this->periodQuery($period)]], $reply->links);
        }

        $this->assertSame(4, $this->stats($this->answerer()->answer($this->headOffice(['payments:view']), LindaIntent::CollectionDetails, null, null))['Payments']);
        $this->assertSame(2, $this->stats($this->answerer()->answer($this->branchUser(['payments:view']), LindaIntent::CollectionDetails, null, null))['Payments']);
    }

    public function test_payments_for_a_named_branch_equal_that_branchs_payment_history(): void
    {
        $this->book();
        $farUser = $this->userWith(['payments:view'], $this->far);
        $history = $this->actingAs($farUser)->getJson('/api/repayments?status=posted&date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString())->assertOk();

        $reply = $this->answerer()->answer($this->headOffice(['payments:view', 'reports:view']), LindaIntent::CollectionDetails, null, 'Far');

        $this->assertSame($history->json('meta.total'), $this->stats($reply)['Payments']);
        $this->assertEquals($history->json('meta.totals.collected'), $this->stats($reply)['Total Collected']);
        $this->assertSame('/reports/repayments_list?'.$this->periodQuery($this->thisMonth(), ['branch' => $this->far->id]), $reply->links[0]['url']);
    }

    public function test_the_payment_list_is_capped_at_ten_and_needs_payments_view(): void
    {
        $loan = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'));
        for ($i = 0; $i < 12; $i++) {
            $this->pay($loan, 100);
        }

        $reply = $this->answerer()->answer($this->branchUser(['payments:view']), LindaIntent::CollectionDetails, null, null);
        $this->assertSame(12, $this->stats($reply)['Payments']);
        $this->assertCount(10, $this->listItems($reply));
        $this->assertSame([], $reply->links);

        $denied = $this->answerer()->answer($this->branchUser(['reports:view']), LindaIntent::CollectionDetails, null, null);
        $this->assertSame('denied', $denied->outcome);
        $this->assertSame("Your role doesn't have access to the payment details. Please ask your administrator if you need them.", $denied->answer);
    }

    // -----------------------------------------------------------------
    // Collection efficiency
    // -----------------------------------------------------------------

    public function test_collection_efficiency_equals_the_report_for_each_user(): void
    {
        $this->book();
        $period = LindaPeriod::named('last_month');

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            foreach ([null, $period] as $asked) {
                $shown = $asked ?? $this->thisMonth();
                $report = $this->reports()->collectionEfficiency($this->scopeOf($user, [
                    'date_from' => $shown->from->toDateString(),
                    'date_to' => $shown->to->toDateString(),
                ]));

                $reply = $this->answerer()->answer($user, LindaIntent::CollectionEfficiency, $asked, null);

                $this->assertSame('answered', $reply->outcome);
                $this->assertEquals([
                    'Collection Rate' => $report['collection_rate'],
                    'Total Due' => $report['total_due'],
                    'Collected (Principal + Interest)' => $report['total_collected'],
                    'Uncollected' => $report['uncollected'],
                ], $this->stats($reply));
                $this->assertSame([['label' => 'Open Collection Efficiency', 'url' => '/reports/collection_efficiency?'.$this->periodQuery($shown)]], $reply->links);
            }
        }

        $this->assertSame('denied', $this->answerer()->answer($this->branchUser(['loans:view']), LindaIntent::CollectionEfficiency, null, null)->outcome);
    }

    // -----------------------------------------------------------------
    // Branch and officer performance
    // -----------------------------------------------------------------

    public function test_branch_performance_equals_the_report_rows_with_past_due_by_the_grace_rule(): void
    {
        $this->book();
        $period = $this->thisMonth();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $report = $this->reports()->performance($this->scopeOf($user, [
                'date_from' => $period->from->toDateString(),
                'date_to' => $period->to->toDateString(),
            ]));

            $reply = $this->answerer()->answer($user, LindaIntent::BranchPerformance, null, null, 'Which branch collected the most?');

            $this->assertSame('answered', $reply->outcome);
            $items = $this->listItems($reply);
            $this->assertCount(count($report['by_branch']), $items);

            foreach ($report['by_branch'] as $row) {
                $item = collect($items)->firstWhere('title', $row['branch_name']);
                $pastDue = $this->reports()->overdueMembers(['branch_ids' => [$row['branch_id']]])['past_due_amount'];
                $this->assertEquals([
                    'Released' => $row['released_count'],
                    'Released Amount' => $row['released_amount'],
                    'Collected' => $row['collected'],
                    'Outstanding' => $row['total_outstanding'],
                    'Past Due' => (float) $pastDue,
                ], $this->fields($item));
            }

            $collected = array_map(fn (array $item) => $this->fields($item)['Collected'], $items);
            $sorted = $collected;
            rsort($sorted);
            $this->assertSame($sorted, $collected);
            $this->assertSame([['label' => 'Open Officer / Branch Performance', 'url' => '/reports/performance?'.$this->periodQuery($period)]], $reply->links);
            $this->assertEquals($this->reports()->overdueMembers($this->scopeOf($user))['past_due_amount'], $this->stats($reply)['Past Due']);
        }
    }

    public function test_the_grace_rule_leaves_out_a_period_still_inside_its_grace(): void
    {
        $book = $this->book();
        $dan = $book['loans']['dan'];

        $performanceFar = collect($this->reports()->performance(['branch_ids' => null])['by_branch'])->firstWhere('branch_id', $this->far->id);
        $graceFar = app(LoanListTotals::class)->for(Loan::query()->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)->where('loans.branch_id', $this->far->id));
        $danOnly = app(LoanListTotals::class)->for(Loan::query()->whereKey($dan->id));

        // The report's own figure counts Dan's period due yesterday; Linda's doesn't.
        $this->assertEquals(0, $danOnly['overdue_amount']);
        $this->assertGreaterThan($graceFar['overdue_amount'], $performanceFar['overdue_amount']);

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::BranchPerformance, null, null, 'Which branch has the most overdue?');
        $far = collect($this->listItems($reply))->firstWhere('title', $this->far->name);
        $this->assertEquals($graceFar['overdue_amount'], $this->fields($far)['Past Due']);
    }

    public function test_branches_rank_by_the_words_of_the_question(): void
    {
        $this->book();
        $user = $this->headOffice();

        $cases = [
            'Which branch released the most?' => 'Released Amount',
            'Aling branch ang may pinakamaraming atraso?' => 'Past Due',
            'Which branch has the most outstanding?' => 'Outstanding',
            'Compare our branches' => 'Collected',
        ];

        foreach ($cases as $question => $label) {
            $reply = $this->answerer()->answer($user, LindaIntent::BranchPerformance, null, null, $question);
            $values = array_map(fn (array $item) => $this->fields($item)[$label], $this->listItems($reply));
            $sorted = $values;
            rsort($sorted);
            $this->assertSame($sorted, $values, $question);
            $this->assertStringContainsString(strtolower($label === 'Released Amount' ? 'released' : $label), strtolower($reply->answer), $question);
        }
    }

    public function test_officer_performance_equals_the_report_rows_with_unassigned_and_past_due_by_grace(): void
    {
        $this->book();
        $period = $this->thisMonth();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $report = $this->reports()->performance($this->scopeOf($user, [
                'date_from' => $period->from->toDateString(),
                'date_to' => $period->to->toDateString(),
            ]));

            $reply = $this->answerer()->answer($user, LindaIntent::OfficerPerformance, null, null, 'How are our officers doing?');
            $items = $this->listItems($reply);
            $this->assertCount(count($report['by_officer']), $items);
            $this->assertContains('Unassigned', array_column($items, 'title'));

            $pastDueSum = 0.0;
            foreach ($report['by_officer'] as $row) {
                $item = collect($items)->firstWhere('title', $row['account_officer_name']);
                $grace = app(LoanListTotals::class)->for(
                    Loan::visibleTo($user)
                        ->whereIn('loans.status', Loan::COLLECTIBLE_STATUSES)
                        ->when($row['account_officer_id'] === null, fn ($q) => $q->whereNull('loans.account_officer_id'), fn ($q) => $q->where('loans.account_officer_id', $row['account_officer_id'])),
                )['overdue_amount'];
                $pastDueSum += $grace;

                $this->assertEquals([
                    'Released' => $row['released_count'],
                    'Released Amount' => $row['released_amount'],
                    'Collected' => $row['collected'],
                    'Outstanding' => $row['total_outstanding'],
                    'Past Due' => $grace,
                ], $this->fields($item), $row['account_officer_name']);
            }

            $this->assertEquals((float) $this->reports()->overdueMembers($this->scopeOf($user))['past_due_amount'], round($pastDueSum, 2));
        }

        $this->assertSame('denied', $this->answerer()->answer($this->branchUser(['loans:view']), LindaIntent::OfficerPerformance, null, null)->outcome);
        $this->assertSame('denied', $this->answerer()->answer($this->branchUser(['loans:view']), LindaIntent::BranchPerformance, null, null)->outcome);
    }

    public function test_a_named_officer_is_shown_alone_and_matched_only_among_the_reports_officers(): void
    {
        $this->book();
        $stranger = $this->officer('Pedro', 'Penduko');

        foreach (['How is officer Maria Santos doing?', 'Kumusta si Maria?', 'Performance ni Santos'] as $question) {
            $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::OfficerPerformance, null, null, $question);
            $this->assertSame(['Maria Santos'], array_column($this->listItems($reply), 'title'), $question);
        }

        // An officer with no loans isn't among the rows, so the whole list answers.
        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::OfficerPerformance, null, null, 'How is officer '.$stranger->first_name.' '.$stranger->last_name.' doing?');
        $this->assertGreaterThan(1, count($this->listItems($reply)));

        // The branch user's rows don't carry Far Branch's officer.
        $reply = $this->answerer()->answer($this->branchUser(), LindaIntent::OfficerPerformance, null, null, 'How is officer Jose Rizal doing?');
        $this->assertNotContains('Jose Rizal', array_column($this->listItems($reply), 'title'));
    }

    public function test_the_ranking_lists_at_most_ten_rows(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $officer = $this->officer('Officer'.chr(65 + $i), 'Test');
            $loan = $this->releaseTo($this->member($this->branch, 'Mem'.chr(65 + $i), 'Ber'), 10000 + $i * 1000);
            $loan->update(['account_officer_id' => $officer->id]);
        }

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::OfficerPerformance, null, null, 'Which officer released the most?');

        $this->assertCount(10, $this->listItems($reply));
        $this->assertStringContainsString('top 10', $reply->answer);
    }

    // -----------------------------------------------------------------
    // Links
    // -----------------------------------------------------------------

    public function test_report_links_carry_the_period_and_the_named_branch(): void
    {
        $this->book();
        $user = $this->headOffice();
        $period = LindaPeriod::named('last_month');

        $collections = $this->answerer()->answer($user, LindaIntent::CollectionSummary, $period, 'Far');
        $disbursements = $this->answerer()->answer($user, LindaIntent::DisbursementSummary, null, null);
        $overdue = $this->answerer()->answer($user, LindaIntent::OverdueAccounts, null, 'Far');

        $this->assertSame('/reports/daily_collection?'.$this->periodQuery($period, ['branch' => $this->far->id]), $collections->links[0]['url']);
        $this->assertSame('/reports/disbursement_report?'.$this->periodQuery($this->thisMonth()), $disbursements->links[0]['url']);
        // A page with no period controls keeps its plain link.
        $this->assertSame('/loans/past-due', $overdue->links[0]['url']);
    }

    // -----------------------------------------------------------------
    // Wording
    // -----------------------------------------------------------------

    public function test_the_not_yet_and_unmatched_replies_list_what_round_2_answers(): void
    {
        foreach ([$this->answerer()->notYet(LindaIntent::IncomeSummary)->answer, $this->answerer()->unmatched()->answer] as $text) {
            foreach (['payments', 'collection efficiency', 'branch and officer performance', 'a member, a loan or a receipt'] as $phrase) {
                $this->assertStringContainsString($phrase, $text);
            }
        }

        // What round 2 answers is never "not yet" any more.
        foreach ([LindaIntent::CollectionDetails, LindaIntent::CollectionEfficiency, LindaIntent::BranchPerformance, LindaIntent::OfficerPerformance] as $intent) {
            $this->assertNotSame('not_yet', $this->answerer()->answer($this->headOffice(['reports:view', 'payments:view']), $intent, null, null)->outcome, $intent->value);
        }
    }
}
