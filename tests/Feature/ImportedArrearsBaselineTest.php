<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A migrated loan book must not be billed twice.
 *
 * The CSV importer writes a cooperative's existing loans straight into `loans`
 * and `amortization_schedules`. Those loans arrive part-way through their life
 * with due dates already months old, so they land immediately overdue by every
 * measure this system has — and whatever penalties the coop already charged on
 * those periods are baked into the balances they handed over. Left alone, the
 * night after an import would charge a few hundred real members months of
 * penalties they have already paid, and some of them would default.
 *
 * `loans.imported_arrears_baseline` is where the coop's bookkeeping stops and
 * ours starts. A schedule due strictly before it is a pre-import arrear: not
 * penalised, not stamped `overdue`, not counted toward defaulting.
 *
 * The governing rule, and the line every test below holds:
 * DO NOT PENALISE IS NOT DO NOT SHOW. The coop still has to chase that money,
 * so it stays on the Past Due tab and in the aging report — exactly the
 * boundary GracePeriodTest draws for grace periods.
 */
class ImportedArrearsBaselineTest extends TestCase
{
    use SetupLendyPH;

    private int $externalNoSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── loans:apply-penalties ────────────────────────────────────────────

    public function test_the_penalties_command_writes_nothing_on_a_six_month_pre_import_backlog(): void
    {
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: 0);

        Artisan::call('loans:apply-penalties');

        $this->assertStringContainsString('0 loan(s)', Artisan::output(), 'the imported loan was loaded as a candidate');

        foreach ($schedules as $period => $schedule) {
            $schedule->refresh();
            $this->assertEqualsWithDelta(0, (float) $schedule->penalty_amount, 0.01, "period {$period} was penalised");
            $this->assertSame('pending', $schedule->status, "period {$period} was stamped overdue");
        }

