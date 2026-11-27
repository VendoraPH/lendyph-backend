<?php

namespace Tests\Feature\Savings\TimeDeposits;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\Borrower;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Savings\SavingsLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\SetupLendyPH;

/**
 * Shared set-up for the time deposit tests (slice S3): a cooperative chart
 * (so every savings posting role is mapped), the clock on 2026-11-10 10:00
 * Manila, an active member in the seeded branch, a regular product at 1% and
 * a time deposit product at 3% on 90, 180 and 360 days whose early
 * terminations pay the regular product's rate.
 */
trait TimeDepositFixtures
{
    use SetupLendyPH;

    protected Borrower $member;

    protected SavingsProduct $regular;

    protected SavingsProduct $product;

    protected function setUpTimeDeposits(): void
    {
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');

        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->regular = SavingsProduct::factory()->create();
        $this->rate($this->regular, null, '1.0000');
        $this->product = SavingsProduct::factory()->timeDeposit()->create(['pretermination_product_id' => $this->regular->id]);

        foreach ([90, 180, 360] as $term) {
            $this->rate($this->product, $term, '3.0000');
        }
    }

    protected function tearDownTimeDeposits(): void
    {
        Carbon::setTestNow();
    }

    protected function rate(SavingsProduct $product, ?int $term, string $rate, ?string $from = null): SavingsProductRate
    {
        return SavingsProductRate::query()->create([
            'product_id' => $product->id,
            'term_days' => $term,
            'annual_rate' => $rate,
            'effective_from' => $from ?? now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
    }

    /** @return array<string, mixed> */
    protected function placementBody(array $overrides = []): array
    {
        return [
            'borrower_id' => $this->member->id,
            'product_id' => $this->product->id,
            'principal' => '100000.00',
            'term_days' => 90,
            'placement_date' => now()->toDateString(),
            'maturity_instruction' => 'payout',
            'payment_method' => 'cash',
            ...$overrides,
        ];
    }

    /** POST with a fresh (or given) Idempotency-Key. */
    protected function idem(string $uri, array $body, ?string $key = null): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function place(array $overrides = []): TestResponse
    {
        return $this->idem('/api/savings/time-deposits', $this->placementBody($overrides));
    }

    /** A regular savings account for the member holding $centavos, deposited in cash today. */
    protected function savingsAccount(int $centavos = 0, ?Borrower $member = null): SavingsAccount
    {
        $member ??= $this->member;
        $account = SavingsAccount::factory()->create([
            'borrower_id' => $member->id,
            'product_id' => $this->regular->id,
            'branch_id' => $member->branch_id,
            'opened_on' => now()->toDateString(),
        ]);

        if ($centavos > 0) {
            DB::transaction(fn () => app(SavingsLedger::class)->credit($account, 'deposit', $centavos, ['method' => 'cash']));
        }

        return $account->refresh();
    }

    /** A user whose only role holds exactly these permissions, assigned to the seeded branch only. */
    protected function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'td_probe_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->branches()->sync([$this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @return list<array{0: string, 1: int, 2: int}> code, debit, credit (centavos) */
    protected function lines(AccountingJournal $journal): array
    {
        return $journal->lines()->with('account')->orderBy('id')->get()
            ->map(fn ($line) => [$line->account->code, (int) $line->debit, (int) $line->credit])
            ->all();
    }

    protected function journalFor(string $type, int $id, string $source): ?AccountingJournal
    {
        return AccountingJournal::query()->where('postable_type', $type)->where('postable_id', $id)->where('source', $source)->first();
    }

    /** Close the accounting period holding $date. */
    protected function closePeriodOf(string $date): void
    {
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse($date)->startOfMonth()) + [
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->admin->id,
        ]);
    }

    protected function savingsRows(SavingsAccount $account): int
    {
        return SavingsTransaction::query()->where('account_id', $account->id)->count();
    }
}
