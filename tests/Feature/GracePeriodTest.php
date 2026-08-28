<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\RepaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `grace_period_days` is a promise the borrower can read.
 *
 * It is chosen on the loan product, copied onto the loan at creation, and
 * printed on both the promissory note (PromissoryNoteService) and the
 * disclosure statement (DisclosureService) — and for a long time it was
 * honoured by nothing. Every caller compared a bare `due_date` against today,
 * so a borrower inside the window their own paperwork granted them was charged
 * a penalty, stamped `overdue`, and shown to collections as late.
 *
 * These hold the fix in place across all four places lateness is decided, and
 * — just as importantly — hold the line at the two places it deliberately is
 * NOT applied. The governing rule: grace changes when a borrower is PENALISED
 * and when they are called LATE. It never changes whether the money is OWED.
 */
class GracePeriodTest extends TestCase
{
    use SetupLendyPH;

    private const GRACE_DAYS = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── penalties and the `overdue` stamp ────────────────────────────────

    public function test_a_schedule_inside_its_grace_window_is_neither_penalised_nor_stamped_overdue(): void
    {
        // Six days late on a seven-day grace period: the borrower is late by
        // the calendar and on time by the contract.
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        app(RepaymentService::class)->applyPenalties($loan, Carbon::today());

        $schedule->refresh();
        $this->assertEqualsWithDelta(0, (float) $schedule->penalty_amount, 0.01, 'penalised inside the promised grace window');
        $this->assertSame('pending', $schedule->status, 'stamped overdue inside the promised grace window');
    }

    public function test_the_day_grace_expires_the_schedule_is_penalised_and_stamped_overdue(): void
    {
        // One day past the window. The boundary is the whole point: day 7 is
        // still covered, day 8 is not.
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);

        app(RepaymentService::class)->applyPenalties($loan, Carbon::today());

