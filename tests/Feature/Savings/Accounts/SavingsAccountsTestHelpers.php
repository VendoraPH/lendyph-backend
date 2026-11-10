<?php

namespace Tests\Feature\Savings\Accounts;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsLedger;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared set-up for the accounts and teller tests (slice S2): a cooperative
 * chart (so savings can post), an active regular product paying 3.65% a
 * year (so ten days on ₱5,000.00 earn exactly ₱5.00), an active member in
 * the seeded branch, and "today" pinned to Nov 10, 2026 in Manila.
 */
trait SavingsAccountsTestHelpers
{
    protected SavingsProduct $product;

    protected Borrower $member;

    protected function setUpSavings(bool $pinToday = true): void
    {
        $this->seedAndLogin();

        if ($pinToday) {
            Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        }

        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        $this->product = SavingsProduct::factory()->create(['name' => 'Regular Savings', 'minimum_opening_balance' => '500.00']);
        SavingsProductRate::factory()->create(['product_id' => $this->product->id, 'annual_rate' => '3.6500', 'effective_from' => '2026-01-01']);
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos', 'suffix' => null]);
    }

    /** Run `$then` with the clock on `$date` (10:00 Manila), then put the clock back as it was. */
    protected function on(string $date, Closure $then): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse("{$date} 10:00:00", 'Asia/Manila'));

        try {
            return $then();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /** An open account on the test product, opened on Nov 1. */
    protected function account(array $overrides = []): SavingsAccount
    {
        return SavingsAccount::factory()->create([
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'branch_id' => $this->member->branch_id,
            'opened_on' => '2026-11-01',
            'last_member_activity_on' => '2026-11-01',
            ...$overrides,
        ]);
    }

    /** Deposit through the ledger and the books, as the teller does, on `$date`. */
    protected function fund(SavingsAccount $account, int $centavos, string $date = '2026-11-10'): SavingsTransaction
    {
        return $this->on($date, fn () => DB::transaction(function () use ($account, $centavos) {
            $row = app(SavingsLedger::class)->credit($account, 'deposit', $centavos, ['method' => 'cash']);
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);

            return $row;
        }));
    }

    /** A money post with a fresh (or the given) Idempotency-Key. */
    protected function money(string $uri, array $body, ?string $key = null): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    /** A user whose only role holds exactly these permissions, in `$branch` (the seeded one by default). */
    protected function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'savings_s2_probe_'.Str::random(8), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => ($branch ?? $this->branch)->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** A teller (view, transact, close) and a checker (view, approve). */
    protected function teller(): User
    {
        return $this->userWith(['savings:view', 'savings:create', 'savings:transact', 'savings:close']);
    }

    protected function checker(): User
    {
        return $this->userWith(['savings:view', 'savings:approve']);
    }

    /** @return list<array{0: string, 1: int, 2: int}> code, debit, credit */
    protected function journalLines(AccountingJournal $journal): array
    {
        return $journal->lines()->with('account')->orderBy('id')->get()
            ->map(fn ($line) => [$line->account->code, (int) $line->debit, (int) $line->credit])
            ->all();
    }

    protected function journalFor(SavingsTransaction $row, string $source = 'savings'): ?AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', SavingsTransaction::class)
            ->where('postable_id', $row->id)
            ->where('source', $source)
            ->first();
    }

    protected function balanceOf(SavingsAccount $account): string
    {
        return (string) $account->refresh()->ledger_balance;
    }
}
