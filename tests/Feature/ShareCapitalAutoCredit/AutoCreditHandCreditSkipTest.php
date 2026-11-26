<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\AutoCreditRun;
use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\ShareCapitalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: the auto-credit run skips a pledge whose member was
 * already credited by hand in the run's month (Manila), so a month is never
 * credited twice. The status (the run's preview) and the run list each skip
 * with its reason; the run stores its skipped list. Other months, other
 * pledges, and credits that weren't made by hand (a payment's build-up, a
 * release, a savings transfer, an import, an earlier run) don't count. Past
 * runs and entries stay as they are.
 */
class AutoCreditHandCreditSkipTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $ana;

    private Borrower $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));

        $this->ana = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Santos']);
        $this->ben = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Cruz']);
        // Once a month (on the 30th): a hand credit covers the whole month. A
        // pledge's default schedule is twice a month ("15/30"), tested below.
        $this->ana->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true, 'schedule' => '30']);
        $this->ben->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => true, 'schedule' => '30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_run_skips_a_pledge_credited_by_hand_this_month_and_says_why(): void
    {
        $this->handCredit($this->ana, '2026-11-05', 'CSH-0001-2026-0007');

        $response = $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.total_amount', 300)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.pledge_id', $this->ana->shareCapitalPledge->id)
            ->assertJsonPath('data.skipped.0.borrower_name', 'Ana Santos')
            ->assertJsonPath('data.skipped.0.amount', 500)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::handCreditedReason('2026-11-05', 'CSH-0001-2026-0007'));

        $this->assertSame(1, ShareCapitalLedger::query()->where('description', ShareCapitalService::AUTO_CREDIT_DESCRIPTION)->count());
        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $this->ben->id, 'credit' => 300, 'date' => '2026-11-24']);

        $run = AutoCreditRun::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, (int) $run->skipped_count);
        $this->assertDatabaseHas('auto_credit_run_skips', [
            'run_id' => $run->id,
            'pledge_id' => $this->ana->shareCapitalPledge->id,
            'borrower_id' => $this->ana->id,
            'amount' => 500,
            'reason' => ShareCapitalService::handCreditedReason('2026-11-05', 'CSH-0001-2026-0007'),
        ]);
    }

    public function test_the_reason_reads_in_words(): void
    {
        $this->assertSame(
            'Credited by hand on Nov 5, 2026 (CSH-0001-2026-0007), so auto-credit skips it this month.',
            ShareCapitalService::handCreditedReason('2026-11-05', 'CSH-0001-2026-0007'),
        );
        $this->assertSame(
            'Credited by hand on Nov 5, 2026, so auto-credit skips it this month.',
            ShareCapitalService::handCreditedReason('2026-11-05', null),
        );
    }

    public function test_the_status_previews_the_skip(): void
    {
        $this->handCredit($this->ana, '2026-11-01', null);

        $data = $this->getJson('/api/auto-credit/status')->assertOk()->json('data');

        $this->assertSame(1, $data['active_count']);
        $this->assertEquals(300.0, $data['total_to_credit']);
        $this->assertSame([$this->ben->shareCapitalPledge->id], array_column($data['active_members'], 'id'));
        $this->assertSame(1, $data['skipped_count']);
        $this->assertSame([[
            'id' => $this->ana->shareCapitalPledge->id,
            'borrower_name' => 'Ana Santos',
            'pledge_amount' => 500,
            'reason' => ShareCapitalService::handCreditedReason('2026-11-01', null),
        ]], $data['skipped_members']);
    }

    public function test_a_hand_credit_in_another_month_does_not_skip(): void
    {
        $this->handCredit($this->ana, '2026-10-31', null);
        Carbon::setTestNow(Carbon::parse('2026-12-01 00:30:00', 'Asia/Manila'));
        $this->handCredit($this->ben, '2026-11-30', null);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.skipped', []);
    }

    public function test_the_month_is_manilas(): void
    {
        // 2026-11-30 17:00 UTC is already Dec 1 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-30 17:00:00', 'UTC'));
        $this->handCredit($this->ana, '2026-11-30', null);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 2);
        $this->assertSame(2, ShareCapitalLedger::query()->where('date', '2026-12-01')->count());
    }

    public function test_a_hand_debit_does_not_skip(): void
    {
        $this->handCredit($this->ana, '2026-11-05', null, 'debit');

        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);
    }

    public function test_credits_not_made_by_hand_or_by_a_run_do_not_count(): void
    {
        // An imported row is history, not this month's instalment.
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->ben->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-02',
            'description' => 'Imported', 'debit' => 0, 'credit' => 300, 'is_imported' => true, 'created_by' => $this->admin->id,
        ]);

        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);
    }

    public function test_a_row_from_an_earlier_run_counts_as_that_months_instalment_linked_or_by_the_older_rule(): void
    {
        $linked = AutoCreditRun::query()->create([
            'total_amount' => 500, 'member_count' => 1, 'processed_at' => now(), 'processed_by' => $this->admin->id,
            'status' => 'completed', 'links_rows' => true,
        ]);
        // Linked to its run, whatever its description.
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->ana->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-02',
            'description' => 'Anything', 'debit' => 0, 'credit' => 500,
            'method' => 'bank_transfer', 'created_by' => $this->admin->id, 'auto_credit_run_id' => $linked->id,
        ]);
        // From a run before rows were linked: its description, up to that run.
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->ben->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-03',
            'description' => ShareCapitalService::AUTO_CREDIT_DESCRIPTION, 'debit' => 0, 'credit' => 300,
            'method' => 'bank_transfer', 'created_by' => $this->admin->id,
        ]);
        AutoCreditRun::query()->create([
            'total_amount' => 300, 'member_count' => 1, 'processed_at' => now()->addSecond(), 'processed_by' => $this->admin->id, 'status' => 'completed',
        ]);

        $this->postJson('/api/auto-credit/process')->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::alreadyCreditedReason([['auto_credit', '2026-11-02', null]]))
            ->assertJsonPath('data.skipped.1.reason', ShareCapitalService::alreadyCreditedReason([['auto_credit', '2026-11-03', null]]));
    }

    public function test_a_hand_entry_typed_with_the_runs_description_after_the_last_unlinked_run_counts_as_by_hand(): void
    {
        AutoCreditRun::query()->create([
            'total_amount' => 500, 'member_count' => 1, 'processed_at' => now()->subDay(), 'processed_by' => $this->admin->id, 'status' => 'completed',
        ]);
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->ana->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-24',
            'description' => ShareCapitalService::AUTO_CREDIT_DESCRIPTION, 'debit' => 0, 'credit' => 500,
            'method' => 'cash', 'reference_number' => 'CSH-0001-2026-0009', 'created_by' => $this->admin->id,
        ]);

        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.0.borrower_name', 'Ana Santos');
    }

    public function test_the_runs_rows_carry_their_run(): void
    {
        $id = $this->postJson('/api/auto-credit/process')->assertCreated()->json('data.id');

        $this->assertSame([$id, $id], ShareCapitalLedger::query()->orderBy('id')->pluck('auto_credit_run_id')->all());
        $this->assertTrue((bool) AutoCreditRun::query()->findOrFail($id)->links_rows);
    }

    public function test_a_hand_credit_debited_back_the_same_month_does_not_skip(): void
    {
        $this->handCredit($this->ana, '2026-11-05', 'CSH-0001-2026-0007');
        $this->handCredit($this->ana, '2026-11-06', 'CSH-0001-2026-0008', 'debit');

        $this->postJson('/api/auto-credit/process')->assertCreated()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonPath('data.skipped_count', 0);
    }

    public function test_a_hand_credit_only_partly_debited_back_still_skips(): void
    {
        $this->handCredit($this->ana, '2026-11-05', 'CSH-0001-2026-0007');
        ShareCapitalLedger::query()->create([
            'borrower_id' => $this->ana->id, 'branch_id' => $this->branch->id, 'date' => '2026-11-06',
            'description' => 'Correction', 'debit' => 499.99, 'credit' => 0, 'method' => 'cash', 'created_by' => $this->admin->id,
        ]);

        $this->postJson('/api/auto-credit/process')->assertCreated()
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::handCreditedReason('2026-11-05', 'CSH-0001-2026-0007'));
    }

    public function test_a_twice_monthly_pledge_hand_credited_on_the_14th_and_run_on_the_16th_and_30th_is_credited_twice_not_three_times(): void
    {
        $this->ana->shareCapitalPledge->update(['schedule' => '15/30']);
        $this->handCredit($this->ana, '2026-11-14', 'CSH-0001-2026-0007');

        // The "15th" run, done on Monday the 16th: the second instalment.
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Asia/Manila'));
        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);

        // The 30th run: two instalments are in already.
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Asia/Manila'));
        $this->postJson('/api/auto-credit/process')->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped.0.borrower_name', 'Ana Santos')
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::alreadyCreditedReason([
                ['hand', '2026-11-14', 'CSH-0001-2026-0007'],
                ['auto_credit', '2026-11-16', null],
            ]));

        $this->assertSame(2, $this->creditsOf($this->ana, '2026-11'));
    }

    public function test_two_runs_in_a_month_credit_a_monthly_pledge_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00', 'Asia/Manila'));
        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);

        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));
        $this->postJson('/api/auto-credit/process')->assertCreated()
            ->assertJsonPath('data.member_count', 0)
            ->assertJsonPath('data.skipped_count', 2)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::alreadyCreditedReason([['auto_credit', '2026-11-02', null]]));

        $this->assertSame(1, $this->creditsOf($this->ana, '2026-11'));

        // Next month it is credited again.
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', 'Asia/Manila'));
        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 2);
    }

    public function test_a_twice_monthly_pledge_with_no_hand_credit_is_credited_by_two_runs_and_no_third(): void
    {
        $this->ana->shareCapitalPledge->update(['schedule' => '15/30']);

        foreach (['2026-11-15', '2026-11-30'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 10:00:00', 'Asia/Manila'));
            $this->postJson('/api/auto-credit/process')->assertCreated();
        }
        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 0);

        $this->assertSame(2, $this->creditsOf($this->ana, '2026-11'));
        $this->assertSame(1, $this->creditsOf($this->ben, '2026-11'));
    }

    public function test_the_reason_lists_each_credit_in_words(): void
    {
        $this->assertSame(
            'Credited by hand on Nov 14, 2026 (CSH-0001-2026-0007) and by auto-credit on Nov 16, 2026, so auto-credit skips it this month.',
            ShareCapitalService::alreadyCreditedReason([['hand', '2026-11-14', 'CSH-0001-2026-0007'], ['auto_credit', '2026-11-16', null]]),
        );
        $this->assertSame(
            ShareCapitalService::handCreditedReason('2026-11-05', null),
            ShareCapitalService::alreadyCreditedReason([['hand', '2026-11-05', null]]),
        );
    }

    public function test_a_hand_credit_made_before_auto_credit_was_switched_on_skips_the_month(): void
    {
        $this->ana->shareCapitalPledge->update(['auto_credit' => false]);
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->ana->id, 'date' => '2026-11-10', 'description' => 'Monthly pledge at the counter',
            'type' => 'credit', 'amount' => 500,
        ])->assertCreated();
        $reference = ShareCapitalLedger::query()->sole()->reference_number;
        $this->patchJson("/api/pledges/{$this->ana->shareCapitalPledge->id}/auto-credit")->assertOk()->assertJsonPath('auto_credit', true);

        $this->postJson('/api/auto-credit/process')
            ->assertCreated()
            ->assertJsonPath('data.member_count', 1)
            ->assertJsonPath('data.skipped.0.reason', ShareCapitalService::handCreditedReason('2026-11-10', $reference));
    }

    public function test_past_runs_and_entries_stay(): void
    {
        $this->handCredit($this->ana, '2026-11-05', null);
        $pastRun = AutoCreditRun::query()->create([
            'total_amount' => 800, 'member_count' => 2, 'processed_at' => '2026-10-24 10:00:00',
            'processed_by' => $this->admin->id, 'status' => 'completed',
        ]);
        $entries = ShareCapitalLedger::query()->orderBy('id')->get()->toArray();
        $run = DB::table('auto_credit_runs')->where('id', $pastRun->id)->first();

        $this->postJson('/api/auto-credit/process')->assertCreated();

        $this->assertSame($entries, ShareCapitalLedger::query()->orderBy('id')->limit(count($entries))->get()->toArray());
        $this->assertEquals($run, DB::table('auto_credit_runs')->where('id', $pastRun->id)->first());
        $this->assertSame(0, DB::table('auto_credit_run_skips')->where('run_id', $pastRun->id)->count());
    }

    /** How many credit rows the member got in the month (YYYY-MM). */
    private function creditsOf(Borrower $member, string $month): int
    {
        return ShareCapitalLedger::query()->where('borrower_id', $member->id)->where('credit', '>', 0)
            ->where('date', 'like', $month.'-%')->count();
    }

    /**
     * A credit (or debit) recorded by hand, as the pledge's single entry
     * posts one, written directly so its date can be any day this month.
     */
    private function handCredit(Borrower $member, string $date, ?string $reference, string $type = 'credit'): void
    {
        ShareCapitalLedger::query()->create([
            'borrower_id' => $member->id,
            'branch_id' => $this->branch->id,
            'date' => $date,
            'description' => 'Manual '.$type.' entry',
            'debit' => $type === 'debit' ? 500 : 0,
            'credit' => $type === 'credit' ? 500 : 0,
            'method' => 'cash',
            'reference_number' => $reference,
            'created_by' => $this->admin->id,
        ]);
    }
}
