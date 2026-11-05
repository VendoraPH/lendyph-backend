<?php

namespace Tests\Feature\FxReports;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Member Report lists the accounts behind its cards (fix round fx,
 * decision D10). One definition for both:
 *  - Active: at least one collectible loan as of `date_to`;
 *  - New: the member was created in the period;
 *  - Repeat: at least 2 ever-released loans.
 */
class MemberReportListTest extends TestCase
{
    use SetupLendyPH;

    private const PERIOD = 'date_from=2026-03-01&date_to=2026-03-31';

    private Branch $reportBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->reportBranch = Branch::factory()->create(['name' => 'Report Branch']);
    }

    public function test_the_active_card_counts_collectible_loans_only(): void
    {
        $this->loan($this->member(), 'ongoing', '2026-01-05 09:00:00');
        $this->loan($this->member(), 'released', '2026-01-05 09:00:00');
        $this->loan($this->member(), 'defaulted', '2026-01-05 09:00:00');
        $this->loan($this->member(), 'completed', '2026-01-05 09:00:00');
        $this->loan($this->member(), 'restructured', '2026-01-05 09:00:00');
        $this->loan($this->member(), 'approved', null);

        $this->assertSame(3, $this->cards()['total_active_borrowers']);
    }

    public function test_active_is_as_of_date_to(): void
    {
        $this->loan($this->member(), 'ongoing', '2026-03-31 23:59:59');
        $this->loan($this->member(), 'ongoing', '2026-04-01 00:00:00');

        $this->assertSame(1, $this->cards()['total_active_borrowers']);
    }

    public function test_the_new_card_counts_members_created_in_the_period(): void
    {
        $this->member('2026-02-28 23:59:59');
        $this->member('2026-03-01 00:00:00');
        $this->member('2026-03-31 23:59:59');
        $this->member('2026-04-01 00:00:00');
        // A registration awaiting a decision is not a member yet.
        $this->member('2026-03-15 09:00:00', ['status' => 'pending']);
        $this->member('2026-03-15 09:00:00', ['status' => 'rejected']);

        $this->assertSame(2, $this->cards()['new_borrowers']);
    }

    public function test_the_repeat_card_counts_at_least_two_ever_released_loans(): void
    {
        $twice = $this->member();
        $this->loan($twice, 'completed', '2025-01-05 09:00:00');
        $this->loan($twice, 'restructured', '2025-06-05 09:00:00');

        $once = $this->member();
        $this->loan($once, 'completed', '2025-01-05 09:00:00');
        $this->loan($once, 'approved', null);

        $this->assertSame(1, $this->cards()['repeat_borrowers']);
    }

    public function test_rows_carry_the_contract_and_meta_counts_equal_the_cards(): void
    {
        $repeatActive = $this->member('2025-01-01 09:00:00', ['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Active', 'suffix' => null]);
        $this->loan($repeatActive, 'completed', '2025-02-05 09:00:00');
        $this->loan($repeatActive, 'ongoing', '2026-02-05 09:00:00');
        $this->loan($repeatActive, 'ongoing', '2026-03-05 09:00:00');

        $new = $this->member('2026-03-10 09:00:00', ['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'New', 'suffix' => null]);

        // Neither active nor new: a repeat member stays off the list, and on the card.
        $repeatOnly = $this->member('2025-01-01 09:00:00', ['first_name' => 'Cara', 'middle_name' => null, 'last_name' => 'Repeat', 'suffix' => null]);
        $this->loan($repeatOnly, 'completed', '2025-02-05 09:00:00');
        $this->loan($repeatOnly, 'completed', '2025-03-05 09:00:00');

        $response = $this->list()->assertOk();
        $rows = collect($response->json('data'))->keyBy('borrower_id');

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertCount(2, $rows);
        $this->assertFalse($rows->has($repeatOnly->id));

        $row = $rows[$repeatActive->id];
        $this->assertSame($repeatActive->borrower_code, $row['borrower_code']);
        $this->assertSame('Ana Active', $row['borrower_name']);
        $this->assertSame('Report Branch', $row['branch_name']);
        $this->assertTrue($row['is_active']);
        $this->assertFalse($row['is_new']);
        $this->assertTrue($row['is_repeat']);
        $this->assertSame(2, $row['active_loans']);
        $this->assertSame(3, $row['loans_released']);
        $this->assertSame('2026-03-05', $row['last_release_date']);

        $row = $rows[$new->id];
        $this->assertFalse($row['is_active']);
        $this->assertTrue($row['is_new']);
        $this->assertFalse($row['is_repeat']);
        $this->assertSame(0, $row['active_loans']);
        $this->assertSame(0, $row['loans_released']);
        $this->assertEquals(0, $row['outstanding'], 'A real ₱0.00.');
        $this->assertNull($row['last_release_date']);

        $cards = $this->cards();
        $this->assertSame([
            'active' => $cards['total_active_borrowers'],
            'new' => $cards['new_borrowers'],
            'repeat' => $cards['repeat_borrowers'],
        ], $response->json('meta.counts'));
        $this->assertSame(['active' => 1, 'new' => 1, 'repeat' => 2], $response->json('meta.counts'));
    }

    public function test_outstanding_is_everything_owed_on_the_active_loans(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['insurance_remaining_balance' => 400]);
        DB::table('amortization_schedules')->where('loan_id', $loan->id)->where('period_number', 1)
            ->update(['penalty_amount' => 121.85, 'interest_paid' => 100.10]);

        // A second, finished loan adds nothing.
        $this->loan($loan->borrower, 'completed', '2025-02-05 09:00:00');

        $expected = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.total_outstanding');

        $row = collect($this->getJson('/api/reports/borrowers/list?date_from=2020-01-01&date_to='.now()->toDateString())->json('data'))
            ->firstWhere('borrower_id', $loan->borrower_id);

        $this->assertEquals(70800 + 400 + 121.85 - 100.10, $expected);
        $this->assertEquals($expected, $row['outstanding']);
        $this->assertSame(1, $row['active_loans']);
        $this->assertSame(2, $row['loans_released']);
    }

    public function test_the_list_pages_in_name_order(): void
    {
        foreach (['Dela', 'Abad', 'Cruz'] as $last) {
            $this->member('2026-03-10 09:00:00', ['first_name' => 'A', 'middle_name' => null, 'last_name' => $last, 'suffix' => null]);
        }

        $first = $this->getJson('/api/reports/borrowers/list?'.self::PERIOD.'&per_page=2&page=1')->assertOk();
        $second = $this->getJson('/api/reports/borrowers/list?'.self::PERIOD.'&per_page=2&page=2')->assertOk();

        $this->assertSame(['A Abad', 'A Cruz'], array_column($first->json('data'), 'borrower_name'));
        $this->assertSame(['A Dela'], array_column($second->json('data'), 'borrower_name'));
        $this->assertSame(3, $first->json('meta.total'));
        $this->assertSame(['active' => 0, 'new' => 3, 'repeat' => 0], $second->json('meta.counts'), 'Counts cover the whole set.');
    }

    public function test_the_branch_filter_narrows_the_list_and_the_cards_alike(): void
    {
        $far = Branch::factory()->create();

        $here = $this->member('2026-03-10 09:00:00');
        $this->loan($here, 'ongoing', '2026-02-05 09:00:00');

        $there = $this->member('2026-03-10 09:00:00', ['branch_id' => $far->id]);
        $this->loan($there, 'ongoing', '2026-02-05 09:00:00', $far);

        // A member of this branch with a loan at the far branch: the far
        // branch sees the loan, and is not told about a new member of ours.
        $crossing = $this->member('2026-03-10 09:00:00');
        $this->loan($crossing, 'ongoing', '2026-02-05 09:00:00', $far);

        $response = $this->getJson('/api/reports/borrowers/list?'.self::PERIOD."&branch_id={$far->id}")->assertOk();
        $rows = collect($response->json('data'))->keyBy('borrower_id');

        $this->assertEqualsCanonicalizing([$there->id, $crossing->id], $rows->keys()->all());
        $this->assertTrue($rows[$there->id]['is_new']);
        $this->assertFalse($rows[$crossing->id]['is_new']);
        $this->assertSame(['active' => 2, 'new' => 1, 'repeat' => 0], $response->json('meta.counts'));

        $cards = $this->getJson('/api/reports/borrowers?'.self::PERIOD."&branch_id={$far->id}")->assertOk()->json('data');
        $this->assertSame(2, $cards['total_active_borrowers']);
        $this->assertSame(1, $cards['new_borrowers']);
    }

    public function test_a_branch_user_sees_their_branch_and_is_refused_another(): void
    {
        $far = Branch::factory()->create();
        $this->loan($this->member(), 'ongoing', '2026-02-05 09:00:00');
        $this->loan($this->member('2025-01-01 09:00:00', ['branch_id' => $far->id]), 'ongoing', '2026-02-05 09:00:00', $far);

        $role = Role::create(['name' => 'member_report_reader', 'guard_name' => 'web']);
        $role->givePermissionTo('reports:view');
        $user = User::factory()->inBranches($this->reportBranch)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertSame(1, $this->list()->assertOk()->json('meta.total'));
        $this->getJson('/api/reports/borrowers/list?'.self::PERIOD."&branch_id={$far->id}")->assertForbidden();
    }

    public function test_it_needs_reports_view(): void
    {
        $this->actingAs(User::factory()->create());

        $this->list()->assertForbidden();
    }

    public function test_a_bad_period_answers_422(): void
    {
        $this->getJson('/api/reports/borrowers/list?date_from=2026-03-31&date_to=2026-03-01')->assertUnprocessable();
        $this->getJson('/api/reports/borrowers/list?per_page=1001')->assertUnprocessable();
    }

    public function test_the_page_costs_the_same_queries_however_many_rows_it_has(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list()->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->loan($this->member(), 'ongoing', '2026-02-05 09:00:00');
        // The first request also loads the caller's permissions.
        $count();
        $few = $count();

        foreach (range(1, 6) as $i) {
            $member = $this->member('2026-03-10 09:00:00');
            $this->loan($member, 'ongoing', '2026-02-05 09:00:00');
            $this->loan($member, 'released', '2026-02-06 09:00:00');
        }

        $this->assertSame($few, $count());
    }

    /** @return array<string, mixed> */
    private function cards(): array
    {
        return $this->getJson('/api/reports/borrowers?'.self::PERIOD)->assertOk()->json('data');
    }

    private function list()
    {
        return $this->getJson('/api/reports/borrowers/list?'.self::PERIOD);
    }

    /** @param array<string, mixed> $attributes */
    private function member(string $createdAt = '2025-01-01 09:00:00', array $attributes = []): Borrower
    {
        $member = Borrower::factory()->create(array_merge(['branch_id' => $this->reportBranch->id], $attributes));
        DB::table('borrowers')->where('id', $member->id)->update(['created_at' => $createdAt]);

        return $member->fresh();
    }

    private function loan(Borrower $member, string $status, ?string $releasedAt, ?Branch $branch = null): Loan
    {
        return Loan::factory()->create([
            'borrower_id' => $member->id,
            'branch_id' => ($branch ?? $this->reportBranch)->id,
            'created_by' => $this->admin->id,
            'status' => $status,
            'released_at' => $releasedAt,
        ]);
    }
}
