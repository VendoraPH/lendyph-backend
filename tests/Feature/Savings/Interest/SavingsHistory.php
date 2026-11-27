<?php

namespace Tests\Feature\Savings\Interest;

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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Savings history for the interest-run and report tests: users holding
 * exact permissions, a cooperative chart, regular products with a rate, and
 * deposits and withdrawals written through SavingsLedger and posted through
 * AutomaticPoster, as the teller does. The ledger back-dates only to
 * yesterday, so history is built by moving the clock (travel()).
 */
trait SavingsHistory
{
    private int $roleCounter = 0;

    protected function cooperativeBooks(): void
    {
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
    }

    /** Today in Manila, at 10:00. */
    protected function onDay(string $date): void
    {
        Carbon::setTestNow(Carbon::parse($date.' 10:00:00', 'Asia/Manila'));
    }

    /** @param  list<string>  $permissions */
    protected function userWith(array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['name' => 'savings_s4_probe_'.(++$this->roleCounter).'_'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => ($branch ?? $this->branch)->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function regularProduct(array $attributes = [], ?string $rate = '3.6500'): SavingsProduct
    {
        $product = SavingsProduct::factory()->create(array_merge(['interest_crediting' => 'quarterly'], $attributes));

        if ($rate !== null) {
            SavingsProductRate::factory()->create(['product_id' => $product->id, 'annual_rate' => $rate, 'effective_from' => '2026-01-01']);
        }

        return $product;
    }

    /** An account opened today, with an opening deposit when one is given. */
    protected function openAccount(SavingsProduct $product, int $openingCentavos = 0, ?Branch $branch = null, array $attributes = []): SavingsAccount
    {
        $borrower = Borrower::factory()->create(['branch_id' => ($branch ?? $this->branch)->id]);
        $account = SavingsAccount::factory()->create(array_merge([
            'borrower_id' => $borrower->id,
            'product_id' => $product->id,
            'opened_on' => now()->toDateString(),
        ], $attributes));

        if ($openingCentavos > 0) {
            $this->deposit($account, $openingCentavos);
        }

        return $account->refresh();
    }

    protected function deposit(SavingsAccount $account, int $centavos, array $attributes = []): SavingsTransaction
    {
        return DB::transaction(function () use ($account, $centavos, $attributes) {
            $row = app(SavingsLedger::class)->credit($account, 'deposit', $centavos, array_merge(['method' => 'cash'], $attributes));
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);

            return $row;
        });
    }

    protected function withdraw(SavingsAccount $account, int $centavos, array $attributes = []): SavingsTransaction
    {
        return DB::transaction(function () use ($account, $centavos, $attributes) {
            $row = app(SavingsLedger::class)->debit($account, 'withdrawal', $centavos, array_merge(['method' => 'cash'], $attributes));
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);

            return $row;
        });
    }
}
