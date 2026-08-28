<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /api/borrowers: filter bounds, and the tab counts agreeing with the page.
 *
 * Two defects live here.
 *
 *  1. The endpoint validated NOTHING. `search`, `status` and `branch_id` were
 *     read straight off the request into `when()`, which skips its callback for
 *     any FALSY condition. `?branch_id=0` therefore dropped the branch filter
 *     and answered with every borrower in the cooperative — names, contact
 *     numbers, emails — plus organisation-wide `meta.stats`. `/borrowers/0` on
 *     the frontend reaches it via Number(params.id). The same hole swallowed
 *     `search=0` and `status=0`, which are ordinary values a user can type, and
 *     `?per_page=0` reached paginate(0).
 *  2. `meta.stats` was branch-scoped only, so the tab totals contradicted the
 *     paginator underneath them the moment anyone searched.
 *
 * The counts are asserted against independently created fixtures rather than
 * against the endpoint's own list output, so a bug that skews both in the same
 * direction cannot pass.
 */
class BorrowerListFilterBoundsTest extends TestCase
{
    use SetupLendyPH;

    private Branch $otherBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->otherBranch = Branch::factory()->create(['name' => 'Satellite Branch']);
    }

    // ── 1. The filters are bounded ───────────────────────────────────────

    public function test_a_zero_branch_id_is_rejected_rather_than_returning_every_borrower(): void
    {
        $this->borrower(['first_name' => 'Home']);
        $this->borrower(['first_name' => 'Away', 'branch_id' => $this->otherBranch->id]);

        $this->getJson('/api/borrowers?branch_id=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    }

    public function test_a_negative_branch_id_is_rejected(): void
    {
        $this->getJson('/api/borrowers?branch_id=-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    }

    /**
     * Measured against the pre-fix controller: `per_page=-5` was a 500 (it
     * reached the database as a negative LIMIT) and `per_page=0` was a 200 that
     * silently served 15 instead, because Builder::paginate() falls back to the
     * model default for any falsy page size. Both are now a 422 that says so.
     */
    public function test_a_zero_or_negative_per_page_is_rejected(): void
    {
        $this->borrower();

        $this->getJson('/api/borrowers?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/borrowers?per_page=-5')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * Search still filters after the rewrite to filled().
     *
     * Note on what is deliberately NOT asserted here: `?search=0` is the falsy
     * twin of `?status=0` below, but it cannot be observed on this model.
     * Borrower::booted() stamps every borrower_code as `BRW-` plus a six-digit
     * zero-padded number, so every row under 100,000 contains a `0` and the
     * resulting `%0%` matches the whole table whether the filter is applied or
     * dropped. The falsy-gating fix is therefore pinned by the `status=0` and
     * `branch_id=0` cases, which are observable; this one guards that the
     * ordinary path still works.
     */
    public function test_search_still_filters(): void
    {
        $this->borrower(['first_name' => 'Findme', 'last_name' => 'Alpha']);
        $this->borrower(['first_name' => 'Hidden', 'last_name' => 'Beta']);

        $response = $this->getJson('/api/borrowers?search=Findme')->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Alpha', $response->json('data.0.last_name'));
    }

    public function test_a_status_of_the_literal_zero_matches_nothing_instead_of_everything(): void
    {
        $this->borrower();
        $this->borrower();

        $response = $this->getJson('/api/borrowers?status=0')->assertOk();

        $this->assertSame(0, $response->json('meta.total'), 'status=0 must filter to nothing, not be dropped as falsy.');
    }

    public function test_a_real_branch_id_still_filters_and_an_absent_one_still_spans_the_organisation(): void
    {
        $this->borrower(['first_name' => 'Home']);
        $this->borrower(['first_name' => 'Away', 'branch_id' => $this->otherBranch->id]);

        $scoped = $this->getJson("/api/borrowers?branch_id={$this->branch->id}")->assertOk();
        $this->assertSame(1, $scoped->json('meta.total'));
        $this->assertSame('Home', $scoped->json('data.0.first_name'));

        $this->assertSame(2, $this->getJson('/api/borrowers')->assertOk()->json('meta.total'));
    }

    /**
     * `members_only` is the one input that must NOT be gated on presence:
     * `members_only=0` means "do not apply the filter", so its falsiness is the
     * answer. Moving it to filled() would apply the members filter for a caller
     * who explicitly switched it off.
     */
    public function test_members_only_zero_means_do_not_filter(): void
    {
        $this->borrower(['status' => 'active']);
        $this->borrower(['status' => 'pending']);

        $this->assertSame(2, $this->getJson('/api/borrowers?members_only=0')->assertOk()->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/borrowers?members_only=1')->assertOk()->json('meta.total'));
    }

    // ── 2. The tab counts agree with the page ────────────────────────────

    public function test_stats_follow_the_search_so_the_tabs_agree_with_the_paginator(): void
    {
        $this->borrower(['first_name' => 'Findme', 'last_name' => 'Alpha', 'status' => 'active']);
        $this->borrower(['first_name' => 'Findme', 'last_name' => 'Beta', 'status' => 'inactive']);
        $this->borrower(['first_name' => 'Hidden', 'last_name' => 'Gamma', 'status' => 'active']);
        $this->borrower(['first_name' => 'Hidden', 'last_name' => 'Delta', 'status' => 'active']);

        $response = $this->getJson('/api/borrowers?search=Findme')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.stats.active'));
        $this->assertSame(1, $response->json('meta.stats.inactive'));

        // The tabs must add up to the page the user is looking at.
        $this->assertSame(
            $response->json('meta.total'),
            array_sum($response->json('meta.stats')),
            'The status tabs must total the paginator.',
        );
    }

    /**
     * `status` is the one filter the stats must ignore, or selecting a tab
     * would zero every other tab and the user could never leave it.
     */
    public function test_stats_ignore_the_selected_status_so_every_tab_keeps_its_count(): void
    {
        $this->borrower(['status' => 'active']);
        $this->borrower(['status' => 'active']);
        $this->borrower(['status' => 'inactive']);
        $this->borrower(['status' => 'blacklisted']);

        $response = $this->getJson('/api/borrowers?status=active')->assertOk();

        $this->assertSame(2, $response->json('meta.total'), 'The page itself is status-filtered.');
        $this->assertSame(2, $response->json('meta.stats.active'));
        $this->assertSame(1, $response->json('meta.stats.inactive'), 'The Inactive tab must still show its own count.');
        $this->assertSame(1, $response->json('meta.stats.blacklisted'));
    }

    public function test_stats_stay_branch_scoped(): void
    {
        $this->borrower(['status' => 'active']);
        $this->borrower(['status' => 'active', 'branch_id' => $this->otherBranch->id]);

        $response = $this->getJson("/api/borrowers?branch_id={$this->branch->id}")->assertOk();

        $this->assertSame(1, $response->json('meta.stats.active'));
    }

    /**
     * `members_only` is the one list filter the stats must NOT follow, and for a
     * different reason than `status`.
     *
     * `stats.pending` feeds the pending-registrations badge, and the Members
     * screen is exactly the screen that sends `members_only=1`. Scoping by it
     * would zero that badge at the one place it is read. FrontendApiNeedsTest
     * already pins this from the frontend's side; this asserts it from the
     * filter side so the next person to widen the stats scoping trips over it
     * here too.
     *
     * The price, asserted rather than hidden: under `members_only=1` the tabs
     * do NOT sum to meta.total.
     */
    public function test_members_only_does_not_narrow_the_stats(): void
    {
        $this->borrower(['status' => 'active']);
        $this->borrower(['status' => 'pending']);
        $this->borrower(['status' => 'rejected']);

        $response = $this->getJson('/api/borrowers?members_only=1')->assertOk();

        $this->assertSame(1, $response->json('meta.total'), 'The page itself excludes non-members.');
        $this->assertSame(1, $response->json('meta.stats.active'));
        $this->assertSame(1, $response->json('meta.stats.pending'), 'The pending badge must survive members_only.');
        $this->assertSame(1, $response->json('meta.stats.rejected'));

        $this->assertNotSame(
            $response->json('meta.total'),
            array_sum($response->json('meta.stats')),
            'Under members_only the tabs deliberately do not total the page — see the controller.',
        );
    }

    /**
     * The response keeps all five keys whatever the filters, so the frontend
     * never has to guard for a missing tab.
     */
    public function test_every_status_key_is_always_present(): void
    {
        $this->borrower(['status' => 'active']);

        $this->getJson('/api/borrowers?search=nothingmatchesthis')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['stats' => ['active', 'inactive', 'blacklisted', 'pending', 'rejected']]]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function borrower(array $overrides = []): Borrower
    {
        return Borrower::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
        ], $overrides));
    }
}
