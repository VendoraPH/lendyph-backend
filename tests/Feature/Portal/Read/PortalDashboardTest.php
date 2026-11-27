<?php

namespace Tests\Feature\Portal\Read;

use App\Models\PortalAnnouncement;
use App\Models\PortalRequest;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Notifications\PortalNotice;
use App\Services\BorrowerLoanSummaryService;
use App\Services\Savings\SavingsMath;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The portal dashboard (portal design §3, TASK 88): one call, every figure
 * from its source, a switched-off card or feature is null, and the query
 * count doesn't grow with the member's history.
 */
class PortalDashboardTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_card_equals_its_source(): void
    {
        $me = $this->portalMember(['first_name' => 'Rosa']);
        $loan = $this->releasedLoanFor($me);
        $second = $this->releasedLoanFor($me, 1, 20000);
        $this->pay($loan, 4000);
        $savings = $this->savingsFor($me, 500_000);
        $other = $this->savingsFor($me, 120_000, ['product_id' => $this->otherProduct()]);
        $this->timeDepositFor($me, ['principal' => '50000.00']);
        $this->timeDepositFor($me, ['principal' => '25000.50']);
        $this->timeDepositFor($me, ['principal' => '9999.00', 'status' => 'withdrawn']);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        PortalRequest::factory()->create(['portal_user_id' => $me->id, 'borrower_id' => $me->borrower_id, 'status' => PortalRequest::STATUS_SUBMITTED]);
        $me->notify(new PortalNotice('security', 'New sign-in', 'Chrome'));
        PortalAnnouncement::factory()->count(4)->create();
        $this->memberDocument($me->borrower);

        $data = $this->portalJson('GET', '/api/portal/dashboard', [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertSame('Rosa', $data['member']['first_name']);
        $this->assertSame('2024-01-15', $data['member']['member_since']);
        $this->assertSame('1500.00', $data['share_capital']['paid_up']);

        $staffSavings = collect([$savings, $other])->map(fn (SavingsAccount $a) => $this->staffGet("/api/savings/accounts/{$a->id}")->json('data'));
        $this->assertSame($this->sum($staffSavings->pluck('balance')->all()), $data['savings']['total']);
        $this->assertSame($this->sum($staffSavings->pluck('available_balance')->all()), $data['savings']['available']);
        $this->assertSame(2, $data['savings']['accounts']);

        $this->assertSame('75000.50', $data['time_deposits']['total']);
        $this->assertSame(2, $data['time_deposits']['count']);

        $summary = app(BorrowerLoanSummaryService::class)->forMember($me->borrower);
        $this->assertSame(SavingsMath::pesos((int) round($summary['total_outstanding'] * 100)), $data['loans']['total_outstanding']);
        $this->assertSame(SavingsMath::pesos((int) round($summary['overdue_amount'] * 100)), $data['loans']['overdue_amount']);
        $this->assertSame(2, $data['loans']['active_count']);

        $next = collect([$loan, $second])->map(fn ($l) => $this->staffGet("/api/loans/{$l->id}/summary")->json('data'));
        $earliest = $next->pluck('next_due_date')->filter()->min();
        $due = $next->where('next_due_date', $earliest);
        $this->assertSame($earliest, $data['next_payment']['due_date']);
        $this->assertSame($this->sum($due->pluck('next_due_amount')->all()), $data['next_payment']['amount']);
        $this->assertSame($due->count(), $data['next_payment']['loans_count']);

        $this->assertSame(1, $data['pending_requests']);
        // The fixture's deposits, release and payments also leave money notices (slice P3's observers).
        $this->assertGreaterThan(1, $me->unreadNotifications()->count());
        $this->assertSame(['notifications' => $me->unreadNotifications()->count(), 'announcements' => 4], $data['unread']);
        $this->assertSame(1, $data['documents']);
        $this->assertCount(3, $data['announcements']);
        $this->assertLessThanOrEqual(5, count($data['recent_activity']));
        $this->assertSame(['date', 'kind', 'description', 'amount'], array_keys($data['recent_activity'][0]));
    }

    public function test_a_switched_off_feature_or_card_is_null(): void
    {
        $this->enablePortal([
            'features' => ['savings' => false, 'loans' => false],
            'dashboard_cards' => ['share_capital', 'next_payment', 'announcements', 'recent_activity'],
        ]);
        $me = $this->portalMember();

        $data = $this->portalJson('GET', '/api/portal/dashboard', [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertNull($data['savings']);
        $this->assertNull($data['loans']);
        $this->assertNull($data['next_payment'], 'Loans are off, so the next payment is too.');
        $this->assertNull($data['time_deposits'], 'Not one of the dashboard cards.');
        $this->assertSame('0.00', $data['share_capital']['paid_up']);
    }

    public function test_the_query_count_does_not_grow_with_two_thousand_savings_rows(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me, 100_000);
        $token = $this->portalToken($me);

        $this->queriesFor($token);
        $few = $this->queriesFor($token);
        $this->bulkRows($account, 2000);
        $many = $this->queriesFor($token);

        $this->assertSame($few, $many, "The dashboard ran {$few} queries with a short history and {$many} with 2,000 savings rows.");
        $this->assertLessThan(80, $many);
    }

    private function queriesFor(string $token): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->portalJson('GET', '/api/portal/dashboard', [], $token)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function bulkRows(SavingsAccount $account, int $count): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'account_id' => $account->id, 'reference' => 'BULK-'.$i, 'type' => 'deposit',
                'date' => '2026-11-09', 'value_date' => '2026-11-09', 'debit' => 0, 'credit' => '1.00',
                'balance_after' => '1000.00', 'method' => 'cash', 'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('savings_transactions')->insert($chunk);
        }
    }

    private function otherProduct(): int
    {
        $product = SavingsProduct::factory()->create(['name' => 'Kiddie Savings', 'maintaining_balance' => '100.00']);
        SavingsProductRate::factory()->create(['product_id' => $product->id, 'annual_rate' => '2.0000', 'effective_from' => '2026-01-01']);

        return $product->id;
    }

    /** @param  list<float|int|string>  $amounts */
    private function sum(array $amounts): string
    {
        return SavingsMath::pesos(array_sum(array_map(fn ($a) => (int) round((float) $a * 100), $amounts)));
    }
}
