<?php

namespace Tests\Feature\Savings\Interest;

use App\Models\Borrower;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * How long posting a large branch's run holds its locks (review M3). One run
 * posts every account's rows in one transaction, under every account's lock;
 * this measures 500 accounts (about 1.2 s on a local tmpfs MySQL, 2026-11-10;
 * the limit leaves room for slower CI). A bulk ledger path is for v1.1.
 */
class InterestRunPostTimeTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private const ACCOUNTS = 500;

    private const LIMIT_SECONDS = 20.0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_posting_a_branch_of_500_accounts_stays_within_20_seconds(): void
    {
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $maker = $this->userWith(['savings:view', 'savings:post']);
        $checker = $this->userWith(['savings:view', 'savings:approve']);

        $this->onDay('2026-07-01');
        $product = $this->regularProduct();
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $ledger = app(SavingsLedger::class);

        for ($i = 0; $i < self::ACCOUNTS; $i++) {
            $account = SavingsAccount::factory()->create([
                'borrower_id' => $borrower->id,
                'product_id' => $product->id,
                'branch_id' => $this->branch->id,
                'opened_on' => '2026-07-01',
                'open_key' => null,
            ]);
            DB::transaction(fn () => $ledger->credit($account, 'deposit', 100_000 + $i, ['method' => 'cash']));
        }

        $this->onDay('2026-10-01');
        $id = $this->actingAs($maker)->postJson('/api/savings/interest-runs', ['product_id' => $product->id, 'period_start' => '2026-07-01'])
            ->assertCreated()->json('data.0.id');
        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();

        $started = hrtime(true);
        $this->actingAs($maker)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk();
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertSame(self::ACCOUNTS, SavingsTransaction::query()->where('interest_run_id', $id)->where('type', 'interest')->count());
        $this->assertLessThan(self::LIMIT_SECONDS, $seconds, sprintf('Posting %d accounts took %.2f s.', self::ACCOUNTS, $seconds));
    }
}
