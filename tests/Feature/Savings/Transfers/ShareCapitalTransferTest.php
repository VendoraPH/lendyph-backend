<?php

namespace Tests\Feature\Savings\Transfers;

use App\Exceptions\CannotPostToTheBooksException;
use App\Models\AccountingJournal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\SavingsApproval;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\LedgerGapsReport;
use App\Services\Savings\IdempotentWrite;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Savings to share capital (gap round S3): `POST
 * /savings/accounts/{account}/share-capital-transfers` moves money from a
 * member's savings to their share capital as one `share_capital_transfer`
 * savings row, one share capital credit linked to it, and one journal (Dr
 * 2050 / Cr 3060) on the savings row. Active members only, in the account's
 * own branch, from an account that can pay out, up to the available balance,
 * through the approval tiers as an outflow. Never reversed.
 */
class ShareCapitalTransferTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function officer(): User
    {
        return $this->userWith(['savings:view', 'savings:post', 'share_capital:create', 'share_capital:view']);
    }

    private function transfer(int $accountId, string $amount, ?string $key = null)
    {
        return $this->money("/api/savings/accounts/{$accountId}/share-capital-transfers", ['amount' => $amount, 'remarks' => 'Member asked to build up share capital'], $key);
    }

    private function shareCapitalOf(int $borrowerId): float
    {
        return ShareCapitalLedger::balancesFor([$borrowerId])[$borrowerId];
    }

    public function test_up_to_tier_one_it_posts_the_savings_row_the_linked_share_capital_credit_and_one_journal(): void
    {
        $account = $this->account();
        $this->fund($account, 2_000_000);
        $this->actingAs($officer = $this->officer());
        $before = $this->shareCapitalOf($this->member->id);

        $response = $this->transfer($account->id, '3000.00')->assertCreated()
            ->assertJsonPath('data.transaction.type', 'share_capital_transfer')
            ->assertJsonPath('data.transaction.debit', '3000.00')
            ->assertJsonPath('data.transaction.balance_after', '17000.00')
            ->assertJsonPath('data.share_capital_entry.credit', '3000.00')
            ->assertJsonPath('data.share_capital_entry.method', 'savings_transfer')
            ->assertJsonPath('data.share_capital_balance', '3000.00');

        $row = SavingsTransaction::query()->where('type', 'share_capital_transfer')->sole();
        $entry = ShareCapitalLedger::query()->where('savings_transaction_id', $row->id)->sole();
        $this->assertSame($entry->id, $response->json('data.share_capital_entry.id'));
        $this->assertSame([$this->member->id, '3000.00', '0.00', 'savings_transfer', $row->reference, $officer->id, '2026-11-10'],
            [$entry->borrower_id, $entry->credit, $entry->debit, $entry->method, $entry->reference_number, $entry->created_by, $entry->date->toDateString()]);
        $this->assertSame(['savings', $officer->id], [$row->method, $row->created_by]);

        $this->assertSame([['2050', 300_000, 0], ['3060', 0, 300_000]], $this->journalLines($this->journalFor($row)));
        $this->assertSame(0, AccountingJournal::query()->where('postable_type', ShareCapitalLedger::class)->where('postable_id', $entry->id)->count());
        $this->assertEquals($before + 3000.0, $this->shareCapitalOf($this->member->id));
        $this->assertSame('17000.00', $this->balanceOf($account));
        $this->assertTrue(AuditLog::query()->where('action', 'savings_share_capital_transfer')->where('auditable_id', $row->id)->exists());
    }

    public function test_the_share_capital_balance_is_withheld_without_share_capital_view(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->userWith(['savings:view', 'savings:post', 'share_capital:create']));

        $this->transfer($account->id, '100.00')->assertCreated()->assertJsonPath('data.share_capital_balance', null);
    }

    public function test_the_poster_refuses_to_post_the_linked_share_capital_row_again(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());
        $this->transfer($account->id, '100.00')->assertCreated();
        $entry = ShareCapitalLedger::query()->whereNotNull('savings_transaction_id')->sole();

        $this->expectException(CannotPostToTheBooksException::class);
        app(AutomaticPoster::class)->shareCapitalEntry($entry, 'cash', $this->admin->id);
    }

    public function test_it_needs_savings_post_and_share_capital_create_and_another_branchs_account_is_404(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);

        $this->actingAs($this->userWith(['savings:view', 'savings:post']));
        $this->transfer($account->id, '100.00')->assertForbidden();
        $this->actingAs($this->userWith(['savings:view', 'savings:transact', 'share_capital:create']));
        $this->transfer($account->id, '100.00')->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:post', 'share_capital:create'], Branch::factory()->create()));
        $this->transfer($account->id, '100.00')->assertNotFound();

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'share_capital_transfer')->count());
    }

    public function test_former_and_inactive_members_are_refused_in_words(): void
    {
        $cases = [
            'withdrawn' => 'Maria Santos has withdrawn from the cooperative, so nothing can be transferred to share capital.',
            'deceased' => 'Maria Santos is recorded as deceased, so nothing can be transferred to share capital.',
            'inactive' => 'Maria Santos is not an active member (inactive), so nothing can be transferred to share capital.',
        ];
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());

        foreach ($cases as $status => $message) {
            $this->member->forceFill(['status' => $status])->save();
            $this->transfer($account->id, '100.00')->assertUnprocessable()->assertJsonPath('errors.account.0', $message);
        }

        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_an_account_in_another_branch_than_the_member_is_refused(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->member->forceFill(['branch_id' => Branch::factory()->create(['name' => 'Second Branch'])->id])->save();
        $this->actingAs($this->officer());

        $this->transfer($account->id, '100.00')->assertUnprocessable()
            ->assertJsonPath('errors.account.0', "{$account->account_number} is in another branch than Maria Santos's (Second Branch). A transfer to share capital stays in the member's own branch.");
    }

    public function test_an_account_that_cant_pay_out_is_refused(): void
    {
        $this->actingAs($this->officer());

        foreach (['frozen' => [], 'dormant' => ['dormant_since' => '2026-11-05'], 'closed' => ['closed_on' => '2026-11-09', 'open_key' => null]] as $status => $extra) {
            $account = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
            $this->fund($account, 500_000);
            $account->forceFill(['status' => $status, ...$extra])->save();

            $this->transfer($account->id, '100.00')->assertUnprocessable()->assertJsonValidationErrors('account');
        }

        $this->assertSame(0, SavingsTransaction::query()->where('type', 'share_capital_transfer')->count());
    }

    public function test_more_than_the_available_balance_is_refused(): void
    {
        $this->product->update(['maintaining_balance' => '100.00']);
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());

        $this->transfer($account->id, '4900.01')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "₱4,900.01 is more than the ₱4,900.00 available on {$account->account_number} (balance less holds, requests waiting for approval and the maintaining balance).");
        $this->transfer($account->id, '4900.00')->assertCreated();
    }

    public function test_a_product_that_allows_no_transfers_is_refused(): void
    {
        $this->product->update(['allow_transfers' => false]);
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());

        $this->transfer($account->id, '100.00')->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', "Regular Savings doesn't allow transfers to other accounts.");
    }

    public function test_above_tier_one_it_waits_for_a_checker_and_reserves_the_amount(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $this->actingAs($maker = $this->officer());

        $approval = $this->transfer($account->id, '10000.01')->assertStatus(202)
            ->assertJsonPath('data.approval.kind', 'share_capital_transfer')
            ->assertJsonPath('data.approval.required_checkers', 1)
            ->assertJsonPath('data.approval.amount', '10000.01')
            ->json('data.approval');

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->getJson("/api/savings/accounts/{$account->id}")->assertJsonPath('data.pending_amount', '10000.01')
            ->assertJsonPath('data.available_balance', '19999.99');

        $this->actingAs($checker = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $row = SavingsTransaction::query()->where('type', 'share_capital_transfer')->sole();
        $entry = ShareCapitalLedger::query()->where('savings_transaction_id', $row->id)->sole();
        $this->assertSame(['10000.01', $approval['id'], $maker->id], [$row->debit, $row->approval_id, $row->created_by]);
        $this->assertSame(['10000.01', $maker->id], [$entry->credit, $entry->created_by]);
        $this->assertSame($row->id, SavingsApproval::query()->findOrFail($approval['id'])->result_transaction_id);
        $this->assertSame([['2050', 1_000_001, 0], ['3060', 0, 1_000_001]], $this->journalLines($this->journalFor($row)));
        $this->assertSame($checker->id, $this->journalFor($row)->created_by);
        $this->assertSame('19999.99', $this->balanceOf($account));
    }

    public function test_tier_three_needs_two_different_checkers(): void
    {
        $account = $this->account();
        $this->fund($account, 6_000_000);
        $this->actingAs($this->officer());
        $approval = $this->transfer($account->id, '50000.01')->assertStatus(202)->assertJsonPath('data.approval.required_checkers', 2)->json('data.approval');

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(0, ShareCapitalLedger::query()->count());

        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('50000.01', ShareCapitalLedger::query()->sole()->credit);
    }

    public function test_the_approval_checks_the_member_again(): void
    {
        $account = $this->account();
        $this->fund($account, 3_000_000);
        $this->actingAs($this->officer());
        $approval = $this->transfer($account->id, '15000.00')->assertStatus(202)->json('data.approval');

        $this->member->forceFill(['status' => 'deceased'])->save();
        $this->actingAs($this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->assertSame('pending', SavingsApproval::query()->findOrFail($approval['id'])->status);
        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_a_reversal_is_refused_with_how_to_correct_it(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());
        $id = $this->transfer($account->id, '100.00')->assertCreated()->json('data.transaction.id');

        $this->actingAs($this->admin);
        $this->money("/api/savings/transactions/{$id}/reverse", ['reason' => 'Wrong account'])->assertUnprocessable()
            ->assertJsonPath('errors.transaction.0', 'A transfer to share capital can\'t be reversed. To correct one, record a share capital entry taking the amount back out of the member\'s share capital, then a deposit of it into their savings account.');
        $this->assertSame(0, SavingsApproval::query()->where('kind', 'reversal')->count());
    }

    public function test_an_idempotent_replay_posts_once(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());
        $key = (string) Str::uuid();

        $first = $this->transfer($account->id, '100.00', $key)->assertCreated();
        $this->transfer($account->id, '100.00', $key)->assertCreated()->assertHeader(IdempotentWrite::REPLAY_HEADER, 'true')
            ->assertJsonPath('data.transaction.id', $first->json('data.transaction.id'));
        $this->postJson("/api/savings/accounts/{$account->id}/share-capital-transfers", ['amount' => '100.00'])->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, ShareCapitalLedger::query()->count());
    }

    public function test_the_amount_is_validated_in_words(): void
    {
        $account = $this->account();
        $this->actingAs($this->officer());

        $this->transfer($account->id, '0')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'The amount must be above ₱0.00.');
        $this->transfer($account->id, '10.005')->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_cash_flow_and_the_ledger_gaps_leave_the_transfer_out_of_cash(): void
    {
        $account = $this->account();
        $this->fund($account, 500_000);
        $this->actingAs($this->officer());
        $this->transfer($account->id, '300.00')->assertCreated();

        $this->actingAs($this->admin);
        $flow = $this->getJson('/api/reports/cash-flow?date_from=2026-11-01&date_to=2026-11-30')->assertOk()->json('data');
        $this->assertEquals(0, $flow['share_capital']['credit']);
        $this->assertEquals(0, $flow['inflows']['share_capital_credit']);
        $this->assertEquals(300, $flow['non_cash']['share_capital_from_savings']);
        $this->assertEquals(0, collect($flow['by_branch'])->sum('inflow_share_capital'));

        $gaps = app(LedgerGapsReport::class)->page(['kind' => 'share_capital'], null, 1);
        $this->assertSame(0, $gaps['total']);
    }

    public function test_a_large_transfer_alerts_the_approvers_but_not_its_maker_or_checkers(): void
    {
        $account = $this->account();
        $this->fund($account, 60_000_000);
        DB::table('notifications')->delete();
        $watcher = $this->checker();
        $this->actingAs($maker = $this->officer());

        $approval = $this->transfer($account->id, '500000.00')->assertStatus(202)->json('data.approval');
        $this->assertSame([], $this->alerted());

        $this->actingAs($first = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk();
        $this->actingAs($second = $this->checker());
        $this->postJson("/api/savings/approvals/{$approval['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $told = $this->alerted();
        $this->assertContains($watcher->id, $told);
        foreach ([$maker->id, $first->id, $second->id] as $actor) {
            $this->assertNotContains($actor, $told);
        }

        $data = json_decode(DB::table('notifications')->where('type', 'savings_large_transaction')->where('notifiable_id', $watcher->id)->sole()->data, true);
        $this->assertStringContainsString('Transfer to share capital of ₱500,000.00', $data['body']);
    }

    /** @return list<int> who got a large-transaction alert */
    private function alerted(): array
    {
        return DB::table('notifications')->where('type', 'savings_large_transaction')
            ->where('notifiable_type', User::class)->orderBy('notifiable_id')->pluck('notifiable_id')->map(fn ($id) => (int) $id)->all();
    }
}
