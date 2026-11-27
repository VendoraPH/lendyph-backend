<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaFacts;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;
use App\Services\Linda\LindaWording as W;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Insight\InsightHistory;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Linda round 3, savings questions: the Savings dashboard's figures as of
 * today (SavingsDashboard::build(), GET /savings/dashboard), on the
 * dashboard's own history, in the caller's branches, with `savings:view`.
 */
class LindaSavingsAnswerTest extends TestCase
{
    use InsightHistory, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->buildHistory();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(User $user, ?string $branch = null): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, LindaIntent::SavingsSummary, null, $branch, 'How much savings do we have?');
    }

    /** @return array<string, mixed> */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'stats' ? $block['items'] : [] as $item) {
                $values[$item['label']] = $item['value'];
            }
        }

        return $values;
    }

    /** @param  array<string, mixed>  $kpis */
    private function assertMatchesKpis(array $kpis, LindaReply $reply): void
    {
        $stats = $this->stats($reply);
        $this->assertEquals((float) $kpis['total_deposits'], $stats['Total Deposits']);
        $this->assertEquals((float) $kpis['savings_balance'], $stats['Savings Balance']);
        $this->assertSame($kpis['time_deposits']['count'], $stats['Time Deposits']);
        $this->assertEquals((float) $kpis['time_deposits']['amount'], $stats['Time Deposits (₱)']);
        $this->assertSame($kpis['accounts']['active'], $stats['Active Accounts']);
        $this->assertSame($kpis['accounts']['dormant'], $stats['Dormant Accounts']);
        $this->assertSame($kpis['accounts']['frozen'], $stats['Frozen Accounts']);
        $this->assertSame($kpis['maturing_30_days']['count'], $stats['Maturing in 30 Days']);
        $this->assertEquals((float) $kpis['maturing_30_days']['maturity_amount'], $stats['Maturity Amount (30 Days)']);
    }

    public function test_the_figures_equal_the_savings_dashboard(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);
        $kpis = $this->actingAs($head)->getJson('/api/savings/dashboard')->assertOk()->json('data.kpis');
        $reply = $this->ask($head);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('SavingsSummaryAnswer', $reply->answerFunction);
        $this->assertMatchesKpis($kpis, $reply);
        $this->assertSame(
            'As of today, November 10, 2026, total deposits across all branches are ₱50,258.87: ₱13,058.87 in savings accounts '
            .'and ₱37,200.00 in 4 time deposits. There are 3 active, 1 dormant and 1 frozen savings accounts. '
            .'1 time deposit (₱10,000.00) matures in the next 30 days, paying ₱10,050.00.',
            $reply->answer,
        );
        $this->assertSame([['label' => 'Open Savings Dashboard', 'url' => '/savings/dashboard']], $reply->links);
    }

    public function test_a_branch_user_and_a_named_branch_narrow_it(): void
    {
        $viewer = $this->userWith(['savings:view']);
        $kpis = $this->actingAs($viewer)->getJson('/api/savings/dashboard')->assertOk()->json('data.kpis');
        $this->assertMatchesKpis($kpis, $this->ask($viewer));
        $this->assertSame(3, $this->stats($this->ask($viewer))['Time Deposits']);
        $this->assertSame('no_branch', $this->ask($viewer, 'Far')->outcome);

        $head = $this->userWith(['savings:view', 'branches:view_all']);
        $far = $this->actingAs($head)->getJson('/api/savings/dashboard?branch_id='.$this->far->id)->assertOk()->json('data.kpis');
        $this->assertMatchesKpis($far, $this->ask($head, 'Far'));
    }

    public function test_it_needs_savings_view(): void
    {
        $reply = $this->ask($this->userWith(['reports:view', 'branches:view_all']));

        $this->assertSame('denied', $reply->outcome);
        $this->assertSame([], $reply->blocks);
    }

    public function test_savings_not_set_up_is_said_plainly(): void
    {
        // A deployment that never set savings up: no product and no account.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['savings_transactions', 'time_deposits', 'savings_accounts', 'savings_product_rates', 'savings_products'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->assertFalse(SavingsProduct::query()->exists() || SavingsAccount::query()->exists());

        $reply = $this->ask($this->userWith(['savings:view', 'branches:view_all']));

        $this->assertSame('not_yet', $reply->outcome);
        $this->assertSame('Savings isn\'t set up in this deployment yet, so there are no savings figures to show.', $reply->answer);
        $this->assertSame([], $reply->blocks);
    }

    public function test_savings_questions_route_to_the_savings_intent(): void
    {
        foreach ([
            'How much savings do we have?', 'What are our total deposits?', 'How many savings accounts do we have?',
            'Time deposits maturing soon', 'Magkano ang ipon ng mga miyembro natin?', 'Magkano ang savings natin sa Main?',
            'Pila ang tinigom nato?', 'Pila ka time deposits nato?',
        ] as $question) {
            $this->assertSame(LindaIntent::SavingsSummary, LindaMatcher::match($question)?->intent, $question);
        }
    }

    // -----------------------------------------------------------------
    // Round 4: savings by period and branch (Savings task, TASK 46)
    // -----------------------------------------------------------------

    private function askAbout(User $user, string $question, ?LindaPeriod $period = null, ?string $branch = null): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, LindaIntent::SavingsSummary, $period, $branch, $question);
    }

    /** @return array<string, mixed> the dashboard's data for the dates */
    private function dashboard(User $user, string $from, string $to): array
    {
        return $this->actingAs($user)->getJson('/api/savings/dashboard?'.http_build_query(['from' => $from, 'to' => $to]))->assertOk()->json('data');
    }

    /** @return list<array<string, mixed>> */
    private function rows(LindaReply $reply): array
    {
        $rows = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'list' ? $block['items'] : [] as $item) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    public function test_deposits_and_withdrawals_for_a_period_equal_the_dashboards(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);

        foreach ([['this_month', '2026-11-01', '2026-11-10'], ['last_month', '2026-10-01', '2026-10-31']] as [$key, $from, $to]) {
            $kpis = $this->dashboard($head, $from, $to)['kpis'];

            $stats = $this->stats($this->askAbout($head, 'How much was deposited and withdrawn?', LindaPeriod::named($key)));

            $this->assertEquals((float) $kpis['deposits']['amount'], $stats['Deposits'], $key);
            $this->assertSame($kpis['deposits']['count'], $stats['Number of Deposits'], $key);
            $this->assertEquals((float) $kpis['withdrawals']['amount'], $stats['Withdrawals'], $key);
            $this->assertSame($kpis['withdrawals']['count'], $stats['Number of Withdrawals'], $key);
            $this->assertEquals((float) $kpis['net_deposit_movement'], $stats['Deposits less Withdrawals'], $key);
            $this->assertEquals((float) $kpis['total_deposits'], $stats['Total Deposits'], $key);
            $this->assertEquals((float) $kpis['savings_balance'], $stats['Savings Balance'], $key);
        }

        // A named branch narrows it, as the dashboard's branch filter does.
        $far = $this->actingAs($head)->getJson('/api/savings/dashboard?from=2026-11-01&to=2026-11-10&branch_id='.$this->far->id)->assertOk()->json('data.kpis');
        $stats = $this->stats($this->askAbout($head, 'Deposits this month in Far branch', LindaPeriod::named('this_month'), 'Far'));
        $this->assertEquals((float) $far['deposits']['amount'], $stats['Deposits']);
        $this->assertEquals((float) $far['net_deposit_movement'], $stats['Deposits less Withdrawals']);
    }

    public function test_net_growth_for_a_period_reads_deposits_less_withdrawals(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);
        $kpis = $this->dashboard($head, '2026-11-01', '2026-11-10')['kpis'];
        $match = LindaMatcher::match('What is our net deposit growth this month?');

        $this->assertSame(LindaIntent::SavingsSummary, $match?->intent);
        $reply = $this->askAbout($head, 'What is our net deposit growth this month?', $match->period);

        $this->assertSame(
            'From November 1 to 10, 2026, members deposited '.W::money($kpis['deposits']['amount']).' ('.W::count($kpis['deposits']['count'], 'deposit').') and withdrew '
            .W::money($kpis['withdrawals']['amount']).' ('.W::count($kpis['withdrawals']['count'], 'withdrawal').') across all branches, so deposits less withdrawals came to '
            .W::money($kpis['net_deposit_movement']).'. As of today, November 10, 2026, total deposits are '.W::money($kpis['total_deposits']).': '
            .W::money($kpis['savings_balance']).' in savings accounts and '.W::money($kpis['time_deposits']['amount']).' in '.W::count($kpis['time_deposits']['count'], 'time deposit').'.',
            $reply->answer,
        );
    }

    public function test_balances_as_of_today_whatever_words_say_now(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);
        $plain = $this->ask($head)->answer;

        $this->assertSame($plain, $this->askAbout($head, 'How much savings do we currently hold?', LindaMatcher::match('How much savings do we currently hold?')?->period)->answer);
        $this->assertSame($plain, $this->askAbout($head, 'How many time deposits mature this month?', LindaPeriod::named('this_month'))->answer);
    }

    public function test_the_branch_with_the_highest_savings_comes_from_the_dashboards_by_branch_rows(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);
        $rows = collect($this->dashboard($head, '2026-11-01', '2026-11-10')['by_branch']['rows'])->whereNotNull('group_id');
        $question = 'Which branch has the highest savings?';
        $match = LindaMatcher::match($question);

        $this->assertSame(LindaIntent::SavingsSummary, $match?->intent);
        $reply = $this->askAbout($head, $question, $match->period, $match->branchName);

        $this->assertStringStartsWith(
            'As of today, November 10, 2026, Main Branch holds the most deposits: ₱33,259.87 (₱3,059.87 in savings accounts and ₱30,200.00 in time deposits). '
            .'Total deposits across all branches are ₱50,258.87.',
            $reply->answer,
        );
        $ranked = $rows->sortByDesc(fn (array $row) => (float) $row['total_deposits'])->values();
        $this->assertSame($ranked->pluck('group_name')->all(), array_column($this->rows($reply), 'title'));
        foreach ($this->rows($reply) as $at => $item) {
            $this->assertEquals((float) $ranked[$at]['total_deposits'], collect($item['fields'])->firstWhere('label', 'Total Deposits')['value']);
            $this->assertEquals((float) $ranked[$at]['closing_balance'], collect($item['fields'])->firstWhere('label', 'Savings Balance')['value']);
        }

        // In savings accounts alone, Far Branch holds the most.
        $accounts = $this->askAbout($head, 'Which branch has the most in savings accounts?');
        $this->assertStringStartsWith('As of today, November 10, 2026, Far Branch holds the most in savings accounts: ₱9,999.00.', $accounts->answer);
        $this->assertSame(['Far Branch', 'Main Branch'], array_column($this->rows($accounts), 'title'));
    }

    public function test_a_branch_word_and_savings_is_one_subject(): void
    {
        foreach ([
            'Which branch has the highest savings?', 'Savings by branch', 'Savings per branch this month', 'Branch with the most deposits',
            'Unsang branch ang pinakadaghan og savings?', 'Aling branch ang may pinakamalaking ipon?',
        ] as $question) {
            $this->assertSame(LindaIntent::SavingsSummary, LindaMatcher::match($question)?->intent, $question);
        }

        $this->assertSame(LindaIntent::BranchPerformance, LindaMatcher::match('Which branch collected the most this month?')?->intent);
    }

    public function test_the_open_question_pack_holds_savings_by_branch(): void
    {
        $head = $this->userWith(['savings:view', 'branches:view_all']);

        $pack = app(LindaFacts::class)->build($head, LindaPeriod::named('this_month'), null, [LindaIntent::SavingsSummary]);
        $entries = collect($pack['facts']['topics'])->where('topic', 'savings_summary');
        $byBranch = $entries->first(fn (array $entry) => str_starts_with($entry['title'], 'Total deposits by branch'));

        $this->assertNotNull($byBranch);
        $this->assertSame([['label' => 'Main Branch', 'value' => '₱33,259.87'], ['label' => 'Far Branch', 'value' => '₱16,999.00']], $byBranch['figures']);

        $json = json_encode($pack['facts'], JSON_UNESCAPED_UNICODE);
        $this->assertFalse(LindaFacts::hasIdentifier($json));
        foreach (Borrower::query()->get() as $member) {
            $this->assertStringNotContainsString($member->last_name, $json);
            $this->assertStringNotContainsString((string) $member->borrower_code, $json);
        }

        // A Main Branch user's pack has only their branch: no by-branch entry.
        $branchPack = app(LindaFacts::class)->build($this->userWith(['savings:view']), LindaPeriod::named('this_month'), null, [LindaIntent::SavingsSummary]);
        $this->assertNull(collect($branchPack['facts']['topics'])->first(fn (array $entry) => str_starts_with($entry['title'], 'Total deposits by branch')));
        $this->assertStringNotContainsString('Far Branch', json_encode($branchPack['facts']));
    }

    public function test_savings_questions_with_a_period_route_to_savings(): void
    {
        foreach ([
            'How much was deposited today?' => ['today', null],
            'What is our net deposit growth this quarter?' => ['this_quarter', null],
            'How are savings performing this month?' => ['this_month', null],
            'How much savings do we currently hold?' => ['today', null],
            'How many dormant accounts do we have?' => [null, null],
            'How many time deposits mature this month?' => ['this_month', null],
            'Magkano ang na-deposit ngayon?' => ['today', null],
            'Pila ang na-withdraw karong bulana?' => ['this_month', null],
            'Pila ang withdrawals sa Main karong bulana?' => ['this_month', 'main'],
        ] as $question => [$period, $branch]) {
            $match = LindaMatcher::match($question);

            $this->assertSame(LindaIntent::SavingsSummary, $match?->intent, $question);
            $this->assertSame($period, $match->period?->key, $question);
            $this->assertSame($branch, $match->branchName, $question);
        }

        // "How do I withdraw?" stays a menu question.
        $this->assertNull(LindaMatcher::match('How do I withdraw?'));
    }
}
