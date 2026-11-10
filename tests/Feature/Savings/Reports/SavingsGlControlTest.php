<?php

namespace Tests\Feature\Savings\Reports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsProduct;
use App\Models\TimeDeposit;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Savings\SavingsLedger;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Interest\SavingsHistory;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The daily GL control (design §6, §8): the savings subledger against GL
 * 2050 Savings Deposits and 2060 Time Deposits, per branch. A mixed day of
 * deposits, a withdrawal, a transfer, interest with tax and a time deposit
 * reads ₱0.00; a subledger row with no journal shows the difference, and the
 * nightly command tells the `savings:post` holders who can see that branch,
 * once per date.
 */
class SavingsGlControlTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->far = Branch::factory()->create(['name' => 'Far Branch']);
        $this->onDay('2026-11-10');
        $this->product = $this->regularProduct();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mixedDay(): void
    {
        $x = $this->openAccount($this->product, 1_000_000);
        $y = $this->openAccount($this->regularProduct(), 200_000);
        $this->withdraw($x, 100_000);

        DB::transaction(function () use ($x, $y) {
            $ledger = app(SavingsLedger::class);
            $out = $ledger->debit($x, 'transfer_out', 50_000, ['method' => 'savings']);
            $ledger->credit($y, 'transfer_in', 50_000, ['method' => 'savings', 'counterpart_transaction_id' => $out->id]);
            $interest = $ledger->credit($y, 'interest', 5_000, ['created_by' => null]);
            $tax = $ledger->debit($y, 'withholding_tax', 1_000, ['created_by' => null]);
            app(AutomaticPoster::class)->savingsInterestCredit($interest, $tax, null);
        });

        $deposit = TimeDeposit::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'principal' => '50000.00',
            'placement_date' => '2026-11-10',
            'funding_method' => 'cash',
        ]);
        DB::transaction(fn () => app(AutomaticPoster::class)->timeDepositPlacement($deposit, $this->admin->id));

        $this->openAccount($this->product, 300_000, $this->far);
    }

    /** @return array<string, array<string, mixed>> keyed by account code */
    private function rowsFor(array $rows, int $branchId): array
    {
        return collect($rows)->where('branch_id', $branchId)->keyBy('account_code')->all();
    }

    public function test_a_mixed_day_reads_nil_by_branch_and_account(): void
    {
        $this->mixedDay();
        $reader = $this->userWith(['reports:view', 'savings:view']);

        $data = $this->actingAs($reader)->getJson('/api/reports/savings/gl-control')->assertOk()->json('data');

        $this->assertSame('2026-11-10', $data['as_of']);
        $main = $this->rowsFor($data['rows'], $this->branch->id);
        $this->assertSame(['subledger_balance' => '11040.00', 'gl_balance' => '11040.00', 'difference' => '0.00'], collect($main['2050'])->only(['subledger_balance', 'gl_balance', 'difference'])->all());
        $this->assertSame(['subledger_balance' => '50000.00', 'gl_balance' => '50000.00', 'difference' => '0.00'], collect($main['2060'])->only(['subledger_balance', 'gl_balance', 'difference'])->all());
        $this->assertSame($this->branch->name, $main['2050']['branch_name']);
        $this->assertSame('Savings Deposits', $main['2050']['account_name']);
        $this->assertSame([], $this->rowsFor($data['rows'], $this->far->id), 'Another branch is not reported to a branch user.');
        $this->assertSame(['subledger_balance' => '61040.00', 'gl_balance' => '61040.00', 'difference' => '0.00'], $data['totals']);
        $this->assertTrue($data['is_balanced']);

        $all = $this->actingAs($this->admin)->getJson('/api/reports/savings/gl-control')->assertOk()->json('data');
        $this->assertSame('3000.00', $this->rowsFor($all['rows'], $this->far->id)['2050']['subledger_balance']);
        $this->assertSame('0.00', $all['totals']['difference']);

        $this->actingAs($reader)->getJson('/api/reports/savings/gl-control?as_of=2026-11-09')->assertOk()
            ->assertJsonPath('data.totals.subledger_balance', '0.00')
            ->assertJsonPath('data.totals.difference', '0.00');
        $this->actingAs($reader)->getJson("/api/reports/savings/gl-control?branch_id={$this->far->id}")->assertForbidden();
    }

    public function test_a_difference_notifies_the_savings_post_holders_who_see_the_branch_once_per_date(): void
    {
        $this->mixedDay();
        $poster = $this->userWith(['savings:view', 'savings:post']);
        $farPoster = $this->userWith(['savings:view', 'savings:post'], $this->far);
        $viewer = $this->userWith(['savings:view', 'reports:view']);

        $this->artisan('savings:gl-control')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'savings_gl_difference')->count(), 'A balanced day notifies nobody.');

        // A legacy balance written to the subledger with no journal.
        $account = $this->openAccount($this->product);
        DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', 12_345, ['method' => 'cash']));

        $this->artisan('savings:gl-control')->assertSuccessful();
        $this->artisan('savings:gl-control')->assertSuccessful();

        $notified = DB::table('notifications')->where('type', 'savings_gl_difference')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($poster->id, $notified);
        $this->assertNotContains($farPoster->id, $notified, 'The difference is in a branch they cannot see.');
        $this->assertNotContains($viewer->id, $notified);
        $this->assertSame(1, collect($notified)->filter(fn (int $id) => $id === $poster->id)->count(), 'Once per date.');

        $body = json_decode((string) DB::table('notifications')->where('notifiable_id', $poster->id)->where('type', 'savings_gl_difference')->value('data'), true);
        $this->assertStringContainsString('₱123.45', $body['body']);

        $this->actingAs($this->admin)->getJson('/api/reports/savings/gl-control')->assertOk()->assertJsonPath('data.totals.difference', '123.45')->assertJsonPath('data.is_balanced', false);

        $this->onDay('2026-11-11');
        $this->artisan('savings:gl-control')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->where('type', 'savings_gl_difference')->where('notifiable_id', $poster->id)->count(), 'A new date notifies again.');
    }

    public function test_the_command_does_nothing_without_savings_records(): void
    {
        $this->userWith(['savings:post']);

        $this->artisan('savings:gl-control')->expectsOutputToContain('No savings records')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications')->where('type', 'savings_gl_difference')->count());
    }

    public function test_it_runs_nightly_at_23_50_manila(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'savings:gl-control'));

        $this->assertNotNull($event);
        $this->assertSame('50 23 * * *', $event->expression);
        $this->assertSame('Asia/Manila', $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }
}
