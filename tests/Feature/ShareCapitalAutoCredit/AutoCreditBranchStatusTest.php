<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Round 9: a share capital auto-credit run records the branches it covered
 * (`auto_credit_run_branches`): its caller's BranchAccess branches, every
 * branch for `branches:view_all`. The status counts a run only for those
 * branches, so a cycle one branch ran never reads as done for another:
 *
 * - `cycle_branches`: each branch of the caller's members on auto-credit, by
 *   name, with the cycle's latest run that covered it;
 * - `cycle_run`: the latest of those runs once every listed branch has one;
 * - `last_run`: the latest run that covered one of the caller's branches, its
 *   totals shown only when it covered nothing outside them;
 * - a run's `scope` says what it covered: `all` (view_all) every branch and
 *   members with no branch, who are a last "No branch" row for view_all;
 *   `branches` exactly its recorded ones;
 * - runs made before (no scope) count for view_all only;
 * - a caller with no branch and no view_all can't run it (422).
 */
class AutoCreditBranchStatusTest extends TestCase
{
    use SetupLendyPH;

    private const CYCLE = '2026-11-15';

    /** "Main Branch", the seeded one. */
    private Branch $main;

    private Branch $east;

    private Borrower $ana;

    private Borrower $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->at('2026-11-16 09:00:00');

        $this->main = $this->branch;
        $this->east = Branch::factory()->create(['name' => 'East Branch', 'code' => 'EST']);
        $this->ana = $this->member($this->main, 'Ana', 500);
        $this->ben = $this->member($this->east, 'Ben', 300);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_branch_users_run_records_only_their_branches_and_a_view_all_run_records_every_branch(): void
    {
        $closed = Branch::factory()->create(['name' => 'Old Branch', 'code' => 'OLD', 'is_active' => false, 'status' => Branch::STATUS_CLOSED]);

        $branchRun = $this->runAs($this->staff($this->main))->json('data.id');
        $this->assertSame([$this->main->id], $this->branchesOf($branchRun));
        $this->assertSame(AutoCreditRun::SCOPE_BRANCHES, AutoCreditRun::query()->findOrFail($branchRun)->scope);

        $this->at('2026-11-16 10:00:00');
        $wholeRun = $this->runAs($this->admin)->json('data.id');
        $this->assertSame(Branch::query()->orderBy('id')->pluck('id')->all(), $this->branchesOf($wholeRun));
        $this->assertContains($closed->id, $this->branchesOf($wholeRun));
        $this->assertSame(AutoCreditRun::SCOPE_ALL, AutoCreditRun::query()->findOrFail($wholeRun)->scope);
    }

    public function test_a_run_in_branch_a_leaves_branch_b_not_done(): void
    {
        $mainStaff = $this->staff($this->main);
        $eastStaff = $this->staff($this->east);
        $run = $this->runAs($mainStaff)->json('data.id');

        $this->statusAs($eastStaff)
            ->assertJsonPath('data.cycle_run', null)
            ->assertJsonPath('data.cycle_branches', [
                ['branch_id' => $this->east->id, 'branch_name' => 'East Branch', 'run_id' => null, 'processed_at' => null],
            ])
            ->assertJsonPath('data.active_count', 1);

        $this->statusAs($mainStaff)
            ->assertJsonPath('data.cycle_run.id', $run)
            ->assertJsonPath('data.cycle_branches', [
                ['branch_id' => $this->main->id, 'branch_name' => 'Main Branch', 'run_id' => $run, 'processed_at' => '2026-11-16 09:00:00'],
            ]);
    }

