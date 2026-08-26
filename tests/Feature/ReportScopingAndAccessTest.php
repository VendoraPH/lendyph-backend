<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Branch scoping, roster exposure and period bounds on the reports.
 *
 * Three defects live here, and each is asserted against an independently
 * computed expectation rather than against the endpoint's own output:
 *
 *  1. Cash Flow added the WHOLE organisation's share capital to a
 *     branch-filtered report, so it contradicted the Share Capital report for
 *     the same filter. The reports must now agree figure-for-figure.
 *  2. `share-capital`'s `by_member` handed the complete membership roster to
 *     every role holding `reports:view` — which is all of them. It is now
 *     gated on `reports:export`, and its absence has to be legible in the
 *     payload rather than silent.
 *  3. An uncapped date span amplified into one month bucket per month of the
 *     range, so a single GET could ask for thousands of them.
 */
class ReportScopingAndAccessTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── 1. Cash Flow vs Share Capital: one filter, one answer ────────────

    /**
     * The exact contradiction, reproduced: two branches, same day, one
     * branch-filtered call to each report.
     */
    public function test_cash_flow_scopes_share_capital_by_member_branch_and_agrees_with_share_capital_report(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $today = Carbon::today()->toDateString();

        // 4,000 belongs to the main branch's member, 1,000 to the satellite's.
        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);
        $this->ledgerEntry($this->memberOf($satellite), $today, credit: 1000);

        $cashFlow = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}&branch_id={$satellite->id}")
            ->assertOk()->json('data');

        $shareCapital = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}&branch_id={$satellite->id}")
            ->assertOk()->json('data');

        // Only the satellite member's 1,000 — not the organisation's 5,000.
        $this->assertEqualsWithDelta(
            1000,
            $cashFlow['inflows']['share_capital_credit'],
            0.01,
            'A branch-filtered Cash Flow must count only that branch members\' share capital.',
        );
        $this->assertSame(1, $cashFlow['share_capital']['count']);

        // The two reports now describe the same scope with the same word...
        $this->assertSame('borrower_branch', $cashFlow['share_capital']['branch_scope']);
        $this->assertSame('borrower_branch', $shareCapital['branch_scope']);

        // ...and arrive at the same numbers.
        $this->assertEqualsWithDelta(
            $shareCapital['credits'],
            $cashFlow['share_capital']['credit'],
            0.01,
            'Cash Flow and Share Capital must not disagree for the same branch and period.',
        );
        $this->assertEqualsWithDelta(
            $shareCapital['debits'],
            $cashFlow['share_capital']['debit'],
            0.01,
        );
        $this->assertEqualsWithDelta(
            $shareCapital['net_movement'],
            $cashFlow['share_capital']['net_movement'],
            0.01,
        );
    }

    /**
     * The headline totals are what the bug corrupted, so assert the arithmetic
     * end to end: with no loan cash on the day, inflows.total IS the branch's
     * share capital and nothing else.
     */
    public function test_branch_filtered_cash_flow_headline_total_excludes_other_branches_share_capital(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $today = Carbon::today()->toDateString();

        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);
        $this->ledgerEntry($this->memberOf($satellite), $today, credit: 1000);
        // A withdrawal at the satellite, so the debit side is scoped too.
        $this->ledgerEntry($this->memberOf($satellite), $today, debit: 250);

        $data = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}&branch_id={$satellite->id}")
            ->assertOk()->json('data');

        $this->assertEqualsWithDelta(0, $data['inflows']['repayments']['total'], 0.01);
        $this->assertEqualsWithDelta(1000, $data['inflows']['total'], 0.01);
        $this->assertEqualsWithDelta(250, $data['outflows']['share_capital_debit'], 0.01);
        $this->assertEqualsWithDelta(250, $data['outflows']['total'], 0.01);
        $this->assertEqualsWithDelta(750, $data['net_movement'], 0.01);
    }

    /**
     * Unfiltered, the figures stay organisation-wide — the fix narrows the
     * scope only when a branch was actually asked for.
     */
    public function test_unfiltered_cash_flow_still_reports_share_capital_organisation_wide(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $today = Carbon::today()->toDateString();

        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);
        $this->ledgerEntry($this->memberOf($satellite), $today, credit: 1000);

        $data = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}")
            ->assertOk()->json('data');

        $this->assertSame('organisation', $data['share_capital']['branch_scope']);
        $this->assertEqualsWithDelta(5000, $data['share_capital']['credit'], 0.01);
        $this->assertEqualsWithDelta(5000, $data['inflows']['share_capital_credit'], 0.01);
        $this->assertSame(2, $data['share_capital']['count']);
    }

    /**
     * `branch_scope` is a two-value vocabulary shared with the Share Capital
     * report, and the note has to describe whichever one actually applied.
     */
    public function test_cash_flow_branch_scope_vocabulary_matches_share_capital_report(): void
    {
        $satellite = Branch::factory()->create(['name' => 'Satellite Branch']);
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($this->memberOf($satellite), $today, credit: 1000);

        foreach ([null => 'organisation', $satellite->id => 'borrower_branch'] as $branchId => $expected) {
            $query = $branchId ? "&branch_id={$branchId}" : '';

            $cashFlow = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}{$query}")
                ->assertOk()->json('data');
            $shareCapital = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}{$query}")
                ->assertOk()->json('data');

            $this->assertSame($expected, $cashFlow['share_capital']['branch_scope']);
            $this->assertSame(
                $shareCapital['branch_scope'],
                $cashFlow['share_capital']['branch_scope'],
                'Both reports must name the same scope identically.',
            );

            // The note is part of the contract and several readers trust it,
            // so it must describe the scope that actually applied rather than
            // keep asserting the pre-fix organisation-wide behaviour.
            $note = $cashFlow['share_capital']['note'];

            if ($expected === 'borrower_branch') {
                $this->assertStringContainsString('member\'s branch', $note);
                $this->assertStringNotContainsString('organisation-wide', $note);
            } else {
                $this->assertStringContainsString('organisation-wide', $note);
                $this->assertStringNotContainsString('member\'s branch', $note);
            }
            $this->assertStringContainsString('by_branch', $note);
        }
    }

    /**
     * Share capital stays out of by_branch — that part was right and must not
     * drift while the headline is being fixed.
     */
    public function test_share_capital_remains_excluded_from_by_branch(): void
    {
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);

        $data = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}")
            ->assertOk()->json('data');

        foreach ($data['by_branch'] as $row) {
            $this->assertArrayNotHasKey('share_capital_credit', $row);
            $this->assertArrayNotHasKey('share_capital_debit', $row);
        }

        // by_branch reconciles to the loan cash only; add share capital back to
        // reach the headline, exactly as the docblock promises.
        $branchInflow = array_sum(array_column($data['by_branch'], 'inflow_total'));
        $this->assertEqualsWithDelta(
            $data['inflows']['total'],
            $branchInflow + $data['inflows']['share_capital_credit'],
            0.01,
        );
    }

    // ── 2. by_member is roster data, not aggregate data ──────────────────

    public function test_by_member_is_returned_to_a_caller_holding_reports_export(): void
    {
        $today = Carbon::today()->toDateString();
        $member = $this->memberOf($this->branch);
        $this->ledgerEntry($member, $today, credit: 4000);

        // super_admin holds every permission, reports:export included.
        $data = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}")
            ->assertOk()->json('data');

        $this->assertIsArray($data['by_member']);
        $this->assertNull($data['by_member_omitted']);
        $this->assertSame($member->id, $data['by_member'][0]['borrower_id']);
    }

    public function test_by_member_is_withheld_from_a_reports_view_only_caller_with_an_explicit_reason(): void
    {
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);
        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 1500);

        foreach (['collector', 'viewer', 'cashier', 'general_bookkeeper'] as $roleName) {
            $this->actingAs($this->userWithRole($roleName));

            $data = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}")
                ->assertOk()->json('data');

            $this->assertNull(
                $data['by_member'],
                "{$roleName} holds reports:view only and must not receive the membership roster.",
            );
            // Null, not [] — an empty array would be a false claim that the
            // cooperative has no members holding share capital.
            $this->assertArrayHasKey('by_member', $data);

            $this->assertSame('permission_required', $data['by_member_omitted']['reason']);
            $this->assertSame('reports:export', $data['by_member_omitted']['required_permission']);
            $this->assertNotEmpty($data['by_member_omitted']['message']);

            // No borrower identity leaks anywhere else in the payload.
            $encoded = json_encode($data);
            $this->assertStringNotContainsString('borrower_code', $encoded);
            $this->assertStringNotContainsString('full_name', $encoded);
        }
    }

    /**
     * Withholding the roster must not change a single number. The aggregates
     * are the report; the roster is an attachment.
     */
    public function test_aggregate_figures_are_identical_with_and_without_reports_export(): void
    {
        $today = Carbon::today()->toDateString();

        // Exactly two members, one of whom also withdraws, so member_count has
        // a hand-computed value the roster gate must not disturb.
        $first = $this->memberOf($this->branch);
        $second = $this->memberOf($this->branch);
        $this->ledgerEntry($first, $today, credit: 4000);
        $this->ledgerEntry($second, $today, credit: 1500);
        $this->ledgerEntry($second, $today, debit: 500);

        $url = "/api/reports/share-capital?date_from={$today}&date_to={$today}";

        $withExport = $this->getJson($url)->assertOk()->json('data');

        $this->actingAs($this->userWithRole('viewer'));
        $withoutExport = $this->getJson($url)->assertOk()->json('data');

        foreach ([
            'opening_balance', 'credits', 'debits', 'net_movement', 'closing_balance',
            'entry_count', 'member_count', 'members_with_activity', 'branch_scope',
            'subscription', 'by_month',
        ] as $key) {
            $this->assertEquals(
                $withExport[$key],
                $withoutExport[$key],
                "`{$key}` is an aggregate and must not depend on the caller's permissions.",
            );
        }

        // member_count in particular is derived from the member list, so prove
        // it survived the roster being withheld.
        $this->assertSame(2, $withoutExport['member_count']);
    }

    /**
     * The gate is server-side. A caller cannot ask for the roster back.
     */
    public function test_a_client_cannot_re_enable_by_member_through_the_query_string(): void
    {
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($this->memberOf($this->branch), $today, credit: 4000);

        $this->actingAs($this->userWithRole('viewer'));

        foreach (['include_members=1', 'by_member=1', 'includeMembers=true'] as $attempt) {
            $data = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}&{$attempt}")
                ->assertOk()->json('data');

            $this->assertNull($data['by_member'], "`{$attempt}` must not unlock the roster.");
        }
    }

    // ── 3. The reporting period is bounded ───────────────────────────────

    /**
     * The probe from the finding: ~6,000 month buckets from one GET.
     */
    #[DataProvider('oversizedSpans')]
    public function test_an_oversized_reporting_period_is_rejected(string $query, string $expectedErrorField): void
    {
        $this->getJson("/api/reports/collection-efficiency?{$query}")
            ->assertStatus(422)
            ->assertJsonValidationErrors([$expectedErrorField]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function oversizedSpans(): array
    {
        return [
            'both ends given' => ['date_from=1900-01-01&date_to=2400-01-01', 'date_from'],
            // Omitting an end must not bypass the cap: the resolver defaults it
            // to today, so the span is still ~126 years.
            'only date_from' => ['date_from=1900-01-01', 'date_from'],
            'only date_to' => ['date_to=2400-01-01', 'date_to'],
            // as_of_date carries no after_or_equal rule, so this is the one
            // shape that genuinely reaches the resolver's reversed-range swap.
            'reversed via as_of_date' => ['date_from=2400-01-01&as_of_date=1900-01-01', 'date_from'],
        ];
    }

    /**
     * Every report inherits the cap, because it lives in the shared filter
     * validation rather than in any one endpoint.
     */
    public function test_every_report_inherits_the_span_cap(): void
    {
        $endpoints = [
            '/api/reports/cash-flow',
            '/api/reports/collection-efficiency',
            '/api/reports/share-capital',
            '/api/reports/releases',
            '/api/reports/repayments',
            '/api/reports/due-past-due',
            '/api/reports/loan-balance-summary',
            '/api/reports/daily-collection',
            '/api/reports/income',
            '/api/reports/aging',
            '/api/reports/borrowers',
            '/api/reports/disbursements',
            '/api/reports/portfolio-by-product',
            '/api/reports/performance',
            '/api/reports/provisioning',
        ];

        foreach ($endpoints as $endpoint) {
            $status = $this->getJson("{$endpoint}?date_from=1900-01-01&date_to=2400-01-01")->status();

            $this->assertSame(422, $status, "{$endpoint} must inherit the span cap.");
        }
    }

    public function test_the_cap_is_ten_years_and_a_period_inside_it_still_works(): void
    {
        $withinCap = Carbon::today()->subYears(10)->addDay()->toDateString();
        $atCap = Carbon::today()->subYears(10)->toDateString();
        $overCap = Carbon::today()->subYears(10)->subDay()->toDateString();
        $today = Carbon::today()->toDateString();

        $this->getJson("/api/reports/collection-efficiency?date_from={$withinCap}&date_to={$today}")
            ->assertOk();
        // Exactly ten years is inside the cap, not over it.
        $this->getJson("/api/reports/collection-efficiency?date_from={$atCap}&date_to={$today}")
            ->assertOk();
        $this->getJson("/api/reports/collection-efficiency?date_from={$overCap}&date_to={$today}")
            ->assertStatus(422);
    }

    /**
     * The cap exists to bound `monthBuckets`, so assert the thing it bounds.
     */
    public function test_month_buckets_stay_bounded_for_the_widest_accepted_period(): void
    {
        $from = Carbon::today()->subYears(10)->toDateString();
        $to = Carbon::today()->toDateString();

        $data = $this->getJson("/api/reports/collection-efficiency?date_from={$from}&date_to={$to}")
            ->assertOk()->json('data');

        // Ten years touches at most 121 calendar months.
        $this->assertLessThanOrEqual(121, count($data['by_period']));
    }

    /**
     * A report with no dates at all, and the share capital reports' "since
     * inception" mode, are both unaffected: there is no caller-supplied span
     * to measure, and inception buckets are anchored on real ledger rows.
     */
    public function test_reports_without_a_caller_supplied_span_are_untouched(): void
    {
        $this->ledgerEntry($this->memberOf($this->branch), Carbon::today()->toDateString(), credit: 4000);

        $this->getJson('/api/reports/cash-flow')->assertOk();
        $this->getJson('/api/reports/collection-efficiency')->assertOk();

        $sinceInception = $this->getJson('/api/reports/share-capital')->assertOk()->json('data');
        $this->assertNull($sinceInception['date_from']);
        $this->assertEqualsWithDelta(4000, $sinceInception['credits'], 0.01);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function memberOf(Branch $branch): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $branch->id]);
    }

    private function ledgerEntry(Borrower $borrower, string $date, float $credit = 0, float $debit = 0): ShareCapitalLedger
    {
        return ShareCapitalLedger::factory()->create([
            'borrower_id' => $borrower->id,
            'date' => $date,
            'credit' => $credit,
            'debit' => $debit,
            'created_by' => $this->admin->id,
            'description' => $debit > 0 ? 'Share capital withdrawal' : 'Share capital contribution',
        ]);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

        return $user->fresh();
    }
}
