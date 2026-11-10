<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who may use the time deposit endpoints (design §2, §3, §0 "Branches"):
 * each needs its own `savings:*` permission (403 without it), a placement in
 * a branch the caller can't see answers 404 like a missing one, a member in
 * such a branch is refused as unknown (422), and the list covers only the
 * caller's branches, with `meta.stats` and `meta.totals` over the filter.
 */
class TimeDepositAccessTest extends TestCase
{
    use TimeDepositFixtures;

    private const NAMES = ['savings:view', 'savings:create', 'savings:transact', 'savings:close', 'savings:approve', 'savings:post', 'savings:settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTimeDeposits();
    }

    protected function tearDown(): void
    {
        $this->tearDownTimeDeposits();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: bool}> method, uri, body, permission, idempotent */
    private function calls(TimeDeposit $deposit): array
    {
        $quote = collect($this->placementBody())->only(['borrower_id', 'product_id', 'principal', 'term_days', 'placement_date'])->all();

        return [
            ['getJson', '/api/savings/time-deposits', [], 'savings:view', false],
            ['getJson', "/api/savings/time-deposits/{$deposit->id}", [], 'savings:view', false],
            ['postJson', '/api/savings/time-deposits/quote', $quote, 'savings:create', false],
            ['postJson', '/api/savings/time-deposits', $this->placementBody(), 'savings:create', true],
            ['postJson', "/api/savings/time-deposits/{$deposit->id}/settle", ['date' => '2026-11-10', 'action' => 'payout', 'payout_method' => 'cash'], 'savings:transact', true],
            ['getJson', "/api/savings/time-deposits/{$deposit->id}/pretermination-quote?date=2026-11-10", [], 'savings:close', false],
            ['postJson', "/api/savings/time-deposits/{$deposit->id}/preterminate", ['date' => '2026-11-10', 'reason' => 'Probe', 'payout_method' => 'cash'], 'savings:close', true],
        ];
    }

    private function send(array $call)
    {
        [$method, $uri, $body, , $idempotent] = $call;

        if ($idempotent) {
            return $this->idem($uri, $body);
        }

        return $method === 'getJson' ? $this->getJson($uri) : $this->postJson($uri, $body);
    }

    public function test_each_endpoint_needs_its_own_permission(): void
    {
        $deposit = TimeDeposit::factory()->create(['borrower_id' => $this->member->id, 'product_id' => $this->product->id]);

        foreach ($this->calls($deposit) as $call) {
            $this->actingAs($this->userWith(array_values(array_diff(self::NAMES, [$call[3]]))));
            $this->send($call)->assertForbidden();

            $this->actingAs($this->userWith([$call[3]]));
            $this->assertNotSame(403, $this->send($call)->status(), "{$call[0]} {$call[1]} refused a caller holding {$call[3]}.");
        }
    }

    public function test_another_branchs_placement_answers_404_on_every_id_route(): void
    {
        $elsewhere = Branch::factory()->create();
        $theirs = Borrower::factory()->create(['branch_id' => $elsewhere->id, 'status' => 'active']);
        $deposit = TimeDeposit::factory()->create(['borrower_id' => $theirs->id, 'product_id' => $this->product->id]);

        $this->actingAs($this->userWith(self::NAMES));

        foreach ($this->calls($deposit) as $call) {
            if (str_contains($call[1], (string) $deposit->id)) {
                $this->send($call)->assertNotFound();
            }
        }

        $this->getJson('/api/savings/time-deposits')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/savings/time-deposits?branch_id={$elsewhere->id}")->assertForbidden();

        $this->place(['borrower_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->postJson('/api/savings/time-deposits/quote', ['borrower_id' => $theirs->id, 'product_id' => $this->product->id, 'principal' => '1000', 'term_days' => 90, 'placement_date' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }

    public function test_the_list_filters_and_counts_on_the_server(): void
    {
        $this->place(['principal' => '10000.00']);
        $this->place(['principal' => '20000.00', 'term_days' => 180]);
        $settled = TimeDeposit::factory()->create([
            'borrower_id' => $this->member->id, 'product_id' => $this->product->id, 'principal' => '5000.00', 'status' => 'withdrawn',
            'placement_date' => '2026-08-01', 'maturity_date' => '2026-10-30',
        ]);
        TimeDeposit::factory()->create(['borrower_id' => Borrower::factory()->create(['branch_id' => Branch::factory()])->id, 'product_id' => $this->product->id]);

        $this->actingAs($this->userWith(['savings:view']));
        $this->travelTo(Carbon::parse('2027-01-15 09:00:00', 'Asia/Manila'));

        $all = $this->getJson('/api/savings/time-deposits')->assertOk();
        $all->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.stats.active', 2)
            ->assertJsonPath('meta.stats.withdrawn', 1)
            ->assertJsonPath('meta.totals.count', 3)
            ->assertJsonPath('meta.totals.total_principal', '35000.00')
            ->assertJsonPath('meta.totals.maturing_30_days', 1);

        $this->getJson('/api/savings/time-deposits?status=withdrawn&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $settled->id)
            ->assertJsonPath('meta.stats.active', 2)
            ->assertJsonPath('meta.totals.total_principal', '5000.00');

        $this->getJson('/api/savings/time-deposits?search='.urlencode($settled->certificate_number))->assertOk()
            ->assertJsonCount(1, 'data');
        $this->getJson('/api/savings/time-deposits?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