    public function test_head_office_reads_the_cycle_done_only_once_runs_cover_every_branch(): void
    {
        $mainRun = $this->runAs($this->staff($this->main))->json('data.id');

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_run', null)
            ->assertJsonPath('data.cycle_branches', [
                ['branch_id' => $this->east->id, 'branch_name' => 'East Branch', 'run_id' => null, 'processed_at' => null],
                ['branch_id' => $this->main->id, 'branch_name' => 'Main Branch', 'run_id' => $mainRun, 'processed_at' => '2026-11-16 09:00:00'],
            ]);

        $this->at('2026-11-16 11:00:00');
        $eastRun = $this->runAs($this->staff($this->east))->json('data.id');

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_run.id', $eastRun)
            ->assertJsonPath('data.cycle_run.processed_at', '2026-11-16 11:00:00')
            ->assertJsonPath('data.cycle_branches.0.run_id', $eastRun)
            ->assertJsonPath('data.cycle_branches.1.run_id', $mainRun);
    }

    public function test_a_head_office_run_covers_every_branch(): void
    {
        $run = $this->runAs($this->admin)->json('data.id');

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_run.id', $run)
            ->assertJsonPath('data.cycle_branches.0.run_id', $run)
            ->assertJsonPath('data.cycle_branches.1.run_id', $run);
        $this->statusAs($this->staff($this->east))
            ->assertJsonPath('data.cycle_run.id', $run)
            ->assertJsonPath('data.cycle_branches.0.run_id', $run);
    }

    public function test_cycle_branches_is_empty_and_cycle_run_any_covering_run_when_no_member_is_on_auto_credit(): void
    {
        $this->ana->shareCapitalPledge->update(['auto_credit' => false]);
        $mainStaff = $this->staff($this->main);

        $this->statusAs($mainStaff)->assertJsonPath('data.cycle_branches', [])->assertJsonPath('data.cycle_run', null);

        $eastRun = $this->runAs($this->staff($this->east))->json('data.id');
        // East's run covers none of Main's branches; head office has only East to cover.
        $this->statusAs($mainStaff)->assertJsonPath('data.cycle_run', null);
        $this->statusAs($this->admin)->assertJsonPath('data.cycle_run.id', $eastRun);

        $this->at('2026-11-16 10:00:00');
        $mainRun = $this->runAs($mainStaff)->json('data.id');
        $this->statusAs($mainStaff)->assertJsonPath('data.cycle_branches', [])->assertJsonPath('data.cycle_run.id', $mainRun);
    }

    public function test_runs_made_before_branches_were_recorded_count_for_view_all_only(): void
    {
        $old = $this->oldRun('2026-11-16 08:00:00');
        $mainStaff = $this->staff($this->main);

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_run.id', $old->id)
            ->assertJsonPath('data.cycle_branches.0.run_id', $old->id)
            ->assertJsonPath('data.cycle_branches.1.run_id', $old->id)
            ->assertJsonPath('data.last_run.id', $old->id)
            ->assertJsonPath('data.last_run.member_count', 1);
        $this->statusAs($mainStaff)
            ->assertJsonPath('data.cycle_run', null)
            ->assertJsonPath('data.cycle_branches.0.run_id', null)
            ->assertJsonPath('data.last_run', null);

        // A recorded run later doesn't turn the old one into a branch's run.
        $this->runAs($this->staff($this->east));
        $this->statusAs($mainStaff)->assertJsonPath('data.cycle_run', null)->assertJsonPath('data.last_run', null);
        $this->statusAs($this->admin)->assertJsonPath('data.cycle_branches.1.run_id', $old->id);

    }

    public function test_last_run_and_its_totals_follow_the_branches_it_covered(): void
    {
        $mainStaff = $this->staff($this->main);
        $eastStaff = $this->staff($this->east);
        $wholeRun = $this->runAs($this->admin)->json('data.id');

        $this->statusAs($mainStaff)
            ->assertJsonPath('data.last_run.id', $wholeRun)
            ->assertJsonPath('data.last_run.total_amount', null)
            ->assertJsonPath('data.last_run.member_count', null)
            ->assertJsonPath('data.last_run.skipped_count', null);

        $this->at('2026-11-30 10:00:00');
        $mainRun = $this->runAs($mainStaff, '2026-11-30')->json('data.id');

        $this->statusAs($mainStaff, '2026-11-30')
            ->assertJsonPath('data.last_run.id', $mainRun)
            ->assertJsonPath('data.last_run.total_amount', 500)
            ->assertJsonPath('data.last_run.member_count', 1)
            ->assertJsonPath('data.last_run.skipped_count', 0);
        $this->statusAs($eastStaff, '2026-11-30')
            ->assertJsonPath('data.last_run.id', $wholeRun)
            ->assertJsonPath('data.last_run.total_amount', null);
        $this->statusAs($this->admin, '2026-11-30')
            ->assertJsonPath('data.last_run.id', $mainRun)
            ->assertJsonPath('data.last_run.member_count', 1);

        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->givePermissionTo('share_capital:view');
        $this->statusAs($nobody, '2026-11-30')->assertJsonPath('data.last_run', null)->assertJsonPath('data.cycle_branches', []);
    }

