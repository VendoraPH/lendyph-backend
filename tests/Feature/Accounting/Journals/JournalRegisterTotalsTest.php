<?php

namespace Tests\Feature\Accounting\Journals;

use App\Models\Branch;
use App\Models\User;
use App\Services\Accounting\JournalPoster;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The journal register's search, totals and status counts (Phase 2,
 * Accounting 10): the screen pages on the server and reads its figures from
 * `meta`, so they cover every matching journal, never only the page.
 */
class JournalRegisterTotalsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    public function test_search_matches_number_reference_and_description_with_wildcards_literal(): void
    {
        $percent = $this->postSimpleJournal('5030', '1010', 1000, ['description' => 'Discount 50% promo']);
        $plain = $this->postSimpleJournal('5030', '1010', 2000, ['description' => 'Discount 500 promo']);
        $underscore = $this->draftJournal($this->lines(3000), ['reference' => 'OR_77', 'description' => 'Rent']);
        $this->draftJournal($this->lines(4000), ['reference' => 'ORX77', 'description' => 'Rent']);

        $this->assertSame([$percent->id], $this->ids('search=50%25'));
        $this->assertSame([$underscore->id], $this->ids('search=OR_77'));
        $this->assertSame([$percent->id], $this->ids('search=%25'));
        $this->assertSame([], $this->ids('search='.urlencode('\\')));
        $this->assertSame([$plain->id], $this->ids('search='.urlencode($plain->journal_no)));
    }

    public function test_totals_cover_every_matching_journal_not_only_the_page(): void
    {
        foreach ([100, 250, 375, 1_000_000, 9] as $centavos) {
            $this->postSimpleJournal('5030', '1010', $centavos);
        }
        $this->draftJournal($this->lines(4321));

        $first = $this->getJson('/api/accounting/journals?per_page=2')->assertOk();

        $sum = 0;
        for ($page = 1; $page <= $first->json('meta.last_page'); $page++) {
            $sum += collect($this->getJson("/api/accounting/journals?per_page=2&page={$page}")->json('data'))
                ->sum('total_debit');
        }

        $this->assertCount(2, $first->json('data'));
        $this->assertSame(6, $first->json('meta.totals.count'));
        $this->assertSame($sum, $first->json('meta.totals.total_debit'));
        $this->assertSame(100 + 250 + 375 + 1_000_000 + 9 + 4321, $first->json('meta.totals.total_debit'));
        $this->assertSame($sum, $first->json('meta.totals.total_credit'));
    }

    public function test_totals_follow_the_filters_and_stats_ignore_only_status(): void
    {
        $this->postSimpleJournal('5030', '1010', 500, ['date' => '2026-09-01']);
        $reversed = $this->postSimpleJournal('5030', '1010', 700, ['date' => '2026-09-02']);
        app(JournalPoster::class)->reverse($reversed, '2026-09-03', 'Wrong', $this->admin->id);
        $this->draftJournal($this->lines(900), ['date' => '2026-09-04']);
        $this->postSimpleJournal('5030', '1010', 11000, ['date' => '2026-10-01']);

        $body = $this->getJson('/api/accounting/journals?status=posted&from=2026-09-01&to=2026-09-30')->assertOk()->json();

        // Posted in September: the 500 and the reversal of the 700.
        $this->assertSame(['count' => 2, 'total_debit' => 1200, 'total_credit' => 1200], $body['meta']['totals']);
        $this->assertSame(['draft' => 1, 'posted' => 2, 'reversed' => 1], $body['meta']['stats']);

        $drafts = $this->getJson('/api/accounting/journals?status=draft')->assertOk()->json('meta');
        $this->assertSame(['count' => 1, 'total_debit' => 900, 'total_credit' => 900], $drafts['totals']);
        $this->assertSame(['draft' => 1, 'posted' => 3, 'reversed' => 1], $drafts['stats']);

        $none = $this->getJson('/api/accounting/journals?search=nothing-matches')->assertOk()->json('meta');
        $this->assertSame(['count' => 0, 'total_debit' => 0, 'total_credit' => 0], $none['totals']);
        $this->assertSame(['draft' => 0, 'posted' => 0, 'reversed' => 0], $none['stats']);
    }

    public function test_a_branch_users_totals_leave_out_other_branches_and_unbranched_journals(): void
    {
        $this->postSimpleJournal('5030', '1010', 1000, ['branch_id' => $this->branch->id]);
        $this->postSimpleJournal('5030', '1010', 20000, ['branch_id' => $this->far->id]);
        $this->postSimpleJournal('5030', '1010', 300000, ['branch_id' => null]);

        $this->actingAs($this->branchUser());

        $meta = $this->getJson('/api/accounting/journals')->assertOk()->json('meta');
        $this->assertSame(['count' => 1, 'total_debit' => 1000, 'total_credit' => 1000], $meta['totals']);
        $this->assertSame(['draft' => 0, 'posted' => 1, 'reversed' => 0], $meta['stats']);

        $this->getJson("/api/accounting/journals?branch_id={$this->far->id}")->assertForbidden();

        $this->actingAs($this->admin);
        $this->assertSame(321000, $this->getJson('/api/accounting/journals')->json('meta.totals.total_debit'));
        $this->assertSame(20000, $this->getJson("/api/accounting/journals?branch_id={$this->far->id}")->json('meta.totals.total_debit'));
    }

    public function test_the_page_keeps_its_default_size_and_order(): void
    {
        foreach (range(1, 16) as $n) {
            $this->postSimpleJournal('5030', '1010', $n);
        }

        $body = $this->getJson('/api/accounting/journals')->assertOk()->json();
        $this->assertCount(15, $body['data']);
        $this->assertSame(16, $body['meta']['total']);
        $this->assertSame(16, $body['meta']['totals']['count']);
    }

    /** @return list<array{account_id:int, debit:int, credit:int}> */
    private function lines(int $centavos): array
    {
        return [
            ['account_id' => $this->account('5030'), 'debit' => $centavos, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => $centavos],
        ];
    }

    /** @return list<int> */
    private function ids(string $query): array
    {
        $ids = array_column($this->getJson("/api/accounting/journals?per_page=100&{$query}")->assertOk()->json('data'), 'id');
        sort($ids);

        return $ids;
    }

    /** A loan officer in Main Branch only, with the register. */
    private function branchUser(): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');
        $user->givePermissionTo('journals:view');

        return $user;
    }
}
