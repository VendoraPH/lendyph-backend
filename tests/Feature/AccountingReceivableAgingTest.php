<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Receivable aging — the one accounting report that reads the LENDING tables.
 *
 * Two things here are easy to get wrong and impossible to spot on screen:
 *
 * 1. THE UNIT. `amortization_schedules` stores pesos as `decimal:2`; the
 *    accounting module speaks integer centavos. A missed conversion renders as
 *    ₱12.35 where ₱1,234.56 was owed, and a doubled one as ₱123,456.00. Both
 *    are perfectly plausible figures for a co-op. The assertions below use an
 *    amount whose two readings cannot be confused.
 * 2. THE BOUNDARIES. Buckets must be disjoint and must cover every day, or the
 *    rows will not sum to the total — which is exactly the bug the lending
 *    aging report already shipped and had to fix.
 */
class AccountingReceivableAgingTest extends TestCase
{
    use SetupLendyPH;

    /** The reporting date every test below works from. */
    private const AS_OF = '2026-09-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function loan(string $status = 'released', ?int $branchId = null): Loan
    {
        return Loan::factory()->create([
            'branch_id' => $branchId ?? $this->branch->id,
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Next free period number per loan.
     *
     * `amortization_schedules` is uniquely indexed on
     * (loan_id, period_number), and several instalments on ONE loan is the
     * shape an aging report exists to describe — so the period cannot just be
     * the factory's default of 1.
     *
     * @var array<int, int>
     */
    private array $periods = [];

    private function schedule(
        Loan $loan,
        string $dueDate,
        float $principal = 0,
        float $interest = 0,
        float $penalty = 0,
        string $status = 'pending',
        float $principalPaid = 0,
        float $penaltyPaid = 0,
    ): AmortizationSchedule {
        $period = ($this->periods[$loan->id] ?? 0) + 1;
        $this->periods[$loan->id] = $period;

        return AmortizationSchedule::factory()->create([
            'loan_id' => $loan->id,
            'period_number' => $period,
            'due_date' => $dueDate,
            'principal_due' => $principal,
            'principal_paid' => $principalPaid,
            'interest_due' => $interest,
            'interest_paid' => 0,
            'penalty_amount' => $penalty,
            'penalty_paid' => $penaltyPaid,
            'total_due' => $principal + $interest,
            'remaining_balance' => 0,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function aging(string $asOf = self::AS_OF, string $query = ''): array
    {
        return $this->getJson("/api/accounting/loans/aging?as_of={$asOf}{$query}")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, array{amount: int, count: int}> */
    private function buckets(array $aging): array
    {
        return collect($aging['rows'])->keyBy('bucket')->map(fn (array $r): array => [
            'amount' => $r['amount'],
            'count' => $r['count'],
        ])->all();
    }

    public function test_pesos_are_converted_to_centavos_exactly(): void
    {
        // ₱1,234.56 — principal + interest + penalty, so the penalty leg is
        // proven to be part of the receivable at the same time.
        $this->schedule($this->loan(), '2026-09-15', principal: 1000.00, interest: 200.00, penalty: 34.56);

        $aging = $this->aging();

        // 123456, not 1234 (pesos leaked through), not 1235 (rounded pesos),
        // and not 12345600 (converted twice). assertSame so a string fails too.
        $this->assertSame(123456, $aging['total']);
        $this->assertSame(123456, $aging['past_due_total']);
        $this->assertSame(123456, $this->buckets($aging)['1_30']['amount']);
    }

    public function test_a_sub_centavo_total_cannot_appear(): void
    {
        // Three odd-centavo rows that a float round-trip would land on
        // x.999999. The SQL sums in exact DECIMAL and the conversion parses
        // digits, so the answer is exact.
        $loan = $this->loan();
        $this->schedule($loan, '2026-09-15', principal: 0.01);
        $this->schedule($loan, '2026-09-16', principal: 0.02);
        $this->schedule($loan, '2026-09-17', principal: 10.07);

        $this->assertSame(1010, $this->aging()['total']);
    }

    public function test_every_bucket_boundary_matches_the_frontend(): void
    {
        $loan = $this->loan();

        // Days past due, measured from AS_OF (2026-09-30). Upper bounds are
        // INCLUSIVE, so 30 is the last day of "1–30" and 31 the first of
        // "31–60". Each amount is distinct so a misplaced row is identifiable
        // rather than merely making a total wrong.
        $this->schedule($loan, '2026-09-30', principal: 1.00);   //   0 -> current
        $this->schedule($loan, '2026-10-15', principal: 2.00);   // future -> current
        $this->schedule($loan, '2026-09-29', principal: 4.00);   //   1 -> 1_30
        $this->schedule($loan, '2026-08-31', principal: 8.00);   //  30 -> 1_30
        $this->schedule($loan, '2026-08-30', principal: 16.00);  //  31 -> 31_60
        $this->schedule($loan, '2026-08-01', principal: 32.00);  //  60 -> 31_60
        $this->schedule($loan, '2026-07-31', principal: 64.00);  //  61 -> 61_90
        $this->schedule($loan, '2026-07-02', principal: 128.00); //  90 -> 61_90
        $this->schedule($loan, '2026-07-01', principal: 256.00); //  91 -> 91_120
        $this->schedule($loan, '2026-06-02', principal: 512.00); // 120 -> 91_120
        $this->schedule($loan, '2026-06-01', principal: 1024.00); // 121 -> over_120

        $aging = $this->aging();
        $buckets = $this->buckets($aging);

        $this->assertSame(300, $buckets['current']['amount']);
        $this->assertSame(1200, $buckets['1_30']['amount']);
        $this->assertSame(4800, $buckets['31_60']['amount']);
        $this->assertSame(19200, $buckets['61_90']['amount']);
        $this->assertSame(76800, $buckets['91_120']['amount']);
        $this->assertSame(102400, $buckets['over_120']['amount']);

        // Powers of two, so this equality can only hold if every single row
        // landed in exactly one bucket — no overlap, no gap.
        $this->assertSame(204700, $aging['total']);
        $this->assertSame(204400, $aging['past_due_total']);
        $this->assertSame(
            $aging['total'],
            array_sum(array_column($aging['rows'], 'amount')),
        );
    }

    public function test_all_six_buckets_are_present_in_report_order_even_when_empty(): void
    {
        $aging = $this->aging();

        // A stable shape: a column must not disappear the month nothing lands
        // in it. The order is `AGING_BUCKETS` in @/lib/accounting/aging.
        $this->assertSame(
            ['current', '1_30', '31_60', '61_90', '91_120', 'over_120'],
            array_column($aging['rows'], 'bucket'),
        );
        $this->assertSame(self::AS_OF, $aging['as_of']);
        $this->assertSame(0, $aging['total']);
        $this->assertSame(0, $aging['past_due_total']);

        foreach ($aging['rows'] as $row) {
            $this->assertSame(0, $row['amount']);
            $this->assertSame(0, $row['count']);
        }
    }

    public function test_past_due_total_excludes_what_is_not_yet_due(): void
    {
        $loan = $this->loan();
        $this->schedule($loan, '2026-10-30', principal: 900.00); // current
        $this->schedule($loan, '2026-09-01', principal: 100.00); // 29 days late

        $aging = $this->aging();

        $this->assertSame(100000, $aging['total']);
        // Being early is not a degree of lateness.
        $this->assertSame(10000, $aging['past_due_total']);
    }

    public function test_counts_are_instalments_and_therefore_add_up(): void
    {
        // ONE loan, late in two different buckets. The screen's footer sums the
        // count column, so counting distinct loans here — which is what the
        // lending delinquency report correctly does — would make that footer
        // disagree with its own rows.
        $loan = $this->loan();
        $this->schedule($loan, '2026-09-15', principal: 100.00); // 1_30
        $this->schedule($loan, '2026-07-15', principal: 100.00); // 61_90

        $aging = $this->aging();
        $buckets = $this->buckets($aging);

        $this->assertSame(1, $buckets['1_30']['count']);
        $this->assertSame(1, $buckets['61_90']['count']);
        $this->assertSame(2, array_sum(array_column($aging['rows'], 'count')));
    }

    public function test_settled_and_zero_balance_rows_are_not_receivables(): void
    {
        $loan = $this->loan();

        // Outside UNPAID_STATUSES — settled, and must never reach an aging row.
        $this->schedule($loan, '2026-09-15', principal: 500.00, status: 'paid');

        // Unpaid by status but nothing left owing. Skipped rather than bucketed
        // at zero, so it cannot inflate `count` with finished business —
        // `buildAging` skips the same row for the same reason.
        $this->schedule($loan, '2026-09-16', principal: 500.00, principalPaid: 500.00);

        $this->schedule($loan, '2026-09-17', principal: 250.00);

        $aging = $this->aging();

        $this->assertSame(25000, $aging['total']);
        $this->assertSame(1, $this->buckets($aging)['1_30']['count']);
    }

    public function test_an_overpaid_period_cannot_net_off_what_another_still_owes(): void
    {
        $loan = $this->loan();
        // Overpaid by ₱400 on one period, ₱1,000 still owed on another. The
        // per-row GREATEST floor is what stops this reporting ₱600.
        $this->schedule($loan, '2026-09-10', principal: 100.00, principalPaid: 500.00);
        $this->schedule($loan, '2026-09-11', principal: 1000.00);

        $this->assertSame(100000, $this->aging()['total']);
    }

    public function test_only_loans_that_can_still_owe_are_counted(): void
    {
        // `defaulted` IS collectible and must be here — it is exactly the
        // receivable a provisioning policy cares most about.
        $this->schedule($this->loan('defaulted'), '2026-05-01', principal: 700.00);
        $this->schedule($this->loan('ongoing'), '2026-09-20', principal: 300.00);

        // Neither of these can owe anything.
        $this->schedule($this->loan('completed'), '2026-09-20', principal: 999.00);
        $this->schedule($this->loan('draft'), '2026-09-20', principal: 888.00);

        $aging = $this->aging();
        $buckets = $this->buckets($aging);

        $this->assertSame(100000, $aging['total']);
        $this->assertSame(70000, $buckets['over_120']['amount']);
        $this->assertSame(30000, $buckets['1_30']['amount']);
    }

    public function test_grace_is_ignored_so_the_buckets_match_the_lending_report(): void
    {
        // The loan factory grants 3 days of grace. Aging is struck from the
        // BARE due date — a prudential convention, deliberately not a
        // collections one. Honouring grace here would move this row to
        // `current` and shift reported portfolio quality.
        $this->schedule($this->loan(), '2026-09-29', principal: 100.00);

        $this->assertSame(10000, $this->buckets($this->aging())['1_30']['amount']);
    }

    public function test_a_branch_filter_narrows_the_report(): void
    {
        $other = Branch::factory()->create();

        $this->schedule($this->loan(), '2026-09-15', principal: 100.00);
        $this->schedule($this->loan('released', $other->id), '2026-09-15', principal: 900.00);

        $this->assertSame(10000, $this->aging(query: '&branch_id='.$this->branch->id)['total']);
        $this->assertSame(90000, $this->aging(query: '&branch_id='.$other->id)['total']);
        $this->assertSame(10000 + 90000, $this->aging()['total']);
    }

    public function test_as_of_moves_a_row_between_buckets(): void
    {
        $this->schedule($this->loan(), '2026-08-31', principal: 100.00);

        // 30 days late on 30 September, 31 on 1 October — the bucket boundary
        // this report exists to draw.
        $this->assertSame(10000, $this->buckets($this->aging('2026-09-30'))['1_30']['amount']);
        $this->assertSame(10000, $this->buckets($this->aging('2026-10-01'))['31_60']['amount']);
    }

    public function test_as_of_defaults_to_today_in_manila_not_utc(): void
    {
        $aging = $this->getJson('/api/accounting/loans/aging')->assertOk()->json('data');

        // The app runs at UTC+8, so a UTC-sliced date reads as YESTERDAY for
        // the first eight hours of every Manila day — and an aging report dated
        // yesterday moves every boundary by a day.
        $this->assertSame(now()->toDateString(), $aging['as_of']);
    }

    public function test_it_requires_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());
        $this->getJson('/api/accounting/loans/aging')->assertForbidden();
    }

    /** Authenticated, but holding no permissions whatsoever. */
    private function userWithNoRole(): User
    {
        return User::factory()->create(['branch_id' => $this->branch->id]);
    }
}
