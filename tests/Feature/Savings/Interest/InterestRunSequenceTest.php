<?php

namespace Tests\Feature\Savings\Interest;

use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Runs post strictly in order (review M1) and survive a change of crediting
 * frequency through one transitional period (review M2).
 */
class InterestRunSequenceTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    private SavingsAccount $account;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->maker = $this->userWith(['savings:view', 'savings:post']);
        $this->checker = $this->userWith(['savings:view', 'savings:approve']);

        $this->onDay('2026-01-01');
        $this->product = $this->regularProduct();
        $this->account = $this->openAccount($this->product, 1_000_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function postRun(string $periodStart): array
    {
        $run = $this->actingAs($this->maker)->postJson('/api/savings/interest-runs', ['product_id' => $this->product->id, 'period_start' => $periodStart])
            ->assertCreated()->json('data.0');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$run['id']}/approve")->assertOk();
        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$run['id']}/post")->assertOk();

        return $run;
    }

    public function test_a_run_whose_previous_period_was_reversed_meanwhile_is_not_posted(): void
    {
        $this->onDay('2026-04-02');
        $q1 = $this->postRun('2026-01-01');

        $this->onDay('2026-07-02');
        $this->actingAs($this->checker)->postJson("/api/savings/interest-runs/{$q1['id']}/reverse", ['reason' => 'Wrong rate'])->assertOk();

        // What a create racing that reversal could have left: Q2, approved,
        // while Q1 is no longer posted.
        $q2 = SavingsInterestRun::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'period_start' => '2026-04-01',
            'period_end' => '2026-06-30',
            'status' => 'approved',
            'created_by' => $this->admin->id,
            'checked_by' => $this->checker->id,
        ]);

        $this->actingAs($this->maker)->postJson("/api/savings/interest-runs/{$q2->id}/post")
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Jan 1, 2026'));

        $this->assertSame('approved', $q2->refresh()->status);
        $this->assertSame(0, SavingsTransaction::query()->where('interest_run_id', $q2->id)->count());
    }

    public function test_a_change_from_quarterly_to_semi_annual_runs_one_transitional_period(): void
    {
        $this->onDay('2026-04-02');
        $this->postRun('2026-01-01');
        $this->product->forceFill(['interest_crediting' => 'semi_annual'])->save();

        $this->onDay('2026-07-02');
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $this->product->id, 'period_start' => '2026-04-01'])
            ->assertOk()
            ->assertJsonPath('data.period_start', '2026-04-01')
            ->assertJsonPath('data.period_end', '2026-06-30');
        $transitional = $this->postRun('2026-04-01');
        $this->assertSame(['2026-04-01', '2026-06-30'], [$transitional['period_start'], $transitional['period_end']]);
        $this->assertSame('2026-06-30', $this->account->refresh()->interest_credited_through->toDateString());

        $this->onDay('2027-01-02');
        $half = $this->postRun('2026-07-01');
        $this->assertSame(['2026-07-01', '2026-12-31'], [$half['period_start'], $half['period_end']]);

        $this->onDay('2027-07-02');
        $this->actingAs($this->maker)->postJson('/api/savings/interest-runs/preview', ['product_id' => $this->product->id, 'period_start' => '2027-04-01'])
            ->assertStatus(422)->assertJsonValidationErrors('period_start');
    }
}
