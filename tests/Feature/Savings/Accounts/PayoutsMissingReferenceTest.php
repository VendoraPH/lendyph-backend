<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\TimeDeposit;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: "Payouts missing a reference"
 * (`GET /savings/payouts/missing-reference`, savings:view, branch scope, 25
 * a page): the withdrawals, closing payouts and time deposit payouts by bank
 * transfer, GCash or Maya still without a reference, not reversed, newest
 * first, with the member's name and code only. `meta.total` is the count, and
 * the Savings dashboard sends the same count.
 */
class PayoutsMissingReferenceTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private SavingsAccount $savings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account(['account_number' => 'SAV-2026-000101']);
        $this->fund($this->savings, 2_000_000, '2026-11-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_lists_non_cash_payouts_still_without_a_reference(): void
    {
        $gcash = $this->payout('withdrawal', 'gcash', '2026-11-08');
        $this->payout('withdrawal', 'cash', '2026-11-08');
        $this->payout('withdrawal', 'gcash', '2026-11-08', 'GC-HAS-ONE');
        $reversed = $this->payout('withdrawal', 'maya', '2026-11-09');
        DB::transaction(fn () => app(SavingsLedger::class)->credit($this->savings, 'reversal', 10_000, ['reverses_transaction_id' => $reversed->id]));
        DB::transaction(fn () => app(SavingsLedger::class)->credit($this->savings, 'deposit', 10_000, ['method' => 'gcash']));

        $closed = $this->account(['account_number' => 'SAV-2026-000102', 'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active'])->id]);
        $this->fund($closed, 50_000, '2026-11-01');
        $closing = $this->payout('closing', 'bank_transfer', '2026-11-09', null, $closed, 50_000);
        $deposit = $this->paidOutDeposit('maya', '2026-11-10', 'withdrawn');
        $this->paidOutDeposit('cash', '2026-11-10', 'withdrawn');
        $this->paidOutDeposit('maya', '2026-11-10', 'preterminated', 'MAYA-HAS-ONE');
        $this->paidOutDeposit('gcash', '2026-11-10', 'active');

        $response = $this->getJson('/api/savings/payouts/missing-reference')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 25);

        $this->assertSame(
            [['time_deposit', $deposit->id], ['transaction', $closing->id], ['transaction', $gcash->id]],
            collect($response->json('data'))->map(fn (array $r) => [$r['source'], $r['id']])->all(),
        );

        $response->assertJsonPath('data.2', [
            'key' => "transaction-{$gcash->id}",
            'source' => 'transaction',
            'id' => $gcash->id,
            'kind' => 'withdrawal',
            'kind_label' => 'Withdrawal',
            'date' => '2026-11-08',
            'member' => ['id' => $this->member->id, 'name' => $this->member->full_name, 'code' => $this->member->borrower_code],
            'account_id' => $this->savings->id,
            'account_number' => 'SAV-2026-000101',
            'time_deposit_id' => null,
            'certificate_number' => null,
            'reference' => $gcash->reference,
            'method' => 'gcash',
            'method_label' => 'GCash',
            'amount' => '100.00',
            'posted_by' => ['id' => $this->admin->id, 'name' => $this->admin->full_name],
            'can_record_reference' => true,
        ]);
        $response->assertJsonPath('data.1.kind_label', 'Closing payout')
            ->assertJsonPath('data.1.method_label', 'Bank transfer')
            ->assertJsonPath('data.1.amount', '500.00')
            ->assertJsonPath('data.0.kind', 'time_deposit_maturity')
            ->assertJsonPath('data.0.kind_label', 'Time deposit payout at maturity')
            ->assertJsonPath('data.0.certificate_number', $deposit->certificate_number)
            ->assertJsonPath('data.0.reference', $deposit->certificate_number)
            ->assertJsonPath('data.0.account_number', null)
            ->assertJsonPath('data.0.amount', '1000.00');

        // Only the member's name and code: no contact details or address.
        $this->assertSame(['id', 'name', 'code'], array_keys($response->json('data.0.member')));

        $this->getJson('/api/savings/dashboard')->assertOk()->assertJsonPath('data.kpis.payouts_missing_reference', 3);
    }

    public function test_filters_by_method_date_and_branch(): void
    {
        $this->payout('withdrawal', 'gcash', '2026-11-05');
        $maya = $this->payout('withdrawal', 'maya', '2026-11-08');
        $this->paidOutDeposit('maya', '2026-11-10', 'preterminated');

        $this->getJson('/api/savings/payouts/missing-reference?method=maya')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/savings/payouts/missing-reference?date_from=2026-11-06&date_to=2026-11-09')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $maya->id);
        $this->getJson("/api/savings/payouts/missing-reference?branch_id={$this->branch->id}")->assertJsonPath('meta.total', 3);

        $elsewhere = Branch::factory()->create();
        $this->getJson("/api/savings/payouts/missing-reference?branch_id={$elsewhere->id}")->assertJsonPath('meta.total', 0);

        $this->getJson('/api/savings/payouts/missing-reference?method=cash')->assertUnprocessable()
            ->assertJsonPath('errors.method.0', 'Choose bank transfer, GCash or Maya.');
        $this->getJson('/api/savings/payouts/missing-reference?date_from=11/06/2026')->assertUnprocessable();
        $this->getJson('/api/savings/payouts/missing-reference?date_from=2026-11-09&date_to=2026-11-01')->assertUnprocessable();
    }

    public function test_a_branch_user_sees_only_their_branches(): void
    {
        $this->payout('withdrawal', 'gcash', '2026-11-08');
        $elsewhere = Branch::factory()->create();
        $theirs = Borrower::factory()->create(['branch_id' => $elsewhere->id, 'status' => 'active']);
        $their = SavingsAccount::factory()->create(['borrower_id' => $theirs->id, 'product_id' => $this->product->id, 'branch_id' => $elsewhere->id, 'opened_on' => '2026-11-01']);
        $this->fund($their, 50_000, '2026-11-01');
        $this->payout('withdrawal', 'gcash', '2026-11-08', null, $their);

        $this->getJson('/api/savings/payouts/missing-reference')->assertJsonPath('meta.total', 2);

        $branchUser = $this->userWith(['savings:view'], $elsewhere);
        $this->actingAs($branchUser)->getJson('/api/savings/payouts/missing-reference')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.member.id', $theirs->id)
            // savings:view alone can't record it.
            ->assertJsonPath('data.0.can_record_reference', false);
        $this->getJson("/api/savings/payouts/missing-reference?branch_id={$this->branch->id}")->assertForbidden();
        $this->getJson('/api/savings/dashboard')->assertJsonPath('data.kpis.payouts_missing_reference', 1);

        $this->actingAs($this->userWith(['loans:view'], $elsewhere))->getJson('/api/savings/payouts/missing-reference')->assertForbidden();
    }

    public function test_it_pages_by_25(): void
    {
        foreach (range(1, 26) as $i) {
            $this->payout('withdrawal', 'gcash', '2026-11-08');
        }

        $this->getJson('/api/savings/payouts/missing-reference?page=2')
            ->assertJsonPath('meta.total', 26)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    }

    private function payout(string $type, string $method, string $date, ?string $reference = null, ?SavingsAccount $account = null, int $centavos = 10_000): SavingsTransaction
    {
        return $this->on($date, fn () => DB::transaction(fn () => app(SavingsLedger::class)->debit($account ?? $this->savings, $type, $centavos, [
            'method' => $method, 'external_reference' => $reference, 'created_by' => $this->admin->id,
        ])));
    }

    private function paidOutDeposit(string $method, string $date, string $status, ?string $reference = null): TimeDeposit
    {
        $tdProduct = SavingsProduct::factory()->timeDeposit()->create(['code' => 'TD-'.Str::upper(Str::random(6))]);

        return TimeDeposit::factory()->create([
            'borrower_id' => $this->member->id,
            'product_id' => $tdProduct->id,
            'branch_id' => $this->member->branch_id,
            'principal' => '1000.00',
            'status' => $status,
            'settled_on' => $status === 'active' ? null : $date,
            'settled_amount' => $status === 'active' ? null : '1000.00',
            'payout_method' => $status === 'active' ? null : $method,
            'payout_reference' => $reference,
            'settled_by' => $this->admin->id,
        ]);
    }
}