        $schedule->refresh();
        $this->assertGreaterThan(0, (float) $schedule->penalty_amount);
        $this->assertSame('overdue', $schedule->status);
    }

    public function test_the_last_day_of_grace_is_still_covered(): void
    {
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS);

        app(RepaymentService::class)->applyPenalties($loan, Carbon::today());

        $this->assertEqualsWithDelta(0, (float) $schedule->refresh()->penalty_amount, 0.01);
    }

    public function test_no_grace_period_behaves_exactly_as_it_did_before_grace_was_honoured(): void
    {
        // Every loan that never had a grace period must be penalised on exactly
        // the same day it always was, or honouring grace becomes a silent
        // change to the whole existing book.
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(1, graceDays: 0);

        app(RepaymentService::class)->applyPenalties($loan, Carbon::today());

        $schedule->refresh();
        $this->assertGreaterThan(0, (float) $schedule->penalty_amount, 'grace 0 must penalise one day late');
        $this->assertSame('overdue', $schedule->status, 'grace 0 must stamp overdue one day late');
    }

    public function test_grace_period_days_cannot_be_null_in_the_database(): void
    {
        // Worth pinning, because the null handling in pastGraceSql()'s COALESCE
        // and in pastGraceCutoff() reads like it is load-bearing and is not:
        // both columns are NOT NULL DEFAULT 0, and the `nullable` on the loan
        // product request rules means "may be omitted", after which the column
        // default supplies 0. The null branch is defence for a future schema
        // change, not a path today. If this test ever fails, the null branch
        // has become real and every caller needs re-checking.
        foreach (['loans', 'loan_products'] as $table) {
            $column = collect(DB::select("SHOW COLUMNS FROM `{$table}` LIKE 'grace_period_days'"))->first();

            $this->assertNotNull($column, "{$table}.grace_period_days is missing");
            $this->assertSame('NO', $column->Null, "{$table}.grace_period_days has become nullable");
            $this->assertSame('0', (string) $column->Default, "{$table}.grace_period_days no longer defaults to 0");
        }

        // And the helper treats a null as no grace regardless, so the defence
        // is correct if that day comes.
        $asOf = Carbon::parse('2026-08-28');
        $this->assertTrue(
            AmortizationSchedule::pastGraceCutoff(null, $asOf)->eq(AmortizationSchedule::pastGraceCutoff(0, $asOf))
        );
    }

    public function test_the_penalties_command_does_not_load_loans_it_will_then_skip(): void
    {
        // The command pre-filters candidate loans and the service decides what
        // to write. If the two disagree on what late means, the command reports
        // a count that does not describe what it did.
        [, $inGrace] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);
        [, $pastGrace] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);

        Artisan::call('loans:apply-penalties');

        $this->assertStringContainsString('1 loan(s)', Artisan::output());
        $this->assertEqualsWithDelta(0, (float) $inGrace->refresh()->penalty_amount, 0.01);
        $this->assertGreaterThan(0, (float) $pastGrace->refresh()->penalty_amount);
    }

    public function test_voiding_a_repayment_does_not_stamp_an_in_grace_schedule_overdue(): void
    {
        // reverseAllocation() re-derives the schedule status from scratch and
        // is the OTHER place this service stamps `overdue` — a hundred lines
        // below applyPenalties(). Left on a bare comparison it would put back
        // the exact label the penalty fix removes, on any void.
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        $repaymentId = $this->postRepayment($loan, 500);
        $this->assertSame('partial', $schedule->refresh()->status);

        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Duplicate entry',
        ])->assertOk()->assertJsonPath('data.status', 'voided');

        $schedule->refresh();
        $this->assertSame('pending', $schedule->status, 'a void re-stamped a schedule still inside its grace window');
        $this->assertEqualsWithDelta(0, (float) $schedule->penalty_amount, 0.01);
    }

    public function test_voiding_a_repayment_still_stamps_a_genuinely_late_schedule_overdue(): void
    {
        [$loan, $schedule] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);

        $repaymentId = $this->postRepayment($loan, 500);

        $this->patchJson("/api/repayments/{$repaymentId}/void", [
            'void_reason' => 'Duplicate entry',
        ])->assertOk();

        $this->assertSame('overdue', $schedule->refresh()->status);
    }

    // ── the loans list vs the report ─────────────────────────────────────

    public function test_a_loan_inside_grace_is_owed_on_the_report_but_not_late_on_the_list(): void
    {
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        // The Past Due tab asks what is LATE, so it leaves this alone.
        $list = $this->getJson('/api/loans?status=past_due')->assertOk();
        $this->assertSame([], array_column($list->json('data'), 'id'));
        $this->assertSame(0, $list->json('meta.stats.past_due'));

        // The Due/Past Due report asks what is OWED, so it must still show it.
        // This is the one deliberate difference left between the two, and it is
        // the reason duePastDueQuery() does NOT call pastGraceSql(): every
        // product carries some grace, so applying it there would empty the
        // "Due" half of a report named "Due / Past Due".
        $report = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame(1, $report->json('totals.count'));
        $this->assertSame($loan->id, $report->json('data.0.loan_id'));
    }

    public function test_the_day_grace_expires_the_loan_appears_on_both(): void
    {
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);

        $list = $this->getJson('/api/loans?status=past_due')->assertOk();
        $this->assertSame([$loan->id], array_column($list->json('data'), 'id'));
        $this->assertSame(1, $list->json('meta.stats.past_due'));

        $report = $this->getJson('/api/reports/due-past-due')->assertOk();
        $this->assertSame(1, $report->json('totals.count'));
    }

    public function test_the_report_lists_an_in_grace_row_without_counting_it_as_late(): void
    {
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        $report = $this->getJson('/api/reports/due-past-due')->assertOk();

        // Listed: the money is owed, and grace does not change that.
        $this->assertSame(1, $report->json('totals.count'));
        $this->assertSame($loan->id, $report->json('data.0.loan_id'));

        // But not labelled late. `overdue_count` is the only figure in the
        // totals block that asserts lateness rather than debt.
        $this->assertSame(0, $report->json('totals.overdue_count'));

        // The amounts owed are untouched — grace moves no money.
        $this->assertGreaterThan(0, $report->json('totals.total_due'));

        // And no penalty has accrued, so the totals' penalty column agrees
        // with `overdue_count` without being filtered separately.
        $this->assertEqualsWithDelta(0, $report->json('totals.total_penalty'), 0.01);

        // CONTRACT with the row payload, for when ReportController's
        // `days_overdue` becomes grace-aware: `overdue_count` must equal the
        // number of rows reading `days_overdue` > 0. That assertion cannot be
        // written yet — the field still counts from the bare due date, so this
        // row reports 6 while the total correctly reports 0. That gap is the
        // whole reason this figure moved.
    }

    public function test_the_day_grace_expires_the_report_counts_the_row_as_late(): void
    {
        $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);

        $report = $this->getJson('/api/reports/due-past-due')->assertOk();

        $this->assertSame(1, $report->json('totals.count'));
        $this->assertSame(1, $report->json('totals.overdue_count'));
    }

    public function test_overdue_count_never_exceeds_the_rows_the_report_lists(): void
    {
        // Membership ignores grace and lateness honours it, so the late count
        // is always a subset of the listed rows — never the other way round.
        $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);
        $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 1);
        $this->loanWithScheduleDaysAgo(0);
        $this->loanWithScheduleDaysAgo(90);

        $totals = $this->getJson('/api/reports/due-past-due')->assertOk()->json('totals');

        $this->assertSame(4, $totals['count'], 'all four are owed');
        $this->assertSame(2, $totals['overdue_count'], 'only the two past grace are late');
        $this->assertLessThanOrEqual($totals['count'], $totals['overdue_count']);
    }

    public function test_the_row_figure_and_the_past_due_tab_flip_together(): void
    {
        // THE invariant, and the reason this is one test rather than two.
        //
        // `overdue_amount` is the money a loan row shows as arrears; the Past
        // Due tab is the filter that decides whether that loan is late at all.
        // They are read side by side on one screen, so either drifting alone
        // reproduces the contradiction this whole batch exists to remove — a
        // row reading "₱X overdue" that no arrears filter returns, and no way
        // for the user to tell which of the two is lying.
        //
        // Inside grace: both silent.
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        $row = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $tab = $this->getJson('/api/loans?status=past_due')->assertOk();

        $this->assertEqualsWithDelta(0, $row['overdue_amount'], 0.01, 'row shows arrears for a loan inside its grace window');
        $this->assertSame([], array_column($tab->json('data'), 'id'));
        $this->assertSame(0, $tab->json('meta.stats.past_due'));

        // The day grace expires: both speak, together.
        $first = $loan->amortizationSchedules()->where('period_number', 1)->firstOrFail();
        $first->update(['due_date' => today()->subDays(self::GRACE_DAYS + 1)->toDateString()]);

        $row = $this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data');
        $tab = $this->getJson('/api/loans?status=past_due')->assertOk();

        $this->assertGreaterThan(0, $row['overdue_amount'], 'row shows no arrears for a loan past its grace window');
        $this->assertSame([$loan->id], array_column($tab->json('data'), 'id'));
        $this->assertSame(1, $tab->json('meta.stats.past_due'));
    }

    public function test_the_loans_list_and_the_loan_detail_report_the_same_arrears(): void
    {
        // LoanResource and RepaymentService::getLoanSummary() compute
        // `overdue_amount` for two different screens. They go through the same
        // AmortizationSchedule::lateUnpaid(), so one loan cannot owe two
        // different amounts depending on which page is open.
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS + 3);

        $fromList = $this->getJson('/api/loans?status=past_due')->assertOk()->json('data.0.overdue_amount');
        $fromDetail = app(RepaymentService::class)->getLoanSummary($loan->fresh())['overdue_amount'];

        $this->assertGreaterThan(0, $fromList);
        $this->assertEqualsWithDelta($fromList, $fromDetail, 0.01);
    }

    public function test_the_loan_summary_counts_only_schedules_past_grace(): void
    {
        [$loan] = $this->loanWithScheduleDaysAgo(self::GRACE_DAYS - 1);

        $summary = app(RepaymentService::class)->getLoanSummary($loan->fresh());

        $this->assertSame(0, $summary['overdue_schedules_count']);
        $this->assertEqualsWithDelta(0, $summary['overdue_amount'], 0.01);

        // Still owed, though — grace moves no money.
        $this->assertGreaterThan(0, $summary['outstanding_balance']);
    }

    public function test_a_schedule_due_today_stays_on_the_report_and_off_the_list(): void
    {
        // Neither grace nor the strict `<` cutoff may drop a row the report
        // exists to show. Due today is due, and it is not late.
        $this->loanWithScheduleDaysAgo(0);

        $this->assertSame(0, $this->getJson('/api/loans?status=past_due')->assertOk()->json('meta.stats.past_due'));
        $this->assertSame(1, $this->getJson('/api/reports/due-past-due')->assertOk()->json('totals.count'));
    }

    // ── the shared definition itself ─────────────────────────────────────

    public function test_the_sql_and_php_cutoffs_agree_on_the_boundary(): void
    {
        // Two forms of one rule: the query form shifts the cutoff in SQL per
        // row, the PHP form shifts it once for a loan already in hand. They are
        // used by different callers and must not drift.
        $asOf = Carbon::parse('2026-08-28');

        $this->assertSame('2026-08-21', AmortizationSchedule::pastGraceCutoff(7, $asOf)->toDateString());
        $this->assertSame('2026-08-28', AmortizationSchedule::pastGraceCutoff(0, $asOf)->toDateString());
        $this->assertSame('2026-08-28', AmortizationSchedule::pastGraceCutoff(null, $asOf)->toDateString());
        $this->assertSame('2026-08-28', $asOf->toDateString(), 'pastGraceCutoff must not mutate the date it is given');

        foreach ([self::GRACE_DAYS - 1, self::GRACE_DAYS, self::GRACE_DAYS + 1] as $daysAgo) {
            [$loan, $schedule] = $this->loanWithScheduleDaysAgo($daysAgo);

            $viaSql = Loan::query()->pastDue()->whereKey($loan->id)->exists();
            $viaPhp = $schedule->due_date->lt(
                AmortizationSchedule::pastGraceCutoff($loan->grace_period_days, Carbon::today())
            );

            $this->assertSame($viaPhp, $viaSql, "SQL and PHP disagree at {$daysAgo} days late");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function postRepayment(Loan $loan, float $amount): int
    {
        return (int) $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => Carbon::today()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
    }

    /**
     * A released loan whose first instalment fell due $daysAgo days ago, with
     * every later instalment pushed out of the way so it is the only one in
     * play.
     *
     * @return array{0: Loan, 1: AmortizationSchedule}
     */
    private function loanWithScheduleDaysAgo(int $daysAgo, int $graceDays = self::GRACE_DAYS): array
    {
        $loan = $this->createReleasedLoan(['product' => [
            'grace_period_days' => $graceDays,
            'penalty_rate' => 2.0,
        ]]);

        // LoanService::createLoan() copies the product's grace onto the loan.
        // If that ever stops, every assertion below would still pass against
        // the wrong number.
        $this->assertSame($graceDays, (int) $loan->grace_period_days);

        $first = $loan->amortizationSchedules()->where('period_number', 1)->firstOrFail();
        $first->update(['due_date' => Carbon::today()->subDays($daysAgo)->toDateString()]);

        $loan->amortizationSchedules()
            ->where('period_number', '>', 1)
            ->each(fn (AmortizationSchedule $s) => $s->update([
                'due_date' => Carbon::today()->addYear()->addMonths($s->period_number)->toDateString(),
            ]));

        return [$loan->fresh(), $first->fresh()];
    }
}
