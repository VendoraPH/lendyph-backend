<?php

namespace Tests\Feature\Savings\Insight;

use App\Models\Branch;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Interest\SavingsHistory;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Accounting dashboard's savings GL flag (gap round S5, the Savings task's
 * TASK 62): `savings_gl_differs` and `savings_gl_control` are null while
 * savings is not in use, false with ₱0.00 when the subledger and GL agree,
 * true with the difference in ₱ when they don't, read in the dashboard's own
 * branch scope and as of its `as_of`.
 */
class AccountingSavingsGlFlagTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->onDay('2026-11-10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_flag_is_null_while_savings_is_not_in_use(): void
    {
        $this->actingAs($this->admin)->getJson('/api/accounting/dashboard')->assertOk()
            ->assertJsonPath('data.savings_gl_differs', null)
            ->assertJsonPath('data.savings_gl_control', null);
    }

    public function test_the_flag_is_false_when_the_subledger_and_the_books_agree(): void
    {
        $this->openAccount($this->regularProduct(), 150_000);

        $this->actingAs($this->admin)->getJson('/api/accounting/dashboard')->assertOk()
            ->assertJsonPath('data.savings_gl_differs', false)
            ->assertJsonPath('data.savings_gl_control', ['as_of' => '2026-11-10', 'difference' => '0.00', 'differences' => 0]);
    }

    public function test_the_flag_is_true_with_the_difference_when_they_differ(): void
    {
        $account = $this->openAccount($this->regularProduct(), 150_000);
        $this->onDay('2026-11-11');
        DB::transaction(fn () => app(SavingsLedger::class)->credit($account->refresh(), 'deposit', 12_345, ['method' => 'cash']));

        $this->actingAs($this->admin)->getJson('/api/accounting/dashboard')->assertOk()
            ->assertJsonPath('data.savings_gl_differs', true)
            ->assertJsonPath('data.savings_gl_control', ['as_of' => '2026-11-11', 'difference' => '123.45', 'differences' => 1]);

        // As of the day before the unposted row, the books still agreed.
        $this->actingAs($this->admin)->getJson('/api/accounting/dashboard?as_of=2026-11-10')->assertOk()
            ->assertJsonPath('data.savings_gl_differs', false)
            ->assertJsonPath('data.savings_gl_control.as_of', '2026-11-10');
    }

    public function test_the_flag_reads_the_dashboards_branch_scope(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch']);
        $this->openAccount($this->regularProduct(), 150_000);
        $farAccount = $this->openAccount($this->regularProduct(), 10_000, $far);
        $this->onDay('2026-11-11');
        DB::transaction(fn () => app(SavingsLedger::class)->credit($farAccount->refresh(), 'deposit', 5_000, ['method' => 'cash']));

        $this->actingAs($this->userWith(['accounting:view']))->getJson('/api/accounting/dashboard')->assertOk()
            ->assertJsonPath('data.savings_gl_differs', false)
            ->assertJsonPath('data.savings_gl_control.difference', '0.00');

        $this->actingAs($this->admin)->getJson("/api/accounting/dashboard?branch_id={$far->id}")->assertOk()
            ->assertJsonPath('data.savings_gl_differs', true)
            ->assertJsonPath('data.savings_gl_control.difference', '50.00');
        $this->actingAs($this->admin)->getJson("/api/accounting/dashboard?branch_id={$this->branch->id}")->assertOk()
            ->assertJsonPath('data.savings_gl_differs', false);
    }
}
