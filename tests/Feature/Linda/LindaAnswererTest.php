<?php

namespace Tests\Feature\Linda;

use App\Models\Branch;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Linda's five answers read the reports' own figures, for the caller's own
 * branches and permissions, and word them in simple English with ₱ and
 * Manila dates.
 */
class LindaAnswererTest extends LindaAnswersTestCase
{
    private function answerer(): LindaAnswerer
    {
        return app(LindaAnswerer::class);
    }

    private function reports(): ReportService
    {
        return app(ReportService::class);
    }

    /**
     * Two members in Main Branch and one in Far Branch, each with a period
     * past grace, released and paid this month.
     */
    private function book(): void
    {
        $ana = $this->releaseTo($this->member($this->branch, 'Ana', 'Cruz'), 60000);
        $carla = $this->releaseTo($this->member($this->branch, 'Carla', 'Diaz'), 30000);
        $ben = $this->releaseTo($this->member($this->far, 'Ben', 'Reyes'), 90000);

        $this->pay($ana, 1500);
        $this->pay($carla, 1250.5);
        $this->pay($ben, 2500);

        // One loan of each branch released this month, for the disbursements.
        $this->releaseTo($this->member($this->branch, 'Eva', 'Tan'), 20000, now()->startOfMonth()->toDateString());
        $this->releaseTo($this->member($this->far, 'Gil', 'Ong'), 25000, now()->startOfMonth()->toDateString());
    }

    /**
     * @return array<string, int|float|string|null> label => value, over every stats block
     */
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

    /** @return list<string> */
    private function linkUrls(LindaReply $reply): array
    {
        return array_column($reply->links, 'url');
    }

    private function thisMonth(): LindaPeriod
    {
        return LindaPeriod::named('this_month');
    }

    // -----------------------------------------------------------------
    // The figures equal the reports'
    // -----------------------------------------------------------------

    public function test_collections_equal_daily_collection_for_head_office_and_a_branch_user(): void
    {
        $this->book();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $period = $this->thisMonth();
            $report = $this->reports()->dailyCollection($this->scopeOf($user, [
                'date_from' => $period->from->toDateString(),
                'date_to' => $period->to->toDateString(),
            ]));

            $reply = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, null);

