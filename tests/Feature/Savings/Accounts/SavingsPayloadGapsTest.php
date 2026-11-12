<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings payload gaps (gap round B9), all read only and worked out on the
 * server:
 *
 * - a line posted through an approval names its final checker
 *   (`approved_by`) and, with two checkers, the first (`first_checker`);
 * - the account detail carries the next withdrawal's fee
 *   (`next_withdrawal_fee`) and `free_withdrawals_left`, as the portal's
 *   options do;
 * - a withdrawal with a fee, its approval and the reversal approval of both
 *   carry `total_with_fee` (amount plus fee).
 */
class SavingsPayloadGapsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account(['opened_on' => '2026-10-01']);
        $this->fund($this->savings, 10_000_000);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rules(array $rules): void
    {
        $this->product->forceFill($rules)->save();
    }

    private function withdraw(string $amount)
    {
        return $this->money("/api/savings/accounts/{$this->savings->id}/withdrawals", ['amount' => $amount, 'date' => '2026-11-10', 'payment_method' => 'cash']);
    }

    private function posted(int $approvalId): SavingsTransaction
    {
        return SavingsTransaction::query()->where('approval_id', $approvalId)->where('type', 'withdrawal')->sole();
    }

    /** @return array{id: int, name: string} */
    private function ref(User $user): array
    {
        $name = trim((string) $user->full_name);

        return ['id' => $user->id, 'name' => $name !== '' ? $name : $user->username];
    }

    public function test_a_line_posted_through_one_checker_names_the_checker_and_no_first_checker(): void
    {
        $this->actingAs($this->teller());
        $approval = $this->withdraw('15000.00')->assertStatus(202)->json('data.approval.id');

        $checker = $this->checker();
        $this->actingAs($checker);
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $row = $this->posted($approval);

        $this->getJson("/api/savings/transactions/{$row->id}")->assertOk()
            ->assertJsonPath('data.approval_id', $approval)
            ->assertJsonPath('data.approved_by', $this->ref($checker))
            ->assertJsonPath('data.first_checker', null);

        $listed = collect($this->getJson("/api/savings/accounts/{$this->savings->id}/transactions")->assertOk()->json('data'))->firstWhere('id', $row->id);
        $this->assertSame($this->ref($checker), $listed['approved_by']);
    }

    public function test_a_line_posted_through_two_checkers_names_both(): void
    {
        $this->actingAs($this->teller());
        $approval = $this->withdraw('60000.00')->assertStatus(202)->assertJsonPath('data.approval.required_checkers', 2)->json('data.approval.id');

        $first = $this->checker();
        $this->actingAs($first);
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();
        $final = $this->checker();
        $this->actingAs($final);
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $this->getJson("/api/savings/transactions/{$this->posted($approval)->id}")->assertOk()
            ->assertJsonPath('data.approved_by', $this->ref($final))
            ->assertJsonPath('data.first_checker', $this->ref($first));
    }

    public function test_a_line_posted_without_an_approval_names_no_checker(): void
    {
        $row = $this->withdraw('100.00')->assertCreated()->json('data');

        $this->assertNull($row['approval_id']);
        $this->assertArrayHasKey('approved_by', $row);
        $this->assertArrayHasKey('first_checker', $row);
        $this->assertNull($row['approved_by']);
        $this->assertNull($row['first_checker']);
        $this->getJson("/api/savings/transactions/{$row['id']}")->assertOk()->assertJsonPath('data.approved_by', null);
    }

    public function test_a_reversal_line_names_the_checker_who_approved_the_reversal(): void
    {
        $row = $this->withdraw('100.00')->assertCreated()->json('data.id');
        $approval = $this->money("/api/savings/transactions/{$row}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval.id');
        $checker = $this->checker();
        $this->actingAs($checker);
        $this->postJson("/api/savings/approvals/{$approval}/approve")->assertOk();

        $reversal = SavingsTransaction::query()->where('reverses_transaction_id', $row)->sole();
        $this->getJson("/api/savings/transactions/{$reversal->id}")->assertOk()
            ->assertJsonPath('data.approved_by', $this->ref($checker))
            ->assertJsonPath('data.first_checker', null);
        $this->getJson("/api/savings/transactions/{$row}")->assertOk()->assertJsonPath('data.approved_by', null);
    }

    public function test_the_account_detail_shows_no_fee_and_unlimited_withdrawals_without_a_free_count(): void
    {
        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', '0.00')
            ->assertJsonPath('data.free_withdrawals_left', null);
    }

    public function test_the_account_detail_counts_posted_and_requested_withdrawals_toward_the_next_fee(): void
    {
        $this->rules(['free_withdrawals_per_month' => 2, 'excess_withdrawal_fee' => '25.00']);

        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', '0.00')
            ->assertJsonPath('data.free_withdrawals_left', 2);

        $this->withdraw('100.00')->assertCreated();
        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', '0.00')
            ->assertJsonPath('data.free_withdrawals_left', 1);

        // A withdrawal waiting for approval counts as already requested.
        $this->actingAs($this->teller());
        $this->withdraw('15000.00')->assertStatus(202);
        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', '25.00')
            ->assertJsonPath('data.free_withdrawals_left', 0);
    }

    public function test_the_account_detail_reads_null_fee_when_more_withdrawals_would_be_refused(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => null]);
        $this->withdraw('100.00')->assertCreated();

        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', null)
            ->assertJsonPath('data.free_withdrawals_left', 0);
    }

    public function test_the_next_fee_is_on_the_account_detail_only_not_the_list(): void
    {
        $row = $this->getJson('/api/savings/accounts')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('next_withdrawal_fee', $row);
        $this->assertArrayNotHasKey('free_withdrawals_left', $row);
    }

    public function test_another_branchs_account_and_lines_answer_404(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);
        $row = $this->withdraw('100.00')->assertCreated()->json('data.id');

        $elsewhere = Branch::factory()->create();
        $this->actingAs($this->userWith(['savings:view', 'savings:approve'], $elsewhere));

        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertNotFound()->assertJsonMissingPath('data.next_withdrawal_fee');
        $this->getJson("/api/savings/transactions/{$row}")->assertNotFound()->assertJsonMissingPath('data.approved_by');
        $this->getJson("/api/savings/accounts/{$this->savings->id}/transactions")->assertNotFound();

        $theirs = $this->account(['branch_id' => $elsewhere->id, 'borrower_id' => Borrower::factory()->create(['branch_id' => $elsewhere->id, 'status' => 'active'])->id]);
        $this->getJson("/api/savings/accounts/{$theirs->id}")->assertOk()
            ->assertJsonPath('data.next_withdrawal_fee', '0.00')
            ->assertJsonPath('data.free_withdrawals_left', 1);
    }

    public function test_a_withdrawal_with_a_fee_carries_its_total_and_one_without_reads_null(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '25.00']);

        $free = $this->withdraw('100.00')->assertCreated()->assertJsonPath('data.total_with_fee', null)->json('data.id');
        $charged = $this->withdraw('100.00')->assertCreated()
            ->assertJsonPath('data.fee_amount', '25.00')
            ->assertJsonPath('data.total_with_fee', '125.00')
            ->json('data');

        $this->getJson("/api/savings/transactions/{$charged['id']}")->assertOk()->assertJsonPath('data.total_with_fee', '125.00');
        $this->getJson("/api/savings/transactions/{$charged['fee_transaction_id']}")->assertOk()->assertJsonPath('data.total_with_fee', null);
        $this->getJson("/api/savings/transactions/{$free}")->assertOk()->assertJsonPath('data.total_with_fee', null);
    }

    public function test_a_withdrawal_approval_with_a_fee_carries_its_total_and_one_without_reads_null(): void
    {
        $this->rules(['free_withdrawals_per_month' => 1, 'excess_withdrawal_fee' => '50.00']);
        $this->actingAs($this->teller());

        $free = $this->withdraw('15000.00')->assertStatus(202)
            ->assertJsonPath('data.approval.fee_amount', '0.00')
            ->assertJsonPath('data.approval.total_with_fee', null)
            ->json('data.approval.id');
        $charged = $this->withdraw('15000.00')->assertStatus(202)
            ->assertJsonPath('data.approval.fee_amount', '50.00')
            ->assertJsonPath('data.approval.total_with_fee', '15050.00')
            ->json('data.approval.id');

        $this->actingAs($this->checker());
        $listed = collect($this->getJson('/api/savings/approvals?status=pending')->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($listed[$free]['total_with_fee']);
        $this->assertSame('15050.00', $listed[$charged]['total_with_fee']);

        $this->postJson("/api/savings/approvals/{$charged}/approve")->assertOk()->assertJsonPath('data.total_with_fee', '15050.00');
        $this->getJson("/api/savings/transactions/{$this->posted($charged)->id}")->assertOk()->assertJsonPath('data.total_with_fee', '15050.00');
    }

    public function test_a_reversal_approval_of_a_withdrawal_and_its_fee_carries_the_total(): void
    {
        $this->rules(['free_withdrawals_per_month' => 0, 'excess_withdrawal_fee' => '25.00']);
        $row = $this->withdraw('100.00')->assertCreated()->json('data.id');

        $this->money("/api/savings/transactions/{$row}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'reversal')
            ->assertJsonPath('data.approval.fee_amount', '25.00')
            ->assertJsonPath('data.approval.total_with_fee', '125.00');
    }

    public function test_a_reversal_approval_with_no_fee_reads_null(): void
    {
        $deposit = $this->money("/api/savings/accounts/{$this->savings->id}/deposits", ['amount' => '100.00', 'date' => '2026-11-10', 'payment_method' => 'cash'])
            ->assertCreated()->json('data');
        $this->assertArrayHasKey('total_with_fee', $deposit);
        $this->assertNull($deposit['total_with_fee']);

        $approval = $this->money("/api/savings/transactions/{$deposit['id']}/reverse", ['reason' => 'Keyed twice'])->assertStatus(202)->json('data.approval');
        $this->assertArrayHasKey('total_with_fee', $approval);
        $this->assertNull($approval['total_with_fee']);
    }
}
