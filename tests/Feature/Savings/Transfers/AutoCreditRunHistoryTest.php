<?php

namespace Tests\Feature\Savings\Transfers;

use App\Models\AccountingAccount;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsAutoCreditInstruction;
use App\Models\SavingsAutoCreditRun;
use App\Models\SavingsAutoCreditSkip;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\Accounting\AccountMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Past savings auto-credit runs (gap round B7): GET /savings/auto-credit/runs,
 * paged, newest first, and one run's posted and skipped lists. `savings:view`.
 * A head office user (`branches:view_all`) sees every run; a branch user sees
 * the runs that posted to or skipped their branches' accounts, with the counts
 * and totals of those accounts only, and another run answers 404.
 */
class AutoCreditRunHistoryTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    private Branch $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'Asia/Manila'));
        $this->elsewhere = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_head_office_sees_the_whole_run_with_its_settlement_account_and_who_ran_it(): void
    {
        $this->twoBranchRun();

        $rows = $this->getJson('/api/savings/auto-credit/runs')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $bank = AccountingAccount::query()->findOrFail(AccountMap::resolve()->accountFor('bank'));
        $this->assertSame([
            'cycle_date' => '2026-11-15',
            'posted_on' => '2026-11-15',
            'method' => 'bank_transfer',
            'method_label' => 'Bank transfer',
            'settlement_account' => ['id' => $bank->id, 'code' => $bank->code, 'name' => $bank->name],
            'posted_count' => 2,
            'posted_total' => '350.00',
            'skipped_count' => 2,
            'skips_recorded' => true,
            'run_by' => ['id' => $this->admin->id, 'name' => $this->admin->full_name],
        ], collect($rows[0])->only(['cycle_date', 'posted_on', 'method', 'method_label', 'settlement_account', 'posted_count', 'posted_total', 'skipped_count', 'skips_recorded', 'run_by'])->all());
    }

    public function test_a_branch_user_sees_only_their_branches_part_of_a_run(): void
    {
        [$ours, $oursFrozen] = $this->twoBranchRun();
        $this->actingAs($this->userWith(['savings:view']));

        $row = $this->getJson('/api/savings/auto-credit/runs')->assertOk()->json('data.0');
        $this->assertSame([1, '100.00', 1], [$row['posted_count'], $row['posted_total'], $row['skipped_count']]);

        $run = $this->getJson("/api/savings/auto-credit/runs/{$row['id']}")->assertOk()->json('data');
        $this->assertSame([$ours->account_number], array_column($run['posted'], 'account_number'));
        $this->assertSame('100.00', $run['posted'][0]['amount']);
        $this->assertSame('Credited', $run['posted'][0]['status_label']);
        $this->assertSame([$oursFrozen->account_number], array_column($run['skipped'], 'account_number'));
        $this->assertSame("{$oursFrozen->account_number} is frozen and takes no deposits until it is unfrozen.", $run['skipped'][0]['reason']);
        $this->assertSame('Maria Santos', $run['skipped'][0]['borrower_name']);
    }

    public function test_a_run_that_touched_none_of_a_branch_users_accounts_is_hidden_from_them(): void
    {
        $theirs = $this->theirAccount();
        $this->instruct($theirs, '100.00');
        $runId = $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated()->json('data.run_id');

        $this->actingAs($this->userWith(['savings:view']));

        $this->assertSame([], $this->getJson('/api/savings/auto-credit/runs')->assertOk()->json('data'));
        $this->getJson("/api/savings/auto-credit/runs/{$runId}")->assertNotFound();
    }

    public function test_runs_are_paged_newest_first(): void
    {
        $account = $this->account();
        $this->instruct($account, '100.00', '15/30');
        $this->on('2026-11-15', fn () => $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated());
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Manila'));
        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-30'])->assertCreated();

        $page = $this->getJson('/api/savings/auto-credit/runs?per_page=1')->assertOk();
        $this->assertSame('2026-11-30', $page->json('data.0.cycle_date'));
        $this->assertSame(2, $page->json('meta.total'));
        $this->assertSame(1, $page->json('meta.per_page'));
        $this->assertSame('2026-11-15', $this->getJson('/api/savings/auto-credit/runs?per_page=1&page=2')->json('data.0.cycle_date'));
    }

    public function test_a_run_from_before_skips_were_recorded_says_so(): void
    {
        $run = SavingsAutoCreditRun::query()->create([
            'cycle_date' => '2026-11-15', 'posted_on' => '2026-11-15', 'method' => 'cash',
            'posted_count' => 0, 'posted_total' => '0.00', 'skipped_count' => 3, 'created_by' => $this->admin->id,
        ]);

        $data = $this->getJson("/api/savings/auto-credit/runs/{$run->id}")->assertOk()->json('data');

        $this->assertFalse($data['skips_recorded']);
        $this->assertSame(3, $data['skipped_count']);
        $this->assertNull($data['skipped']);
        $this->assertSame([], $data['posted']);
        $this->assertNull($data['settlement_account']);
        $this->assertSame('Cash', $data['method_label']);
    }

    public function test_it_needs_savings_view(): void
    {
        $this->actingAs($this->userWith(['savings:post']));

        $this->getJson('/api/savings/auto-credit/runs')->assertForbidden();
        $this->getJson('/api/savings/auto-credit/runs/1')->assertForbidden();
    }

    public function test_every_skip_of_a_run_is_recorded(): void
    {
        $this->twoBranchRun();

        $this->assertSame(2, SavingsAutoCreditSkip::query()->count());
        $this->assertTrue(SavingsAutoCreditRun::query()->sole()->skips_recorded);
    }

    public function test_an_instruction_a_run_skipped_is_kept_for_the_record(): void
    {
        $frozen = $this->account(['status' => 'frozen']);
        $instruction = $this->instruct($frozen, '100.00');
        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated()->assertJsonPath('data.totals.skipped_count', 1);

        $this->deleteJson("/api/savings/auto-credit/instructions/{$instruction->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.instruction.0', 'This instruction was skipped by an auto-credit run, so it is kept for the record. Deactivate it instead.');

        $this->assertNotNull($instruction->fresh());
    }

    public function test_the_list_reads_its_figures_in_sql_in_a_fixed_number_of_queries(): void
    {
        $viewer = $this->userWith(['savings:view']);
        $this->instruct($this->account(), '100.00', '15/30');
        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated();
        $one = $this->listQueries($viewer);

        foreach (range(1, 4) as $i) {
            $this->instruct($this->account(['product_id' => SavingsProduct::factory()->create(['name' => "Payroll Savings {$i}"])->id]), '100.00', '15/30');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Manila'));
        $this->actingAs($this->admin);
        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-30'])->assertCreated();
        $two = $this->listQueries($viewer);

        $this->assertSame(count($one), count($two), 'Two runs with six credits read in as many queries as one run with one credit.');

        // The counts, the ₱ total and the first credit come from grouped SQL: no posting, account or member rows are loaded.
        foreach ($two as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^select \* from `(savings_auto_credit_postings|savings_accounts|borrowers)`/', $sql);
        }

        $this->actingAs($viewer);
        $rows = $this->getJson('/api/savings/auto-credit/runs')->assertOk()->json('data');
        $this->assertSame([5, '500.00'], [$rows[0]['posted_count'], $rows[0]['posted_total']]);
        $this->assertSame([1, '100.00'], [$rows[1]['posted_count'], $rows[1]['posted_total']]);
        $this->assertNotNull($rows[0]['settlement_account']);
    }

    /** @return list<string> the SQL the list ran, as `$viewer`, after one warm-up request */
    private function listQueries(User $viewer): array
    {
        $this->actingAs($viewer);
        $this->getJson('/api/savings/auto-credit/runs')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/savings/auto-credit/runs')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    }

    /**
     * One head office run over both branches: here, one credit (₱100.00) and
     * one frozen account skipped; elsewhere, one credit (₱250.00) and one
     * frozen account skipped.
     *
     * @return array{0: SavingsAccount, 1: SavingsAccount}
     */
    private function twoBranchRun(): array
    {
        $ours = $this->account();
        $oursFrozen = $this->account(['status' => 'frozen', 'product_id' => SavingsProduct::factory()->create(['name' => 'Payroll Savings'])->id]);
        $theirs = $this->theirAccount();
        $theirsFrozen = $this->theirAccount(['status' => 'frozen']);
        $this->instruct($ours, '100.00');
        $this->instruct($oursFrozen, '100.00');
        $this->instruct($theirs, '250.00');
        $this->instruct($theirsFrozen, '250.00');

        $this->actingAs($this->admin);
        $this->money('/api/savings/auto-credit/process', ['cycle_date' => '2026-11-15'])->assertCreated();

        return [$ours, $oursFrozen];
    }

    /** @param  array<string, mixed>  $overrides */
    private function theirAccount(array $overrides = []): SavingsAccount
    {
        return $this->account([
            'branch_id' => $this->elsewhere->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->elsewhere->id])->id,
            ...$overrides,
        ]);
    }

    private function instruct(SavingsAccount $account, string $amount, string $schedule = '15'): SavingsAutoCreditInstruction
    {
        return SavingsAutoCreditInstruction::query()->create([
            'account_id' => $account->id,
            'amount' => $amount,
            'schedule' => $schedule,
            'active' => true,
            'authorised_on' => '2026-11-01',
            'authorised_by' => 'The member',
            'created_by' => $this->admin->id,
        ]);
    }
}
