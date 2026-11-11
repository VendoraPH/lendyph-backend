<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\SavingsApproval;
use App\Models\TimeDeposit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Browser check (Phase 3, S5): a placement whose payout or pre-termination
 * waits for a checker reads `pending_approval` (`{id, kind, amount,
 * requested_by{id, name}}`, else null) on the list and the detail, so the
 * screen can show "Waiting for approval" and disable Settle and
 * Pre-terminate.
 */
class TimeDepositPendingApprovalTest extends TestCase
{
    use TimeDepositFixtures;

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

    private function placed(): TimeDeposit
    {
        return TimeDeposit::query()->findOrFail($this->place()->assertCreated()->json('data.id'));
    }

    /** @return array<string, mixed>|null */
    private function listed(TimeDeposit $deposit): ?array
    {
        $row = collect($this->getJson('/api/savings/time-deposits?per_page=100')->assertOk()->json('data'))->firstWhere('id', $deposit->id);
        $this->assertNotNull($row);

        return $row['pending_approval'];
    }

    public function test_a_payout_waiting_for_a_checker_shows_on_the_list_and_the_detail(): void
    {
        $waiting = $this->placed();
        $other = $this->placed();
        $this->travelTo(Carbon::parse('2027-02-08 09:00:00', 'Asia/Manila'));

        $approvalId = $this->idem("/api/savings/time-deposits/{$waiting->id}/settle", ['date' => now()->toDateString(), 'action' => 'payout', 'payout_method' => 'cash'])
            ->assertStatus(202)->json('data.approval.id');

        $expected = [
            'id' => $approvalId,
            'kind' => 'td_payout',
            'amount' => '100739.73',
            'requested_by' => ['id' => $this->admin->id, 'name' => $this->admin->full_name],
        ];

        $this->assertSame($expected, $this->getJson("/api/savings/time-deposits/{$waiting->id}")->assertOk()->json('data.pending_approval'));
        $this->assertSame($expected, $this->listed($waiting));
        $this->assertNull($this->getJson("/api/savings/time-deposits/{$other->id}")->assertOk()->json('data.pending_approval'));
        $this->assertNull($this->listed($other));
    }

    public function test_a_pretermination_waiting_for_a_checker_shows_and_clears_once_decided(): void
    {
        $deposit = $this->placed();
        $this->travelTo(Carbon::parse('2026-12-10 09:00:00', 'Asia/Manila'));

        $approvalId = $this->idem("/api/savings/time-deposits/{$deposit->id}/preterminate", [
            'date' => now()->toDateString(), 'reason' => 'Member needs the money for tuition.', 'payout_method' => 'cash',
        ])->assertStatus(202)->json('data.approval.id');

        $pending = $this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()->json('data.pending_approval');
        $this->assertSame($approvalId, $pending['id']);
        $this->assertSame('pretermination', $pending['kind']);
        $this->assertSame(SavingsApproval::query()->findOrFail($approvalId)->amount, $pending['amount']);

        DB::table('savings_approvals')->where('id', $approvalId)->update(['status' => 'rejected']);

        $this->assertNull($this->getJson("/api/savings/time-deposits/{$deposit->id}")->assertOk()->json('data.pending_approval'));
        $this->assertNull($this->listed($deposit));
    }

    public function test_the_list_reads_every_pending_approval_in_one_query(): void
    {
        $this->placed();
        $this->placed();
        $this->placed();

        DB::enableQueryLog();
        $this->getJson('/api/savings/time-deposits?per_page=100')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'savings_approvals'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }
}