            $this->assertSame('answered', $reply->outcome);
            $this->assertSame('collection_summary', $reply->intent);
            $this->assertEquals([
                'Total Received' => $report['total_received'],
                'Collected (Principal + Interest)' => $report['total_collected'],
                'Penalty Collected' => $report['penalty_collected'],
                'Payments' => $report['payment_count'],
            ], $this->stats($reply));
            $this->assertSame(['/reports/daily_collection'], $this->linkUrls($reply));
            $this->assertSame('Open Daily Collection Report', $reply->links[0]['label']);
            $this->assertNotNull($reply->answerFunction);
            $this->assertStringContainsString($period->label, $reply->answer);
        }

        $this->assertSame(3, $this->stats($this->answerer()->answer($this->headOffice(), LindaIntent::CollectionSummary, null, null))['Payments']);
        $this->assertSame(2, $this->stats($this->answerer()->answer($this->branchUser(), LindaIntent::CollectionSummary, null, null))['Payments']);
    }

    public function test_disbursements_equal_the_disbursement_summary_and_the_releases_list(): void
    {
        $this->book();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $period = $this->thisMonth();
            $filters = $this->scopeOf($user, ['date_from' => $period->from->toDateString(), 'date_to' => $period->to->toDateString()]);
            $report = $this->reports()->disbursementReport($filters);
            $releases = $this->reports()->listOfReleasesTotals($filters);

            $reply = $this->answerer()->answer($user, LindaIntent::DisbursementSummary, $period, null);

            $this->assertEquals([
                'Net Disbursed' => $report['total_disbursed'],
                'Loans Released' => $report['loans_released'],
                'Principal Released' => $releases['total_principal'],
            ], $this->stats($reply));
            $this->assertSame(['/reports/disbursement_report'], $this->linkUrls($reply));
        }

        $this->assertSame(2, $this->stats($this->answerer()->answer($this->headOffice(), LindaIntent::DisbursementSummary, null, null))['Loans Released']);
        $this->assertSame(1, $this->stats($this->answerer()->answer($this->branchUser(), LindaIntent::DisbursementSummary, null, null))['Loans Released']);
    }

    public function test_active_loans_equal_the_portfolio_summary_with_each_branch(): void
    {
        $this->book();

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::LoanSummary, null, null);
        $summary = $this->reports()->loanBalanceSummary(['branch_ids' => null]);

        $this->assertSame(['Active Loans' => $summary['active_loans']], $this->stats($reply));
        $this->assertSame(5, $summary['active_loans']);
        $byBranch = [];
        foreach ($this->listItems($reply) as $item) {
            $byBranch[$item['title']] = $item['fields'][0]['value'];
        }
        $this->assertSame(3, $byBranch['Main Branch']);
        $this->assertSame(2, $byBranch['Far Branch']);
        $this->assertSame($summary['active_loans'], array_sum($byBranch));
        $this->assertSame(['/loans?tab=active'], $this->linkUrls($reply));

        $branch = $this->answerer()->answer($this->branchUser(), LindaIntent::LoanSummary, null, null);
        $this->assertSame(['Active Loans' => 3], $this->stats($branch));
        $this->assertStringNotContainsString('Far Branch', json_encode($branch->toArray()));
    }

    public function test_overdue_lists_the_members_of_the_past_due_grouping_with_the_portfolio_total(): void
    {
        $this->book();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $figures = $this->reports()->overdueMembers($this->scopeOf($user));

            $reply = $this->answerer()->answer($user, LindaIntent::OverdueAccounts, null, null);

            $this->assertEquals([
                'Members With Past Due' => $figures['member_count'],
                'Total Past Due' => (float) $figures['past_due_amount'],
            ], $this->stats($reply));
            $this->assertSame(array_column($figures['members'], 'name'), array_column($this->listItems($reply), 'title'));
            $this->assertSame(array_column($figures['members'], 'code'), array_column($this->listItems($reply), 'subtitle'));
            $this->assertSame(array_column($figures['members'], 'borrower_id'), $reply->memberIds);
            $first = $this->listItems($reply)[0]['fields'];
            $this->assertSame(['Past Due', 'Days Overdue', 'Loans'], array_column($first, 'label'));
            $this->assertEquals((float) $figures['members'][0]['past_due_amount'], $first[0]['value']);
            $this->assertSame('currency', $first[0]['format']);
            $this->assertSame('PHP', $first[0]['currency']);
            $this->assertSame($figures['members'][0]['days_overdue'], $first[1]['value']);
            $this->assertSame(['/loans/past-due'], $this->linkUrls($reply));
        }

        $this->assertSame(2, $this->stats($this->answerer()->answer($this->branchUser(), LindaIntent::OverdueAccounts, null, null))['Members With Past Due']);
    }

    public function test_the_lending_summary_reads_each_report_for_the_period_and_today(): void
    {
        $this->book();

        foreach ([$this->headOffice(), $this->branchUser()] as $user) {
            $period = $this->thisMonth();
            $dated = $this->scopeOf($user, ['date_from' => $period->from->toDateString(), 'date_to' => $period->to->toDateString()]);
            $summary = $this->reports()->loanBalanceSummary($this->scopeOf($user));
            $overdue = $this->reports()->overdueMembers($this->scopeOf($user));

            $reply = $this->answerer()->answer($user, LindaIntent::GeneralLendingSummary, null, null);

            $this->assertEquals([
                'Total Received' => $this->reports()->dailyCollection($dated)['total_received'],
                'Net Disbursed' => $this->reports()->disbursementReport($dated)['total_disbursed'],
                'Active Loans' => $summary['active_loans'],
                'Outstanding' => $summary['total_outstanding'],
                'Total Past Due' => (float) $overdue['past_due_amount'],
                'Members With Past Due' => $overdue['member_count'],
            ], $this->stats($reply));
            $this->assertSame(['/reports/portfolio_summary'], $this->linkUrls($reply));
            $this->assertSame([], $reply->memberIds);
        }
    }

    // -----------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------

    public function test_each_answer_needs_the_permission_of_the_screen_it_mirrors(): void
    {
        $this->book();

        $cases = [
            [LindaIntent::CollectionSummary, ['loans:view', 'borrowers:view'], ['reports:view']],
            [LindaIntent::DisbursementSummary, ['loans:view'], ['reports:view']],
            [LindaIntent::GeneralLendingSummary, ['loans:view'], ['reports:view']],
            [LindaIntent::OverdueAccounts, ['reports:view'], ['reports:view', 'loans:view']],
            [LindaIntent::OverdueAccounts, ['loans:view'], ['reports:view', 'loans:view']],
            [LindaIntent::LoanSummary, ['reports:view'], ['loans:view']],
        ];

        foreach ($cases as [$intent, $without, $with]) {
            $denied = $this->answerer()->answer($this->branchUser($without), $intent, null, null);

            $this->assertSame('denied', $denied->outcome, $intent->value);
            $this->assertSame($intent->value, $denied->intent);
            $this->assertSame([], $denied->blocks);
            $this->assertSame([], $denied->links);
            $this->assertSame([], $denied->memberIds);
            $this->assertDoesNotMatchRegularExpression('/\d/', $denied->answer, 'a refusal carries no figures');

            $this->assertSame('answered', $this->answerer()->answer($this->branchUser($with), $intent, null, null)->outcome, $intent->value);
        }
    }

    public function test_the_summary_leaves_out_its_past_due_lines_without_loans_view(): void
    {
        $this->book();

        $reply = $this->answerer()->answer($this->branchUser(['reports:view']), LindaIntent::GeneralLendingSummary, null, null);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame(['Total Received', 'Net Disbursed', 'Active Loans', 'Outstanding'], array_keys($this->stats($reply)));
        $this->assertStringNotContainsStringIgnoringCase('past due', $reply->answer);
    }

    public function test_the_overdue_list_is_capped_with_a_link_to_the_whole_page(): void
    {
        $this->book();
        config()->set('linda.list_max', 2);

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::OverdueAccounts, null, null);

        $this->assertCount(2, $this->listItems($reply));
        $this->assertCount(2, $reply->memberIds);
        $this->assertSame(3, $this->stats($reply)['Members With Past Due']);
        $this->assertSame(['/loans/past-due'], $this->linkUrls($reply));
        $this->assertStringContainsString('Past Due Loans', $reply->answer);
    }

    public function test_a_member_links_to_their_page_only_with_borrowers_view_and_their_branch(): void
    {
        $this->book();

        $full = $this->listItems($this->answerer()->answer($this->headOffice(), LindaIntent::OverdueAccounts, null, null));
        foreach ($full as $item) {
            $this->assertMatchesRegularExpression('#^/borrowers/\d+$#', $item['url']);
        }

        $noMembers = $this->listItems($this->answerer()->answer($this->headOffice(['reports:view', 'loans:view']), LindaIntent::OverdueAccounts, null, null));
        $this->assertCount(3, $noMembers);
        foreach ($noMembers as $item) {
            $this->assertArrayNotHasKey('url', $item);
        }

        // A Main Branch loan whose member was moved to Far Branch: listed (the
        // loan is in the user's branch), but the member's page isn't theirs.
        $moved = $this->member($this->far, 'Hana', 'Sy');
        $loan = $this->releaseTo($moved, 500000);
        $loan->update(['branch_id' => $this->branch->id]);

        $items = $this->listItems($this->answerer()->answer($this->branchUser(), LindaIntent::OverdueAccounts, null, null));
        $hana = collect($items)->firstWhere('title', 'Hana Sy');
        $this->assertNotNull($hana);
        $this->assertArrayNotHasKey('url', $hana);
        $ana = collect($items)->firstWhere('title', 'Ana Cruz');
        $this->assertArrayHasKey('url', $ana);
    }

    public function test_a_user_with_no_branch_and_no_view_all_gets_no_figures(): void
    {
        $this->book();
        $user = $this->userWith(['reports:view', 'loans:view']);

        $reply = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, null);

        $this->assertSame('denied', $reply->outcome);
        $this->assertSame([], $reply->blocks);
        $this->assertSame([], $reply->links);
        $this->assertStringContainsString('No branch is assigned', $reply->answer);
    }

    // -----------------------------------------------------------------
    // Branches
    // -----------------------------------------------------------------

    public function test_a_named_branch_narrows_the_answer_for_head_office(): void
    {
        $this->book();
        $user = $this->headOffice();

        $reply = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, 'far');
        $period = $this->thisMonth();
        $report = $this->reports()->dailyCollection([
            'branch_ids' => null, 'branch_id' => $this->far->id,
            'date_from' => $period->from->toDateString(), 'date_to' => $period->to->toDateString(),
        ]);

        $this->assertSame('answered', $reply->outcome);
        $this->assertEquals($report['total_received'], $this->stats($reply)['Total Received']);
        $this->assertSame(1, $this->stats($reply)['Payments']);
        $this->assertStringContainsString('Far Branch', $reply->answer);
    }

    public function test_a_branch_user_never_reaches_another_branch_by_naming_it(): void
    {
        $this->book();
        $user = $this->branchUser();

        $far = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, 'Far Branch');
        $missing = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, 'Nowhere Branch');

        $this->assertSame('no_branch', $far->outcome);
        $this->assertSame([], $far->blocks);
        $this->assertSame([], $far->links);
        $this->assertSame(
            str_replace('Nowhere Branch', 'X', $missing->answer),
            str_replace('Far Branch', 'X', $far->answer),
            'a hidden branch reads exactly like a missing one',
        );
        $this->assertStringContainsString('not among your branches', $far->answer);

        foreach (LindaIntent::cases() as $intent) {
            $this->assertStringNotContainsString('2,500.00', $this->answerer()->answer($user, $intent, null, 'far')->answer);
        }
    }

    public function test_a_name_that_fits_several_branches_asks_which(): void
    {
        Branch::factory()->create(['name' => 'North Branch A', 'code' => 'NA']);
        Branch::factory()->create(['name' => 'North Branch B', 'code' => 'NB']);

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::CollectionSummary, null, 'north');

        $this->assertSame('ambiguous_branch', $reply->outcome);
        $this->assertStringContainsString('North Branch A', $reply->answer);
        $this->assertStringContainsString('North Branch B', $reply->answer);
        $this->assertSame([], $reply->blocks);
    }

    // -----------------------------------------------------------------
    // Periods and wording
    // -----------------------------------------------------------------

    public function test_overdue_and_active_loans_are_as_of_today_whatever_period_is_asked(): void
    {
        $this->book();
        $user = $this->headOffice();
        $today = LindaPeriod::today()->format('F j, Y');

        foreach ([LindaIntent::OverdueAccounts, LindaIntent::LoanSummary] as $intent) {
            $plain = $this->answerer()->answer($user, $intent, null, null);
            $lastMonth = $this->answerer()->answer($user, $intent, LindaPeriod::named('last_month'), null);

            $this->assertStringContainsString("as of today, {$today}", strtolower($plain->answer[0]).substr($plain->answer, 1));
            $this->assertSame($this->stats($plain), $this->stats($lastMonth));
            $this->assertStringContainsString('as of today', $lastMonth->answer);
        }
    }

    public function test_money_reads_in_pesos_with_two_decimals(): void
    {
        $this->book();

        $reply = $this->answerer()->answer($this->branchUser(), LindaIntent::CollectionSummary, null, null);

        $this->assertStringContainsString('₱2,750.50', $reply->answer);
        $this->assertStringNotContainsString('$', $reply->answer);
        $this->assertStringContainsString('2 payments', $reply->answer);
    }

    public function test_an_empty_result_says_so_in_words(): void
    {
        $this->travelToManila('2026-11-16 10:00');
        $user = $this->headOffice();

        $collections = $this->answerer()->answer($user, LindaIntent::CollectionSummary, null, null);
        $this->assertSame('answered', $collections->outcome);
        $this->assertStringContainsString('No payments were received from November 1 to 16, 2026.', $collections->answer);

        $today = $this->answerer()->answer($user, LindaIntent::CollectionSummary, LindaPeriod::named('today'), null);
        $this->assertStringContainsString('No payments were received on November 16, 2026.', $today->answer);

        $this->assertStringContainsString(
            'No loans were released from November 1 to 16, 2026.',
            $this->answerer()->answer($user, LindaIntent::DisbursementSummary, null, null)->answer,
        );
        $this->assertStringContainsString(
            'There are no active loans',
            $this->answerer()->answer($user, LindaIntent::LoanSummary, null, null)->answer,
        );
        $overdue = $this->answerer()->answer($user, LindaIntent::OverdueAccounts, null, null);
        $this->assertStringContainsString('No members have past due payments', $overdue->answer);
        $this->assertSame([], $this->listItems($overdue));
        $this->assertStringContainsString(
            'There was no lending activity from November 1 to 16, 2026.',
            $this->answerer()->answer($user, LindaIntent::GeneralLendingSummary, null, null)->answer,
        );
    }

    // -----------------------------------------------------------------
    // Other intents and fixed replies
    // -----------------------------------------------------------------

    public function test_an_intent_linda_cant_answer_yet_links_to_its_report_when_the_user_can_open_it(): void
    {
        $reports = $this->answerer()->answer($this->branchUser(), LindaIntent::IncomeSummary, null, null);
        $this->assertSame('not_yet', $reports->outcome);
        $this->assertSame('income_summary', $reports->intent);
        $this->assertSame([['label' => 'Open Income Report', 'url' => '/reports/income_report']], $reports->links);
        $this->assertSame([], $reports->blocks);

        $this->assertSame(['/reports/collection_efficiency'], $this->linkUrls($this->answerer()->answer($this->branchUser(), LindaIntent::CollectionEfficiency, null, null)));
        $this->assertSame(['/reports/portfolio_summary'], $this->linkUrls($this->answerer()->answer($this->branchUser(), LindaIntent::PortfolioSummary, null, null)));

        $noReports = $this->answerer()->answer($this->branchUser(['loans:view']), LindaIntent::IncomeSummary, null, null);
        $this->assertSame('not_yet', $noReports->outcome);
        $this->assertSame([], $noReports->links);

        $lookup = $this->answerer()->answer($this->branchUser(), LindaIntent::BorrowerLookup, null, null);
        $this->assertSame('not_yet', $lookup->outcome);
        $this->assertSame([], $lookup->links);

        $this->assertSame([], $this->answerer()->notYet(LindaIntent::IncomeSummary)->links);
    }

    public function test_an_unsupported_question_gets_the_fixed_refusal(): void
    {
        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::UnsupportedQuestion, null, null);

        $this->assertSame('unsupported', $reply->outcome);
        $this->assertSame('unsupported_question', $reply->intent);
        $this->assertSame($this->answerer()->unsupported()->answer, $reply->answer);
        $this->assertSame([], $reply->blocks);
        $this->assertSame([], $reply->links);
    }

    public function test_the_fixed_replies(): void
    {
        $answerer = $this->answerer();

        $unmatched = $answerer->unmatched();
        $this->assertSame('unmatched', $unmatched->outcome);
        foreach ([
            'How much have we collected this month?',
            'Which members currently have overdue payments?',
            'How many active loans do we have?',
            'How much have we disbursed this month?',
            'Give me a summary of our lending operation this month.',
        ] as $question) {
            $this->assertStringContainsString($question, $unmatched->answer);
        }

        $this->assertSame('unclear_period', $answerer->unclearPeriod()->outcome);
        $this->assertSame('period_refused', $answerer->periodRefused('future')->outcome);
        $this->assertStringContainsString('future', $answerer->periodRefused('future')->answer);
        $this->assertStringContainsString('10 years', $answerer->periodRefused('over_10_years')->answer);
        $this->assertSame('unsupported', $answerer->unsupported()->outcome);
        $this->assertSame('no_branch', $answerer->branchNotFound('Sur')->outcome);
        $this->assertStringContainsString('Sur', $answerer->branchNotFound('Sur')->answer);
        $this->assertSame('ambiguous_branch', $answerer->branchAmbiguous(['A Branch', 'B Branch'])->outcome);
        $this->assertSame('unmatched', $answerer->aiOffForNow()->outcome);
        $this->assertStringContainsString('How many active loans do we have?', $answerer->aiOffForNow()->answer);

        foreach ([$unmatched, $answerer->unclearPeriod(), $answerer->periodRefused('future'), $answerer->unsupported(),
            $answerer->notYet(LindaIntent::LoanDetails), $answerer->branchNotFound('Sur'), $answerer->branchAmbiguous(['A', 'B']), $answerer->aiOffForNow()] as $reply) {
            $this->assertNotSame('', trim($reply->answer));
            $this->assertStringNotContainsStringIgnoringCase('borrower', $reply->answer);
            $this->assertStringNotContainsString('$', $reply->answer);
            $this->assertSame([], $reply->memberIds);
        }
    }

    public function test_to_array_is_the_frontends_reply_with_a_manila_as_of(): void
    {
        $this->book();

        $reply = $this->answerer()->answer($this->headOffice(), LindaIntent::OverdueAccounts, null, null);
        $array = $reply->toArray();

        $this->assertSame(['intent', 'answer', 'blocks', 'links', 'as_of'], array_keys($array));
        $this->assertSame('overdue_accounts', $array['intent']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00$/', $array['as_of']);
        $this->assertSame(['stats', 'list'], array_column($array['blocks'], 'type'));
        $this->assertTrue($array['blocks'][1]['ordered']);
        foreach ($array['links'] as $link) {
            $this->assertSame(['label', 'url'], array_keys($link));
        }
        $this->assertSame(['label', 'value', 'format', 'currency'], array_keys($array['blocks'][0]['items'][1]));
        $this->assertSame(['label', 'value', 'format'], array_keys($array['blocks'][0]['items'][0]));
        $this->assertNotFalse(json_encode($array, JSON_THROW_ON_ERROR));
    }

    private function travelToManila(string $dateTime): void
    {
        $instant = CarbonImmutable::parse($dateTime, 'Asia/Manila');
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
