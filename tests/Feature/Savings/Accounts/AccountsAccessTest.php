<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Who may call the accounts and teller endpoints (design §2, §3): each one
 * refuses (403) a caller without its permission and lets one holding exactly
 * it through; and another branch's account, transaction, hold or approval
 * reads as not found (404) on every `{id}` route, even with every savings
 * permission.
 */
class AccountsAccessTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private const ALL = ['savings:view', 'savings:create', 'savings:transact', 'savings:close', 'savings:approve', 'savings:post', 'savings:settings'];

    private SavingsAccount $savings;

    private SavingsTransaction $deposit;

    private SavingsHold $hold;

    private SavingsApproval $approval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        $this->savings = $this->account();
        $this->deposit = $this->fund($this->savings, 100_000);
        $this->hold = SavingsHold::factory()->create(['account_id' => $this->savings->id, 'amount' => '1.00']);
        $this->approval = SavingsApproval::factory()->create(['account_id' => $this->savings->id, 'amount' => '1.00', 'requested_by' => $this->admin->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: list<string>}> method, uri, body, the permissions that let it through */
    private function calls(): array
    {
        $a = $this->savings->id;
        $other = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);

        return [
            ['getJson', '/api/savings/accounts', [], ['savings:view']],
            ['money', '/api/savings/accounts', ['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id, 'product_id' => $this->product->id, 'opened_at' => '2026-11-10', 'initial_deposit' => '500', 'payment_method' => 'cash'], ['savings:create']],
            ['getJson', "/api/savings/accounts/{$a}", [], ['savings:view']],
            ['getJson', "/api/savings/accounts/{$a}/transactions", [], ['savings:view']],
            ['money', "/api/savings/accounts/{$a}/deposits", ['amount' => '1', 'date' => '2026-11-10', 'payment_method' => 'cash'], ['savings:transact']],
            ['money', "/api/savings/accounts/{$a}/withdrawals", ['amount' => '1', 'date' => '2026-11-10', 'payment_method' => 'cash'], ['savings:transact']],
            ['money', "/api/savings/accounts/{$a}/transfers", ['to_account_id' => $other->id, 'amount' => '1', 'date' => '2026-11-10'], ['savings:transact']],
            ['getJson', "/api/savings/accounts/{$a}/closing-quote", [], ['savings:close']],
            ['money', "/api/savings/accounts/{$a}/close", ['date' => '2026-11-10', 'reason' => 'x', 'payout_method' => 'cash'], ['savings:close']],
            ['postJson', "/api/savings/accounts/{$a}/status", ['status' => 'frozen', 'reason' => 'x'], ['savings:close']],
            ['getJson', "/api/savings/accounts/{$a}/holds", [], ['savings:view']],
            ['money', "/api/savings/accounts/{$a}/holds", ['type' => 'manual', 'amount' => '1', 'reason' => 'x'], ['savings:transact']],
            ['postJson', "/api/savings/holds/{$this->hold->id}/release", ['reason' => 'x'], ['savings:transact']],
            ['getJson', '/api/savings/transactions', [], ['savings:view']],
            ['getJson', "/api/savings/transactions/{$this->deposit->id}", [], ['savings:view']],
            ['money', "/api/savings/transactions/{$this->deposit->id}/reverse", ['reason' => 'x'], ['savings:transact']],
            ['getJson', '/api/savings/approvals', [], ['savings:view']],
            ['postJson', "/api/savings/approvals/{$this->approval->id}/approve", [], ['savings:approve']],
            ['postJson', "/api/savings/approvals/{$this->approval->id}/reject", ['reason' => 'x'], ['savings:approve']],
        ];
    }

    public function test_each_endpoint_answers_403_without_its_permission_and_lets_exactly_it_through(): void
    {
        $calls = $this->calls();

        foreach ($calls as [$method, $uri, $body, $allowed]) {
            $this->actingAs($this->userWith(['dashboard:view', ...array_values(array_diff(self::ALL, $allowed))]));
            $this->{$method}($uri, $body)->assertForbidden();
        }

        foreach ($calls as [$method, $uri, $body, $allowed]) {
            $this->actingAs($this->userWith($allowed));
            $status = $this->{$method}($uri, $body)->status();
            $this->assertNotSame(403, $status, "{$method} {$uri} refused a caller holding ".implode(' and ', $allowed).'.');
            $this->assertLessThan(500, $status, "{$method} {$uri} answered {$status}.");
        }
    }

    public function test_cancel_belongs_to_the_maker_alone(): void
    {
        $this->actingAs($this->userWith(self::ALL));
        $this->postJson("/api/savings/approvals/{$this->approval->id}/cancel")->assertForbidden();

        $this->actingAs($this->admin);
        $this->postJson("/api/savings/approvals/{$this->approval->id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_another_branchs_records_read_as_not_found_on_every_id_route(): void
    {
        $elsewhere = Branch::factory()->create();
        $outsider = $this->userWith(self::ALL, $elsewhere);
        $this->actingAs($outsider);

        foreach ($this->calls() as [$method, $uri, $body]) {
            if (! preg_match('#/\d+(/|$)#', $uri)) {
                continue;
            }

            $this->{$method}($uri, $body)->assertNotFound();
        }

        $own = $this->account(['branch_id' => $elsewhere->id, 'borrower_id' => Borrower::factory()->create(['branch_id' => $elsewhere->id])->id]);
        $this->money("/api/savings/accounts/{$own->id}/transfers", ['to_account_id' => $this->savings->id, 'amount' => '1', 'date' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('to_account_id');

        $this->assertSame([$own->id], array_column($this->getJson('/api/savings/accounts')->assertOk()->json('data'), 'id'));
        $this->assertSame(0, $this->getJson('/api/savings/transactions')->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->getJson('/api/savings/approvals?status=pending')->assertOk()->json('meta.total'));
        $this->getJson("/api/savings/accounts?branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_a_caller_without_any_branch_sees_nothing(): void
    {
        $nobody = User::factory()->create(['branch_id' => null, 'username' => 'nobranch_'.Str::random(6)]);
        $role = Role::create(['name' => 'savings_s2_nobranch', 'guard_name' => 'web']);
        $role->givePermissionTo(self::ALL);
        $nobody->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($nobody);

        $this->assertSame(0, $this->getJson('/api/savings/accounts')->assertOk()->json('meta.total'));
        $this->getJson("/api/savings/accounts/{$this->savings->id}")->assertNotFound();
    }
}
