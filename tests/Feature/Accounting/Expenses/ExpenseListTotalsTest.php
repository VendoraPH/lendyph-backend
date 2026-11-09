<?php

namespace Tests\Feature\Accounting\Expenses;

use App\Models\AccountingExpense;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The expenses list's search, amount due, totals and status counts (Phase 2,
 * Accounting 10). The screen pages on the server, so every figure it shows
 * comes from `meta` or the row, never from a browser sum.
 */
class ExpenseListTotalsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    public function test_each_expense_carries_its_amount_due_on_the_list_and_on_show(): void
    {
        $expense = AccountingExpense::factory()->create([
            'branch_id' => $this->branch->id, 'amount' => 100050, 'amount_paid' => 25025, 'settlement_status' => 'partially_paid',
        ]);

        $row = collect($this->getJson('/api/accounting/expenses')->assertOk()->json('data'))->firstWhere('id', $expense->id);
        $this->assertSame(75025, $row['amount_due']);

        $this->getJson("/api/accounting/expenses/{$expense->id}")->assertOk()->assertJsonPath('data.amount_due', 75025);
    }

    public function test_totals_cover_every_page_for_each_status_with_overdue_by_manila_today(): void
    {
        // 07:30 in Manila on the 2nd is still the 1st in UTC: a bill due on the
        // 1st is overdue and one due on the 2nd is not.
        $this->travelTo(CarbonImmutable::parse('2026-10-02 07:30:00', 'Asia/Manila'));

        $this->expense(1000, 0, 'unpaid', null);
        $this->expense(2000, 0, 'unpaid', '2026-10-02');
        $this->expense(4000, 1000, 'partially_paid', null);
        $this->expense(8000, 0, 'unpaid', '2026-10-01');
        $this->expense(16000, 4000, 'partially_paid', '2026-09-30');
        $this->expense(32000, 32000, 'paid', '2026-09-01');

        $expected = [
            'unpaid' => ['count' => 2, 'amount' => 3000, 'amount_paid' => 0, 'amount_due' => 3000],
            'partially_paid' => ['count' => 1, 'amount' => 4000, 'amount_paid' => 1000, 'amount_due' => 3000],
            'overdue' => ['count' => 2, 'amount' => 24000, 'amount_paid' => 4000, 'amount_due' => 20000],
            'paid' => ['count' => 1, 'amount' => 32000, 'amount_paid' => 32000, 'amount_due' => 0],
        ];

        foreach ($expected as $status => $totals) {
            $meta = $this->getJson("/api/accounting/expenses?status={$status}&per_page=1")->assertOk()->json('meta');
            $this->assertSame($totals, $meta['totals'], $status);
        }

        $first = $this->getJson('/api/accounting/expenses?per_page=2')->assertOk();
        $rows = [];
        for ($page = 1; $page <= $first->json('meta.last_page'); $page++) {
            $rows = array_merge($rows, $this->getJson("/api/accounting/expenses?per_page=2&page={$page}")->json('data'));
        }

        $all = $first->json('meta.totals');
        $this->assertSame(6, $all['count']);
        $this->assertSame(array_sum(array_column($rows, 'amount')), $all['amount']);
        $this->assertSame(array_sum(array_column($rows, 'amount_paid')), $all['amount_paid']);
        $this->assertSame(array_sum(array_column($rows, 'amount_due')), $all['amount_due']);
        $this->assertSame(26000, $all['amount_due']);
    }

    public function test_stats_count_each_status_with_every_filter_but_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'Asia/Manila'));

        $this->expense(1000, 0, 'unpaid', null);
        $this->expense(4000, 1000, 'partially_paid', null);
        $this->expense(8000, 0, 'unpaid', '2026-10-01');
        $this->expense(32000, 32000, 'paid', null);
        $this->expense(500, 0, 'unpaid', null, ['date' => '2026-08-01']);

        $stats = ['unpaid' => 1, 'partially_paid' => 1, 'paid' => 1, 'overdue' => 1];

        $this->assertSame($stats, $this->getJson('/api/accounting/expenses?from=2026-09-01&status=paid')->assertOk()->json('meta.stats'));
        $this->assertSame($stats, $this->getJson('/api/accounting/expenses?from=2026-09-01')->assertOk()->json('meta.stats'));
        $this->assertSame(
            ['unpaid' => 2, 'partially_paid' => 1, 'paid' => 1, 'overdue' => 1],
            $this->getJson('/api/accounting/expenses?status=overdue')->assertOk()->json('meta.stats'),
        );
    }

    public function test_search_matches_payee_or_reference_literally(): void
    {
        $percent = $this->expense(100, 0, 'unpaid', null, ['payee' => 'Meralco 50% rebate']);
        $this->expense(100, 0, 'unpaid', null, ['payee' => 'Meralco 500 rebate']);
        $underscore = $this->expense(100, 0, 'unpaid', null, ['payee' => 'PLDT', 'reference' => 'INV_9']);
        $this->expense(100, 0, 'unpaid', null, ['payee' => 'Globe', 'reference' => 'INVX9']);

        $this->assertSame([$percent->id], $this->ids('search=50%25'));
        $this->assertSame([$underscore->id], $this->ids('search=INV_9'));
        $this->assertSame([$percent->id], $this->ids('search=%25'));
        $this->assertSame([], $this->ids('search='.urlencode('\\')));

        // A literal backslash: only `A\B` is found, never `AB`.
        $backslash = $this->expense(100, 0, 'unpaid', null, ['payee' => 'Shop A\\B']);
        $this->expense(100, 0, 'unpaid', null, ['payee' => 'Shop AB']);
        $this->assertSame([$backslash->id], $this->ids('search='.urlencode('A\\B')));

        $meta = $this->getJson('/api/accounting/expenses?search=Meralco')->assertOk()->json('meta');
        $this->assertSame(2, $meta['totals']['count']);
        $this->assertSame(2, $meta['stats']['unpaid']);
    }

    public function test_a_branch_users_list_totals_and_stats_stay_in_their_branches(): void
    {
        $this->expense(1000, 0, 'unpaid', null, ['branch_id' => $this->branch->id]);
        $far = $this->expense(20000, 0, 'unpaid', null, ['branch_id' => $this->far->id]);
        $this->expense(300000, 0, 'unpaid', null, ['branch_id' => null]);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $user->givePermissionTo('expenses:view');
        $this->actingAs($user);

        $meta = $this->getJson('/api/accounting/expenses')->assertOk()->json('meta');
        $this->assertSame(['count' => 1, 'amount' => 1000, 'amount_paid' => 0, 'amount_due' => 1000], $meta['totals']);
        $this->assertSame(1, $meta['stats']['unpaid']);

        $this->getJson("/api/accounting/expenses?branch_id={$this->far->id}")->assertForbidden();
        $this->getJson("/api/accounting/expenses/{$far->id}")->assertNotFound();

        $this->actingAs($this->admin);
        $this->assertSame(321000, $this->getJson('/api/accounting/expenses')->json('meta.totals.amount_due'));
    }

    /** @param  array<string, mixed>  $overrides */
    private function expense(int $amount, int $paid, string $settlement, ?string $dueDate, array $overrides = []): AccountingExpense
    {
        return AccountingExpense::factory()->create(array_merge([
            'date' => '2026-09-18',
            'branch_id' => $this->branch->id,
            'amount' => $amount,
            'amount_paid' => $paid,
            'settlement_status' => $settlement,
            'due_date' => $dueDate,
        ], $overrides));
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        $ids = array_column($this->getJson("/api/accounting/expenses?per_page=100&{$query}")->assertOk()->json('data'), 'id');
        sort($ids);

        return $ids;
    }
}
