<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingExpense;
use App\Models\AccountingReconciliation;
use App\Models\AccountingReconciliationLine;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\User;
use App\Rules\BranchTakesNew;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE2, review of #339: a closed branch takes nothing new on any
 * write path, and a refusal never names a branch the caller can't see (nor
 * any branch, to an anonymous caller).
 */
class BranchClosedWritePathsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private const CLOSED = 'Branch "Far Branch" (FAR) is closed, so nothing new can be added there.';

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    // Closed: every other write path -------------------------------------

    public function test_a_closed_branch_takes_no_expense_transfer_or_walk_in_gcash(): void
    {
        $this->close();

        $this->postJson('/api/accounting/expenses', [
            'date' => now()->toDateString(), 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'payment_account_id' => $this->account('1010'), 'branch_id' => $this->far->id,
        ])->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSED);

        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => now()->toDateString(), 'from_account_id' => $this->account('1010'), 'to_account_id' => $this->account('1040'),
            'amount' => 50000, 'description' => 'Cash to bank', 'branch_id' => $this->far->id,
        ])->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSED);

        $this->postJson('/api/gcash/transactions', ['gcash_non_member_id' => 1, 'type' => 'cash_in', 'amount' => 1000, 'branch_id' => $this->far->id])
            ->assertUnprocessable()->assertJsonPath('errors.branch_id.0', self::CLOSED);
    }

    public function test_a_member_in_a_closed_branch_gets_no_gcash_but_one_in_a_suspended_branch_does_not_wait(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']));
        $this->close();

        $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1000])
            ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', self::CLOSED);

        DB::table('branches')->where('id', $this->far->id)->update(['status' => 'suspended']);
        $response = $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1000]);
        $this->assertArrayNotHasKey('borrower_id', $response->json('errors') ?? []);
    }

    public function test_a_new_user_is_not_put_in_a_closed_branch(): void
    {
        $this->close();

        $this->postJson('/api/users', [
            'username' => 'newcashier', 'first_name' => 'New', 'last_name' => 'Cashier', 'email' => 'new@example.test',
            'role' => 'cashier', 'branch_ids' => [$this->far->id],
        ])->assertUnprocessable()->assertJsonPath('errors', fn (array $errors): bool => in_array(self::CLOSED, $errors['branch_ids.0'] ?? [], true));
    }

    public function test_a_closed_branchs_expense_is_not_paid(): void
    {
        $expense = AccountingExpense::factory()->create(['branch_id' => $this->far->id, 'payment_account_id' => null]);
        $this->close();

        $this->postJson("/api/accounting/expenses/{$expense->id}/pay", ['date' => now()->toDateString(), 'amount' => 100, 'account_id' => $this->account('1010')])
            ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSED);
    }

    public function test_a_closed_branchs_reconciliation_takes_no_action(): void
    {
        $reconciliation = AccountingReconciliation::create([
            'accounting_account_id' => $this->account('1040'), 'branch_id' => $this->far->id, 'period' => 'September 2026',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'statement_balance' => 0, 'created_by' => $this->admin->id,
        ]);
        $line = AccountingReconciliationLine::create([
            'accounting_reconciliation_id' => $reconciliation->id, 'date' => '2026-09-12', 'description' => 'Service charge', 'amount' => -15000, 'created_by' => $this->admin->id,
        ]);
        $this->close();

        foreach (['expense' => ['expense_account_id' => $this->account('5030')], 'transfer' => ['counter_account_id' => $this->account('1010')], 'suspense' => []] as $action => $body) {
            $this->postJson("/api/accounting/reconciliations/{$reconciliation->id}/lines/{$line->id}/{$action}", $body)
                ->assertUnprocessable()->assertJsonPath('errors.branch.0', self::CLOSED);
        }
    }

    // Hidden branches are never named ------------------------------------

    public function test_a_closed_branch_the_caller_cannot_see_is_never_named(): void
    {
        $this->close();
        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(['journals:create', 'expenses:create', 'gcash:transact', 'users:create', 'users:update']);
        $this->actingAs($user);

        $responses = [
            $this->postJson('/api/accounting/journals', ['date' => now()->toDateString(), 'description' => 'x', 'branch_id' => $this->far->id, 'lines' => []]),
            $this->postJson('/api/accounting/expenses', ['date' => now()->toDateString(), 'payee' => 'x', 'branch_id' => $this->far->id]),
            $this->postJson('/api/gcash/transactions', ['gcash_non_member_id' => 1, 'type' => 'cash_in', 'amount' => 1000, 'branch_id' => $this->far->id]),
            $this->postJson('/api/users', ['username' => 'x', 'role' => 'cashier', 'branch_ids' => [$this->far->id]]),
        ];

        foreach ($responses as $response) {
            $this->assertHidesTheBranch($response);
        }
    }

    public function test_a_public_registration_never_reads_a_branch_name(): void
    {
        $this->close();
        auth()->forgetGuards();

        $response = $this->postJson('/api/borrowers', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'birthdate' => '1990-01-01', 'gender' => 'female', 'civil_status' => 'single',
            'contact_number' => '09171234567', 'street_address' => '1 Rizal St', 'city' => 'Iloilo City', 'province' => 'Iloilo',
            'status' => 'pending', 'branch_id' => $this->far->id,
        ])->assertUnprocessable()->assertJsonPath('errors.branch_id.0', BranchTakesNew::PUBLIC_REFUSAL);

        $this->assertHidesTheBranch($response);
    }

    private function close(): void
    {
        DB::table('branches')->where('id', $this->far->id)->update(['status' => 'closed']);
    }

    private function assertHidesTheBranch(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('Far Branch', $body);
        $this->assertStringNotContainsString('FAR', $body);
    }
}