    public function test_a_user_of_two_branches_sees_the_totals_of_a_run_by_one_of_them(): void
    {
        $run = $this->runAs($this->staff($this->main))->json('data.id');

        $this->statusAs($this->staff($this->main, $this->east))
            ->assertJsonPath('data.last_run.id', $run)
            ->assertJsonPath('data.last_run.total_amount', 500)
            ->assertJsonPath('data.last_run.member_count', 1)
            ->assertJsonPath('data.last_run.skipped_count', 0)
            ->assertJsonPath('data.cycle_run', null);
    }

    public function test_a_one_branch_user_never_sees_the_totals_of_a_wider_run_or_a_head_office_run(): void
    {
        $mainStaff = $this->staff($this->main);
        $wider = $this->runAs($this->staff($this->main, $this->east))->json('data.id');
        $this->assertSame([$this->main->id, $this->east->id], $this->branchesOf($wider));

        $this->statusAs($mainStaff)
            ->assertJsonPath('data.last_run.id', $wider)
            ->assertJsonPath('data.last_run.total_amount', null)
            ->assertJsonPath('data.last_run.member_count', null)
            ->assertJsonPath('data.last_run.skipped_count', null)
            ->assertJsonPath('data.cycle_run.id', $wider);

        $this->at('2026-11-30 10:00:00');
        $headOffice = $this->runAs($this->admin, '2026-11-30')->json('data.id');

        $this->statusAs($mainStaff, '2026-11-30')
            ->assertJsonPath('data.last_run.id', $headOffice)
            ->assertJsonPath('data.last_run.total_amount', null)
            ->assertJsonPath('data.last_run.member_count', null)
            ->assertJsonPath('data.cycle_run.id', $headOffice);
    }

    public function test_members_with_no_branch_are_a_head_office_row_only_a_head_office_run_covers(): void
    {
        $this->member(null, 'Cy', 200);
        $mainStaff = $this->staff($this->main);
        $noBranch = ['branch_id' => null, 'branch_name' => 'No branch', 'run_id' => null, 'processed_at' => null];

        $this->statusAs($this->admin)
            ->assertJsonCount(3, 'data.cycle_branches')
            ->assertJsonPath('data.cycle_branches.2', $noBranch)
            ->assertJsonPath('data.cycle_run', null);

        $this->runAs($mainStaff);
        $this->runAs($this->staff($this->east));

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_branches.2', $noBranch)
            ->assertJsonPath('data.cycle_run', null);
        $this->statusAs($mainStaff)
            ->assertJsonCount(1, 'data.cycle_branches')
            ->assertJsonPath('data.cycle_branches.0.branch_name', 'Main Branch');

        $this->at('2026-11-16 12:00:00');
        $headOffice = $this->runAs($this->admin)->json('data.id');

        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_branches.2', [...$noBranch, 'run_id' => $headOffice, 'processed_at' => '2026-11-16 12:00:00'])
            ->assertJsonPath('data.cycle_run.id', $headOffice);
        $this->statusAs($mainStaff)->assertJsonCount(1, 'data.cycle_branches');
    }

