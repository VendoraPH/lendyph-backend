<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Collateral;
use App\Models\CollateralType;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The data migration that corrects share capital collateral attached at ₱0
 * while the frontend valued every share capital balance as ₱0.
 *
 * Deployed databases already hold these rows when it lands, so each test
 * writes the rows the bug left behind straight into the tables, with the
 * timestamps the history would have, then runs up() the way a deploy does.
 */
class CorrectZeroShareCapitalSnapshotsTest extends TestCase
{
    use SetupLendyPH;

    private const MIGRATION = 'migrations/2026_09_30_130000_correct_zero_share_capital_collateral_snapshots.php';

    private int $referenceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function member(): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function shareCapitalCollateral(Borrower $member): Collateral
    {
        return Collateral::factory()->create([
            'borrower_id' => $member->id,
            'collateral_type_id' => CollateralType::where('source', 'share_capital')->firstOrFail()->id,
            'detail_value' => 'SC-PLEDGE-'.$member->id,
            'amount' => 0,
        ]);
    }

    private function loanFor(Borrower $member): Loan
    {
        return Loan::factory()->create([
            'borrower_id' => $member->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * A ledger entry dated `$date` and recorded at `$recordedAt`, which are
     * different things: an operator can date an entry in the past.
     */
    private function ledger(Borrower $member, string $date, float $credit, Carbon $recordedAt, float $debit = 0): int
    {
        return DB::table('share_capital_ledger')->insertGetId([
            'borrower_id' => $member->id,
            'date' => $date,
            'description' => 'Share capital',
            'reference' => 'SC-TEST-'.str_pad((string) ++$this->referenceSequence, 6, '0', STR_PAD_LEFT),
            'debit' => $debit,
            'credit' => $credit,
            'created_by' => $this->admin->id,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ]);
    }

    private function attach(Loan $loan, Collateral $collateral, float $snapshot, ?Carbon $attachedAt): int
    {
        return DB::table('loan_collaterals')->insertGetId([
            'loan_id' => $loan->id,
            'collateral_id' => $collateral->id,
            'snapshot_value' => $snapshot,
            'attached_at' => $attachedAt,
            'created_at' => $attachedAt ?? now(),
            'updated_at' => $attachedAt ?? now(),
        ]);
    }

    private function snapshotOf(int $loanCollateralId): float
    {
        return (float) DB::table('loan_collaterals')->where('id', $loanCollateralId)->value('snapshot_value');
    }

    private function corrections(): int
    {
        return AuditLog::where('action', 'collateral_snapshot_corrected')->count();
    }

    /**
     * @return array<int, object> the plan rows keyed by loan_collateral_id
     */
    private function planByRow(): array
    {
        $rows = [];

        foreach ($this->migration()->plan() as $row) {
            $rows[(int) $row->loan_collateral_id] = $row;
        }

        return $rows;
    }

    /**
     * Member with ₱1,500 on the ledger when the collateral was attached and
     * ₱700 more afterwards, attached at ₱0.
     *
     * @return array{0: Loan, 1: int}
     */
    private function affectedAttachment(): array
    {
        $member = $this->member();
        $attachedAt = Carbon::parse('2026-09-10 10:00:00');

        $this->ledger($member, '2026-08-01', 1000, Carbon::parse('2026-08-01 09:00:00'));
        $this->ledger($member, '2026-09-05', 500, Carbon::parse('2026-09-05 14:00:00'));
        $this->ledger($member, '2026-09-20', 700, Carbon::parse('2026-09-20 11:00:00'));

        $loan = $this->loanFor($member);

        return [$loan, $this->attach($loan, $this->shareCapitalCollateral($member), 0, $attachedAt)];
    }

    public function test_an_affected_attachment_gets_the_members_balance_as_of_when_it_was_attached(): void
    {
        [, $row] = $this->affectedAttachment();

        $this->migration()->up();

        // ₱1,500 as of the attach, not today's ₱2,200.
        $this->assertEqualsWithDelta(1500.00, $this->snapshotOf($row), 0.001);
    }

    public function test_each_change_is_audited_as_a_system_change_against_the_loan(): void
    {
        [$loan, $row] = $this->affectedAttachment();

        $this->migration()->up();

        $entry = AuditLog::where('action', 'collateral_snapshot_corrected')->sole();

        $this->assertNull($entry->user_id, 'A system change names no user.');
        $this->assertSame(Loan::class, $entry->auditable_type);
        $this->assertSame($loan->id, $entry->auditable_id);
        $this->assertSame($row, $entry->old_values['loan_collateral_id']);
        $this->assertEqualsWithDelta(0.0, $entry->old_values['snapshot_value'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $entry->new_values['snapshot_value'], 0.001);
        $this->assertSame('2026-09-10 10:00:00', $entry->new_values['valued_as_of']);
        $this->assertStringStartsWith('System correction:', $entry->description);
        $this->assertNull($entry->ip_address);
    }

    public function test_an_attachment_already_holding_its_value_is_left_alone(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 2500, Carbon::parse('2026-09-01 09:00:00'));
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 2500, Carbon::parse('2026-09-02 09:00:00'));

        $this->assertArrayNotHasKey($row, $this->planByRow());

        $this->migration()->up();

        $this->assertEqualsWithDelta(2500.00, $this->snapshotOf($row), 0.001);
        $this->assertSame(0, $this->corrections());
    }

    public function test_a_member_with_no_share_capital_at_the_time_keeps_zero(): void
    {
        $member = $this->member();
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, Carbon::parse('2026-09-10 10:00:00'));
        // Every entry came later, and is dated later too.
        $this->ledger($member, '2026-09-15', 900, Carbon::parse('2026-09-15 10:00:00'));

        $this->assertSame('unchanged_zero', $this->planByRow()[$row]->verdict);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
        $this->assertSame(0, $this->corrections());
    }

    public function test_an_attachment_whose_value_cannot_be_determined_is_left_alone_and_listed(): void
    {
        $member = $this->member();
        $attachedAt = Carbon::parse('2026-09-10 10:00:00');
        $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        // Recorded after the attach but dated before it: the balance the
        // operator saw was ₱1,000, the ledger's reading for that day is ₱1,400.
        $this->ledger($member, '2026-09-08', 400, Carbon::parse('2026-09-12 16:00:00'));
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, $attachedAt);

        $planned = $this->planByRow()[$row];
        $this->assertSame('undetermined', $planned->verdict);
        $this->assertNull($planned->new_value);
        $this->assertStringContainsString('dated on the other side of the attach', $planned->undetermined_reason);

        Log::spy();

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
        $this->assertSame(0, $this->corrections());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => collect($context['rows'])->contains('loan_collateral_id', $row)
        )->once();
    }

    public function test_an_entry_dated_the_same_day_but_recorded_after_the_attach_makes_it_undetermined(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        $this->ledger($member, '2026-09-10', 250, Carbon::parse('2026-09-10 15:00:00'));
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, Carbon::parse('2026-09-10 10:00:00'));

        $this->migration()->up();

        $this->assertSame('undetermined', $this->planByRow()[$row]->verdict);
        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    public function test_an_attachment_with_no_attach_time_is_undetermined(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, null);

        $this->assertSame('attached_at is unknown', $this->planByRow()[$row]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    public function test_a_ledger_entry_edited_after_the_attach_makes_it_undetermined(): void
    {
        $member = $this->member();
        $entry = $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        DB::table('share_capital_ledger')->where('id', $entry)->update([
            'credit' => 1200,
            'updated_at' => Carbon::parse('2026-09-20 09:00:00'),
        ]);
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, Carbon::parse('2026-09-10 10:00:00'));

        $this->assertSame('a ledger entry of this member was edited after the attach', $this->planByRow()[$row]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    public function test_a_negative_balance_is_undetermined_because_a_snapshot_cannot_hold_it(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 0, Carbon::parse('2026-09-01 09:00:00'), debit: 300);
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, Carbon::parse('2026-09-10 10:00:00'));

        $this->assertSame('the balance was negative', $this->planByRow()[$row]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    public function test_other_collateral_stored_at_zero_is_not_touched(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        $loan = $this->loanFor($member);
        $landTitle = Collateral::factory()->create([
            'borrower_id' => $member->id,
            'collateral_type_id' => CollateralType::where('source', 'manual')->firstOrFail()->id,
        ]);
        $row = $this->attach($loan, $landTitle, 0, Carbon::parse('2026-09-10 10:00:00'));

        $this->assertArrayNotHasKey($row, $this->planByRow());

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    /**
     * A released loan whose member held ₱1,000 when a share capital collateral
     * was attached to it at ₱0 five days ago, and ₱400 more two days ago.
     *
     * @return array{0: Loan, 1: Collateral, 2: int}
     */
    private function releasedSourceWithZeroShareCapital(): array
    {
        $source = $this->createReleasedLoan();
        $member = $source->borrower;
        $collateral = $this->shareCapitalCollateral($member);

        $this->ledger($member, now()->subDays(10)->toDateString(), 1000, now()->subDays(10));
        $sourceRow = $this->attach($source, $collateral, 0, now()->subDays(5));
        $this->ledger($member, now()->subDays(2)->toDateString(), 400, now()->subDays(2));

        return [$source, $collateral, $sourceRow];
    }

    /**
     * Restructure through the real endpoint, so the copy and its audit entry
     * are what production holds. Returns the new loan and its copy's row.
     *
     * @return array{0: Loan, 1: int}
     */
    private function restructureCarrying(Loan $source, Collateral $collateral, float $principal = 70800.00): array
    {
        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
            'remarks' => 'Restructured for the snapshot test',
        ])->assertCreated();

        $newLoan = Loan::findOrFail($response->json('data.id'));
        $copyRow = (int) DB::table('loan_collaterals')
            ->where('loan_id', $newLoan->id)
            ->where('collateral_id', $collateral->id)
            ->value('id');

        $this->assertNotSame(0, $copyRow, 'The restructure carried the collateral over.');
        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($copyRow), 0.001, 'The copy took the ₱0.');

        return [$newLoan, $copyRow];
    }

    private function release(Loan $loan): void
    {
        $this->patchJson("/api/loans/{$loan->id}/submit")->assertOk();

        // Restructures need a second person's approval.
        $approver = tap(User::factory()->create(), fn (User $user) => $user->assignRole(Role::where('name', 'admin')->firstOrFail()));
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$loan->id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);

        $this->patchJson("/api/loans/{$loan->id}/release")->assertOk();
    }

    public function test_a_restructure_copy_carries_the_corrected_value_of_the_attachment_it_came_from(): void
    {
        [$source, $collateral, $sourceRow] = $this->releasedSourceWithZeroShareCapital();
        [$newLoan, $copyRow] = $this->restructureCarrying($source, $collateral);

        $this->migration()->up();

        // The source's balance when it was attached, carried forward — not the
        // ₱1,400 the member held on the day of the restructure.
        $this->assertEqualsWithDelta(1000.00, $this->snapshotOf($sourceRow), 0.001);
        $this->assertEqualsWithDelta(1000.00, $this->snapshotOf($copyRow), 0.001);
        $this->assertSame(2, $this->corrections());

        $sourceAttachedAt = (string) DB::table('loan_collaterals')->where('id', $sourceRow)->value('attached_at');
        $copyEntry = AuditLog::where('action', 'collateral_snapshot_corrected')->where('auditable_id', $newLoan->id)->sole();
        $this->assertSame($sourceAttachedAt, $copyEntry->new_values['valued_as_of'], 'Valued as of the original attach, not the copy.');
        $this->assertSame($sourceRow, $copyEntry->new_values['valued_from_loan_collateral_id']);
        $this->assertStringContainsString('carried it over from', $copyEntry->description);
    }

    public function test_a_restructure_of_a_restructure_carries_the_original_value_all_the_way(): void
    {
        [$source, $collateral, $sourceRow] = $this->releasedSourceWithZeroShareCapital();
        [$first, $firstCopy] = $this->restructureCarrying($source, $collateral);
        $this->release($first);
        [, $secondCopy] = $this->restructureCarrying($first, $collateral, principal: 50000.00);

        $this->migration()->up();

        $this->assertEqualsWithDelta(1000.00, $this->snapshotOf($sourceRow), 0.001);
        $this->assertEqualsWithDelta(1000.00, $this->snapshotOf($firstCopy), 0.001);
        $this->assertEqualsWithDelta(1000.00, $this->snapshotOf($secondCopy), 0.001);
        $this->assertSame(3, $this->corrections());
    }

    public function test_a_copy_whose_source_attachment_holds_a_value_is_undetermined(): void
    {
        [$source, $collateral, $sourceRow] = $this->releasedSourceWithZeroShareCapital();
        [, $copyRow] = $this->restructureCarrying($source, $collateral);
        // Someone set the source by hand afterwards; the copy no longer says
        // what it was copied from.
        DB::table('loan_collaterals')->where('id', $sourceRow)->update(['snapshot_value' => 900]);

        $this->assertSame('no longer matches the attachment it was copied from', $this->planByRow()[$copyRow]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($copyRow), 0.001);
        $this->assertEqualsWithDelta(900.00, $this->snapshotOf($sourceRow), 0.001);
    }

    public function test_a_copy_whose_source_attachment_was_detached_is_undetermined(): void
    {
        [$source, $collateral, $sourceRow] = $this->releasedSourceWithZeroShareCapital();
        [, $copyRow] = $this->restructureCarrying($source, $collateral);
        DB::table('loan_collaterals')->where('id', $sourceRow)->delete();

        $this->assertSame('copied from an attachment that no longer exists', $this->planByRow()[$copyRow]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($copyRow), 0.001);
    }

    public function test_a_copy_is_never_valued_from_a_source_attached_again_after_it(): void
    {
        [$source, $collateral, $sourceRow] = $this->releasedSourceWithZeroShareCapital();
        [, $copyRow] = $this->restructureCarrying($source, $collateral);

        // Detached and attached again later: a new row, not the one copied.
        DB::table('loan_collaterals')->where('id', $sourceRow)->delete();
        $this->travel(1)->minutes();
        $reattached = $this->attach($source, $collateral, 0, now());

        $plan = $this->planByRow();
        $this->assertSame('undetermined', $plan[$copyRow]->verdict);
        $this->assertSame('change', $plan[$reattached]->verdict);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($copyRow), 0.001);
        // The re-attach is valued as of its own attach, when the member held ₱1,400.
        $this->assertEqualsWithDelta(1400.00, $this->snapshotOf($reattached), 0.001);
    }

    public function test_a_ledger_entry_with_no_timestamp_makes_it_undetermined(): void
    {
        $member = $this->member();
        $this->ledger($member, '2026-09-01', 1000, Carbon::parse('2026-09-01 09:00:00'));
        $untimed = $this->ledger($member, '2026-09-02', 200, Carbon::parse('2026-09-02 09:00:00'));
        DB::table('share_capital_ledger')->where('id', $untimed)->update(['created_at' => null, 'updated_at' => null]);
        $loan = $this->loanFor($member);
        $row = $this->attach($loan, $this->shareCapitalCollateral($member), 0, Carbon::parse('2026-09-10 10:00:00'));

        $this->assertSame('a ledger entry of this member has no timestamp', $this->planByRow()[$row]->undetermined_reason);

        $this->migration()->up();

        $this->assertEqualsWithDelta(0.00, $this->snapshotOf($row), 0.001);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        [, $row] = $this->affectedAttachment();

        $this->migration()->up();
        $afterFirst = $this->snapshotOf($row);

        $this->migration()->up();

        $this->assertEqualsWithDelta($afterFirst, $this->snapshotOf($row), 0.001);
        $this->assertSame(1, $this->corrections());
        $this->assertSame([], array_filter(
            $this->planByRow(),
            fn (object $planned): bool => $planned->verdict === 'change',
        ));
    }

    public function test_the_dry_run_statement_returns_exactly_the_plan(): void
    {
        $this->affectedAttachment();

        $migration = $this->migration();

        $this->assertEquals($migration->plan(), DB::select($migration->planSql()));
        $this->assertCount(1, $migration->plan());
    }
}