        $this->assertTrue($loan->fresh()->isImported());
    }

    public function test_the_penalties_command_still_penalises_the_same_backlog_on_a_loan_that_was_not_imported(): void
    {
        // The control. Without it the test above passes just as well against a
        // setup that could never have been penalised in the first place.
        [, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: null);

        Artisan::call('loans:apply-penalties');

        $this->assertStringContainsString('1 loan(s)', Artisan::output());

        foreach ($schedules as $period => $schedule) {
            $schedule->refresh();
            $this->assertGreaterThan(0, (float) $schedule->penalty_amount, "period {$period} escaped its penalty");
            $this->assertSame('overdue', $schedule->status);
        }
    }

    public function test_a_post_baseline_schedule_on_the_same_imported_loan_is_still_penalised(): void
    {
        // The exclusion is not a blanket amnesty. One loan, one baseline, and
        // the two instalments either side of it must be treated differently.
        [, $schedules] = $this->importedLoan([200, 100], baselineDaysAgo: 120);

        Artisan::call('loans:apply-penalties');

        $this->assertStringContainsString('1 loan(s)', Artisan::output());

        $this->assertEqualsWithDelta(0, (float) $schedules[1]->refresh()->penalty_amount, 0.01);
        $this->assertSame('pending', $schedules[1]->status, 'a pre-import instalment was stamped overdue');

        $this->assertGreaterThan(0, (float) $schedules[2]->refresh()->penalty_amount, 'a post-baseline arrear escaped its penalty');
        $this->assertSame('overdue', $schedules[2]->status);
    }

    // ── loans:check-defaulted ────────────────────────────────────────────

    public function test_the_defaulted_command_does_not_default_a_loan_whose_only_arrears_are_pre_import(): void
    {
        [$loan] = $this->importedLoan([200, 150], baselineDaysAgo: 0);

        Artisan::call('loans:check-defaulted');

        $this->assertStringContainsString('0 loan(s)', Artisan::output());
        $this->assertSame('released', $loan->fresh()->status);
    }

    public function test_the_defaulted_command_still_defaults_the_same_loan_when_it_was_not_imported(): void
    {
        [$loan] = $this->importedLoan([200, 150], baselineDaysAgo: null);

        Artisan::call('loans:check-defaulted');

        $this->assertStringContainsString('1 loan(s)', Artisan::output());
        $this->assertSame('defaulted', $loan->fresh()->status);
    }

    public function test_a_post_baseline_schedule_does_count_toward_default(): void
    {
        // Both halves of the command have to see this instalment: the candidate
        // whereHas to pick the loan up at all, and the $hasRecentDue complement
        // not to be fooled by the pre-import row sitting beside it.
        [$loan] = $this->importedLoan([200, 100], baselineDaysAgo: 120);

        Artisan::call('loans:check-defaulted');

        $this->assertSame('defaulted', $loan->fresh()->status, 'a genuinely 100-day-late arrear was excused as pre-import');
    }

    public function test_a_post_baseline_schedule_inside_the_threshold_still_holds_the_loan_open(): void
    {
        // The complement doing its job: the loan has a 200-day pre-import
        // arrear (excluded) and a post-baseline instalment 30 days late (inside
        // the 90-day threshold), so it must NOT default. If the exclusion were
        // applied to the candidate query only, the pre-import row would still
        // be the thing that qualified it.
        [$loan] = $this->importedLoan([200, 30], baselineDaysAgo: 120);

        Artisan::call('loans:check-defaulted');

        $this->assertSame('released', $loan->fresh()->status);
    }

    public function test_a_freshly_imported_book_cannot_default_on_the_night_it_lands(): void
    {
        // The corner where the two halves' cutoffs cross, and the reason the
        // baseline filter on the $hasRecentDue complement is redundant today.
        // A baseline 30 days old is NEWER than the 90-day default cutoff, so no
        // schedule on this loan can be both post-baseline and past the
        // threshold — the arithmetic alone guarantees a just-imported loan a
        // full threshold's runway before it can default here, however old the
        // arrears it inherited.
        [$loan] = $this->importedLoan([400, 200, 10], baselineDaysAgo: 30);

        $this->assertTrue(
            $loan->imported_arrears_baseline->gt(Carbon::today()->subDays(90)),
            'precondition: the baseline must be newer than the default cutoff',
        );

        Artisan::call('loans:check-defaulted');

        $this->assertStringContainsString('0 loan(s)', Artisan::output());
        $this->assertSame('released', $loan->fresh()->status);
    }

    // ── the path the nightly command never touches ───────────────────────

    public function test_posting_a_repayment_does_not_penalise_the_imported_backlog(): void
    {
        // RepaymentService::applyPenalties() is the only code anywhere that
        // WRITES a penalty, and processRepayment() calls it as step 1 inside
        // the transaction on EVERY payment. loans:apply-penalties never loads
        // this loan, so this is the path that would have charged a migrated
        // member their whole backlog the first time they paid a peso.
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: 0);

        $response = $this->postRepayment($loan, 500);

        $this->assertEqualsWithDelta(0, (float) $response['penalty_applied'], 0.01, 'a repayment charged the pre-import backlog');

        foreach ($schedules as $period => $schedule) {
            $this->assertEqualsWithDelta(
                0, (float) $schedule->refresh()->penalty_amount, 0.01,
                "period {$period} was penalised by a repayment",
            );
            $this->assertNotSame('overdue', $schedule->status, "period {$period} was stamped overdue by a repayment");
        }
    }

    public function test_posting_a_repayment_still_penalises_the_same_backlog_on_a_loan_that_was_not_imported(): void
    {
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: null);

        $response = $this->postRepayment($loan, 500);

        $this->assertGreaterThan(0, (float) $response['penalty_applied'], 'the control never accrued a penalty to begin with');
        $this->assertGreaterThan(0, (float) $schedules[1]->refresh()->penalty_amount);
        $this->assertGreaterThan(0, (float) $schedules[2]->refresh()->penalty_amount);
    }

    public function test_posting_a_repayment_still_penalises_a_post_baseline_arrear(): void
    {
        [$loan, $schedules] = $this->importedLoan([200, 100], baselineDaysAgo: 120);

        $this->postRepayment($loan, 100);

        $this->assertEqualsWithDelta(0, (float) $schedules[1]->refresh()->penalty_amount, 0.01);
        $this->assertGreaterThan(0, (float) $schedules[2]->refresh()->penalty_amount);
    }

    public function test_voiding_that_repayment_does_not_re_stamp_overdue(): void
    {
        // reverseAllocation() re-derives the status from scratch and is the
        // OTHER place this service stamps `overdue`. Left on the date alone, a
        // void would put back exactly the label applyPenalties() declined to
        // write — and the loans screen would show a migrated member as newly
        // delinquent because someone voided a mistyped receipt.
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: 0);

        $repayment = $this->postRepayment($loan, 500);
        $this->assertSame('partial', $schedules[1]->refresh()->status, 'the repayment did not land where the void test needs it');

        $this->voidRepayment((int) $repayment['id']);

        $schedules[1]->refresh();
        $this->assertSame('pending', $schedules[1]->status, 'voiding a repayment re-stamped a pre-import arrear overdue');
        $this->assertEqualsWithDelta(0, (float) $schedules[1]->penalty_amount, 0.01);
    }

    public function test_voiding_a_repayment_still_re_stamps_a_loan_that_was_not_imported(): void
    {
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: null);

        $repayment = $this->postRepayment($loan, 500);
        $this->voidRepayment((int) $repayment['id']);

        $this->assertSame('overdue', $schedules[1]->refresh()->status);
    }

    // ── do not penalise is not do not show ───────────────────────────────

    public function test_imported_arrears_still_appear_on_the_past_due_tab(): void
    {
        // The coop has to chase this money. Loan::scopePastDue() reads
        // `due_date` directly rather than the `overdue` stamp, which is exactly
        // why never stamping these rows costs nothing here.
        [$loan, $schedules] = $this->importedLoan([180, 150], baselineDaysAgo: 0);

        Artisan::call('loans:apply-penalties');

        $this->assertSame('pending', $schedules[1]->refresh()->status, 'precondition: the row is deliberately unstamped');

        $this->assertTrue(Loan::query()->pastDue()->whereKey($loan->id)->exists());

        $list = $this->getJson('/api/loans?status=past_due')->assertOk();
        $this->assertContains($loan->id, array_column($list->json('data'), 'id'));
        $this->assertSame(1, $list->json('meta.stats.past_due'));

        // And the money is still shown as arrears on the row itself —
        // LoanResource's `overdue_amount` is deliberately NOT baseline-aware.
        $this->assertGreaterThan(0, $list->json('data.0.overdue_amount'));
    }

    public function test_imported_arrears_still_appear_in_the_aging_report(): void
    {
        [$loan] = $this->importedLoan([180, 150], baselineDaysAgo: 0);

        Artisan::call('loans:apply-penalties');

        $aging = $this->getJson('/api/reports/aging')->assertOk()->json('data');

        $this->assertSame(1, $aging['total']['count'], 'an imported loan fell out of the aging report');
        $this->assertGreaterThan(0, $aging['total']['amount']);
        $this->assertSame(1, $aging['buckets']['over_90']['count']);

        // Also on the Due/Past Due report, which asks what is OWED. It lists
        // SCHEDULE rows, so both pre-import instalments appear.
        $report = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame(2, $report->json('totals.count'));
        $this->assertSame($loan->id, $report->json('data.0.loan_id'));
        $this->assertGreaterThan(0, $report->json('totals.total_due'));
    }

    // ── the loan account number sequence ─────────────────────────────────

    public function test_release_still_issues_the_next_ln_after_imported_loans_are_present(): void
    {
        // The trap this schema avoids. LoanService::release() takes the next
        // number with `(int) substr(MAX(loan_account_number), 3) + 1`, which
        // assumes the LN-000123 shape. An external reference parked in that
        // column reads as a tiny number:
        $this->assertSame(3, (int) substr('2023-0041', 3), 'the arithmetic release() performs on loan_account_number');
        $this->assertSame(41, (int) substr('LN-000041', 3), '…which is only correct for the LN- shape');

        // …so the next release would reissue LN-000004 and the unique index
        // would reject it, permanently breaking loan release on that
        // deployment. Imported loans therefore keep the column NULL and carry
        // their reference in `external_loan_no`, and release()'s
        // whereNotNull() already steps over them.
        $first = $this->createReleasedLoan();
        $this->assertSame('LN-000001', $first->fresh()->loan_account_number);

        [$imported] = $this->importedLoan([180, 150], baselineDaysAgo: 0, externalNo: '2023-0041');
        [$importedTwo] = $this->importedLoan([90], baselineDaysAgo: 0, externalNo: '9999-0001');

        $this->assertNull($imported->loan_account_number);
        $this->assertNull($importedTwo->loan_account_number);
        $this->assertSame('2023-0041', $imported->external_loan_no);

        $second = $this->createReleasedLoan();

        $this->assertSame('LN-000002', $second->fresh()->loan_account_number, 'the imported book broke the LN sequence');
    }

    public function test_two_loans_cannot_share_an_external_reference(): void
    {
        // The importer needs the database, not just its own bookkeeping, to
        // refuse the same loan arriving twice.
        $this->importedLoan([90], baselineDaysAgo: 0, externalNo: 'DUP-0001');

        $this->expectException(QueryException::class);
        $this->importedLoan([90], baselineDaysAgo: 0, externalNo: 'DUP-0001');
    }

    // ── the shared definition itself ─────────────────────────────────────

    public function test_the_php_twin_treats_the_baseline_as_strictly_before(): void
    {
        $baseline = Carbon::parse('2026-06-01');

        $this->assertFalse(AmortizationSchedule::isPenalisable($baseline, Carbon::parse('2026-05-31')), 'the day before the baseline is pre-import');
        $this->assertTrue(AmortizationSchedule::isPenalisable($baseline, Carbon::parse('2026-06-01')), 'the baseline day itself is ours');
        $this->assertTrue(AmortizationSchedule::isPenalisable($baseline, Carbon::parse('2026-06-02')));

        // A null baseline is every loan that was not imported, and must be true
        // by construction rather than by a caller remembering to branch.
        $this->assertTrue(AmortizationSchedule::isPenalisable(null, Carbon::parse('1999-01-01')));

        // Neither argument may be mutated — the same assertion GracePeriodTest
        // makes about pastGraceCutoff().
        $dueDate = Carbon::parse('2026-05-31 13:45:00');
        AmortizationSchedule::isPenalisable($baseline, $dueDate);
        $this->assertSame('2026-06-01 00:00:00', $baseline->toDateTimeString());
        $this->assertSame('2026-05-31 13:45:00', $dueDate->toDateTimeString());
    }

    public function test_the_sql_and_php_forms_agree_on_the_boundary(): void
    {
        // Two forms of one rule, used by different callers. The SQL form runs
        // in the two commands' whereHas; the PHP form runs in RepaymentService,
        // which already holds the loan. They must not drift.
        foreach ([121, 120, 119] as $daysAgo) {
            [$loan, $schedules] = $this->importedLoan([$daysAgo], baselineDaysAgo: 120);

            $viaSql = Loan::query()
                ->whereKey($loan->id)
                ->whereHas('amortizationSchedules', fn ($q) => $q
                    ->whereKey($schedules[1]->id)
                    ->whereRaw(AmortizationSchedule::penalisableSql()))
                ->exists();

            $viaPhp = AmortizationSchedule::isPenalisable(
                $loan->imported_arrears_baseline,
                $schedules[1]->due_date,
            );

            $this->assertSame($viaPhp, $viaSql, "SQL and PHP disagree at {$daysAgo} days ago against a 120-day baseline");
        }
    }

    public function test_the_sql_form_leaves_every_non_imported_loan_alone(): void
    {
        // Written `IS NULL OR …` so the entire existing book is unaffected by
        // construction. If this ever fails, an ordinary loan has silently
        // stopped being penalisable.
        [$native, $schedules] = $this->importedLoan([180], baselineDaysAgo: null);

        $this->assertNull($native->imported_arrears_baseline);
        $this->assertFalse($native->isImported());

        $this->assertTrue(
            Loan::query()
                ->whereKey($native->id)
                ->whereHas('amortizationSchedules', fn ($q) => $q
                    ->whereKey($schedules[1]->id)
                    ->whereRaw(AmortizationSchedule::penalisableSql()))
                ->exists(),
        );
    }

    // ── the resource ─────────────────────────────────────────────────────

    public function test_the_resource_exposes_the_import_markers(): void
    {
        [$imported] = $this->importedLoan([180], baselineDaysAgo: 0, externalNo: '2023-0041');

        $this->getJson("/api/loans/{$imported->id}")
            ->assertOk()
            ->assertJsonPath('data.external_loan_no', '2023-0041')
            ->assertJsonPath('data.is_imported', true)
            ->assertJsonPath('data.loan_account_number', null);

        $native = $this->createReleasedLoan();

        $this->getJson("/api/loans/{$native->id}")
            ->assertOk()
            ->assertJsonPath('data.external_loan_no', null)
            ->assertJsonPath('data.is_imported', false);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed> the serialised repayment
     */
    private function postRepayment(Loan $loan, float $amount): array
    {
        return $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => Carbon::today()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated()->json('data');
    }

    private function voidRepayment(int $repaymentId): void
    {
        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Duplicate entry',
        ])->assertOk()->assertJsonPath('data.status', 'voided');
    }

    /**
     * A released loan written the way the CSV importer will write one: straight
     * into `loans` + `amortization_schedules`, with no `loan_account_number`,
     * carrying the coop's own reference and the date its arrears stop.
     *
     * Deliberately NOT built through LoanService::release(). An imported loan
     * never goes through that path, and borrowing it here would consume an LN
     * number the release-sequence test is about.
     *
     * `$baselineDaysAgo` of null makes the same loan a NON-imported control:
     * identical schedules, no import markers, so every "excluded" assertion has
     * a twin proving the setup could have been penalised.
     *
     * @param  array<int, int>  $dueDaysAgo  days before today each instalment fell due, period 1 first
     * @return array{0: Loan, 1: Collection<int, AmortizationSchedule>} the loan, and its schedules keyed by period_number
     */
    private function importedLoan(array $dueDaysAgo, ?int $baselineDaysAgo, ?string $externalNo = null): array
    {
        $isImported = $baselineDaysAgo !== null;
        $externalNo ??= 'EXT-'.str_pad((string) ++$this->externalNoSequence, 4, '0', STR_PAD_LEFT);

        $product = LoanProduct::factory()->create([
            'term' => count($dueDaysAgo),
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            // Grace is a separate rule with its own tests. Zero here so every
            // assertion below is about the baseline and nothing else.
            'grace_period_days' => 0,
        ]);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $loan = Loan::factory()->create([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'term' => count($dueDaysAgo),
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 0,
            'principal_amount' => 20000,
            'net_proceeds' => 20000,
            'start_date' => Carbon::today()->subDays(max($dueDaysAgo) + 30),
            'maturity_date' => Carbon::today()->subDays(min($dueDaysAgo)),
            'status' => 'released',
            'loan_account_number' => null,
            'external_loan_no' => $isImported ? $externalNo : null,
            'imported_arrears_baseline' => $isImported ? Carbon::today()->subDays($baselineDaysAgo) : null,
            'created_by' => $this->admin->id,
            'released_by' => $this->admin->id,
            'released_at' => Carbon::today()->subDays(max($dueDaysAgo) + 30),
        ]);

        $periods = count($dueDaysAgo);

        $schedules = collect($dueDaysAgo)
            ->mapWithKeys(function (int $daysAgo, int $index) use ($loan, $periods) {
                $period = $index + 1;

                return [$period => AmortizationSchedule::factory()->create([
                    'loan_id' => $loan->id,
                    'period_number' => $period,
                    'due_date' => Carbon::today()->subDays($daysAgo),
                    'principal_due' => 10000,
                    'interest_due' => 500,
                    'total_due' => 10500,
                    'remaining_balance' => 10000 * ($periods - $period),
                    'status' => 'pending',
                ])];
            });

        return [$loan->fresh(), $schedules];
    }
}