    public function test_the_status_reads_a_moved_member_by_their_current_branch(): void
    {
        $mainStaff = $this->staff($this->main);
        $run = $this->runAs($mainStaff)->json('data.id');
        $this->ana->update(['branch_id' => $this->east->id]);

        $this->statusAs($this->staff($this->east))
            ->assertJsonPath('data.cycle_branches', [
                ['branch_id' => $this->east->id, 'branch_name' => 'East Branch', 'run_id' => null, 'processed_at' => null],
            ])
            ->assertJsonPath('data.cycle_run', null);
        $this->statusAs($this->admin)
            ->assertJsonCount(1, 'data.cycle_branches')
            ->assertJsonPath('data.cycle_branches.0.branch_id', $this->east->id)
            ->assertJsonPath('data.cycle_run', null);
        // Main has no member on auto-credit now; its own run still counts for it.
        $this->statusAs($mainStaff)
            ->assertJsonPath('data.cycle_branches', [])
            ->assertJsonPath('data.cycle_run.id', $run);
    }

    public function test_a_run_by_a_user_with_no_branch_is_refused_and_writes_nothing(): void
    {
        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->givePermissionTo(['share_capital:view', 'auto_credit:process']);

        $this->actingAs($nobody)->postJson('/api/auto-credit/process', ['cycle_date' => self::CYCLE])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', 'No branch is assigned to your account, so there are no members to credit.');

        $this->assertSame(0, AutoCreditRun::query()->count());
        $this->assertSame(0, DB::table('auto_credit_run_branches')->count());
        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->statusAs($this->admin)
            ->assertJsonPath('data.cycle_run', null)
            ->assertJsonPath('data.cycle_branches.0.run_id', null)
            ->assertJsonPath('data.cycle_branches.1.run_id', null)
            ->assertJsonPath('data.last_run', null);
    }

    public function test_a_run_that_fails_records_no_branches(): void
    {
        ShareCapitalLedger::creating(fn () => throw new RuntimeException('Posting failed.'));

        $this->actingAs($this->staff($this->main));
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/auto-credit/process', ['cycle_date' => self::CYCLE]);
            $this->fail('The run should have failed.');
        } catch (RuntimeException $failed) {
            $this->assertSame('Posting failed.', $failed->getMessage());
        }

        $this->assertSame(0, AutoCreditRun::query()->count());
        $this->assertSame(0, DB::table('auto_credit_run_branches')->count());
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'Asia/Manila'));
    }

    private function member(?Branch $branch, string $first, int $amount): Borrower
    {
        $member = Borrower::factory()->create(['branch_id' => $branch?->id, 'first_name' => $first, 'status' => 'active']);
        $member->shareCapitalPledge->update(['amount' => $amount, 'auto_credit' => true, 'schedule' => '15/30']);

        return $member;
    }

    /** A staff member of `$branches` only (no branches:view_all) who can run auto-credit. */
    private function staff(Branch ...$branches): User
    {
        $user = User::factory()->inBranches(...$branches)->create();
        $user->givePermissionTo(['share_capital:view', 'auto_credit:process']);

        return $user;
    }

    private function runAs(User $user, string $cycle = self::CYCLE): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/auto-credit/process', ['cycle_date' => $cycle])->assertCreated();
    }

    private function statusAs(User $user, string $cycle = self::CYCLE): TestResponse
    {
        return $this->actingAs($user)->getJson('/api/auto-credit/status?cycle_date='.$cycle)->assertOk();
    }

    /** A run with no recorded branches, as runs made before round 9 are. */
    private function oldRun(string $processedAt): AutoCreditRun
    {
        return AutoCreditRun::query()->create([
            'total_amount' => 800, 'member_count' => 1, 'skipped_count' => 0, 'links_rows' => true,
            'processed_at' => Carbon::parse($processedAt, 'Asia/Manila'), 'processed_by' => $this->admin->id,
            'status' => 'completed', 'cycle_date' => self::CYCLE,
        ]);
    }

    /** @return list<int> */
    private function branchesOf(int $runId): array
    {
        return DB::table('auto_credit_run_branches')->where('auto_credit_run_id', $runId)->orderBy('branch_id')
            ->pluck('branch_id')->map(fn ($id): int => (int) $id)->all();
    }
}
