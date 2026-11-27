<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\PeriodCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;

/**
 * A year of trading in three groups (Main Branch, Far Branch and journals
 * with no branch), fixed at 9 November 2026 in Manila so 2025 has ended and
 * 2026 hasn't. Centavos throughout.
 *
 * 2025 by group, as the closing entries must move them:
 * - Main: 4010 credit ₱90,000.00 less a ₱1,000.00 debit = ₱89,000.00;
 *   5020 debit ₱15,000.00. Net surplus ₱74,000.00.
 * - Far: 4010 ₱20,000.00, 4020 ₱1,000.50, 5010 ₱30,000.00. A loss of
 *   ₱8,999.50.
 * - No branch: 4070 ₱4,000.00, and 5010 with a ₱200.00 CREDIT net (an
 *   expense refunded), which the close debits. Net surplus ₱4,200.00.
 * - The year: ₱74,000.00 - ₱8,999.50 + ₱4,200.00 = ₱69,200.50.
 */
trait YearEndFixtures
{
    private Branch $far;

    private const MAIN_NET = 7400000;

    private const FAR_NET = -899950;

    private const NO_BRANCH_NET = 420000;

    private const YEAR_NET = 6920050;

    private function setUpYearEnd(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Manila'));
        $this->seedAndLogin();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    private function postTheYear(): void
    {
        $main = $this->branch->id;
        $far = $this->far->id;

        $this->postSimpleJournal('1010', '3010', 50000000, ['date' => '2025-03-01', 'branch_id' => $main]);
        $this->postSimpleJournal('1010', '4010', 9000000, ['date' => '2025-03-15', 'branch_id' => $main]);
        $this->postSimpleJournal('4010', '1010', 100000, ['date' => '2025-06-20', 'branch_id' => $main]);
        $this->postSimpleJournal('5020', '1010', 1500000, ['date' => '2025-09-10', 'branch_id' => $main]);

        $this->postSimpleJournal('1010', '4010', 2000000, ['date' => '2025-04-01', 'branch_id' => $far]);
        $this->postSimpleJournal('1010', '4020', 100050, ['date' => '2025-07-01', 'branch_id' => $far]);
        $this->postSimpleJournal('5010', '1010', 3000000, ['date' => '2025-12-15', 'branch_id' => $far]);

        $this->postSimpleJournal('1010', '4070', 400000, ['date' => '2025-05-05', 'branch_id' => null]);
        $this->postSimpleJournal('1010', '5010', 20000, ['date' => '2025-08-08', 'branch_id' => null]);

        // The following year: never part of 2025's close.
        $this->postSimpleJournal('1010', '4010', 77700, ['date' => '2026-01-10', 'branch_id' => $main]);
    }

    /** @return array<string, mixed> */
    private function preview(int $year = 2025): array
    {
        return $this->getJson("/api/accounting/year-closes/preview?year={$year}")->assertOk()->json('data');
    }

    private function closeYear(int $year = 2025): TestResponse
    {
        return $this->postJson('/api/accounting/year-closes', ['year' => $year]);
    }

    private function reopenYear(int $id, ?string $reason = 'Late supplier invoice for December'): TestResponse
    {
        return $this->postJson("/api/accounting/year-closes/{$id}/reopen", ['reason' => $reason]);
    }

    /** @return array<string, mixed> */
    private function closed(int $year = 2025): array
    {
        return $this->closeYear($year)->assertCreated()->json('data');
    }

    /**
     * A user holding exactly these permissions, in the given branches.
     *
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions, Branch ...$branches): User
    {
        $role = Role::firstOrCreate(['name' => 'year_end_'.md5(implode(',', $permissions)), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->inBranches(...($branches === [] ? [$this->branch] : $branches))->create();
        $user->assignRole($role);

        return $user;
    }

    private function period(string $code): AccountingPeriod
    {
        app(PeriodCalendar::class)->ensureProvisioned();

        return AccountingPeriod::query()->where('code', $code)->sole();
    }

    private function closePeriod(string $code): AccountingPeriod
    {
        return app(PeriodCalendar::class)->close($this->period($code), $this->admin);
    }
}
