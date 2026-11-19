<?php

namespace Tests\Feature\Linda;

use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaReply;
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
}
