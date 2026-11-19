<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaReply;

/**
 * Linda round 3: member questions. The status counts are the Members page's
 * `meta.stats` (GET /borrowers), and "with a loan being repaid", new and
 * repeat members are the Member Report's cards (GET /reports/borrowers), on
 * the same data, in the caller's branches.
 */
class LindaMemberAnswersTest extends LindaAnswersTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Main Branch: 3 active members (2 with a loan being repaid, one of
        // them a repeat member), an inactive member with a loan being repaid,
        // a blacklisted member whose loan is completed, a pending and a
        // rejected registration (not members).
        $ana = $this->member($this->branch, 'Ana', 'Reyes');
        $this->releaseTo($ana);
        $this->releaseTo($ana, 20000);
        $this->releaseTo($this->member($this->branch, 'Ben', 'Cruz'));
        $this->member($this->branch, 'Carla', 'Santos');

        $inactive = $this->member($this->branch, 'Dino', 'Lopez');
        $this->releaseTo($inactive);
        $inactive->update(['status' => 'inactive']);

        $blacklisted = $this->member($this->branch, 'Ely', 'Garcia');
        $this->releaseTo($blacklisted)->update(['status' => 'completed']);
        $blacklisted->update(['status' => 'blacklisted']);

        $this->member($this->branch, 'Fe', 'Pending')->update(['status' => 'pending']);
        $this->member($this->branch, 'Gil', 'Rejected')->update(['status' => 'rejected']);

        // Far Branch: 2 active members, one with a loan being repaid.
        $this->releaseTo($this->member($this->far, 'Hana', 'Far'));
        $this->member($this->far, 'Ivan', 'Far');

        // Not members: a co-maker and a GCash walk-in.
        CoMaker::factory()->create(['borrower_id' => $ana->id, 'first_name' => 'Jose', 'last_name' => 'Comaker']);
        GCashNonMember::factory()->create(['full_name' => 'Kiko Walkin']);
    }

    private function ask(User $user, ?LindaPeriod $period = null, ?string $branch = null, string $question = 'How many members do we have?'): LindaReply
    {
        return app(LindaAnswerer::class)->answer($user, LindaIntent::BorrowerSummary, $period, $branch, $question);
    }

    /** @return array<string, mixed> */
    private function stats(LindaReply $reply): array
    {
        $values = [];
        foreach ($reply->blocks as $block) {
            foreach ($block['type'] === 'stats' ? $block['items'] : [] as $item) {
                $values[$item['label']] = $item['value'];
            }
        }

        return $values;
    }

    /** @return array<string, int> */
    private function membersPageStats(User $user, ?int $branchId = null): array
    {
        $this->actingAs($user);

        return $this->getJson('/api/borrowers?members_only=1'.($branchId === null ? '' : '&branch_id='.$branchId))
            ->assertOk()
            ->json('meta.stats');
    }

    /** @return array<string, mixed> */
    private function memberReportCards(User $user, ?LindaPeriod $period = null, ?int $branchId = null): array
    {
        $this->actingAs($user);
        $query = array_filter([
            'date_from' => $period?->from->toDateString(),
            'date_to' => $period?->to->toDateString(),
            'branch_id' => $branchId,
        ]);

        return $this->getJson('/api/reports/borrowers?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_the_counts_equal_the_members_page_and_the_member_report(): void
    {
        $user = $this->headOffice(['reports:view', 'borrowers:view', 'loans:view']);

        $reply = $this->ask($user);
        $stats = $this->stats($reply);
        $page = $this->membersPageStats($user);
        $cards = $this->memberReportCards($user);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('borrower_summary', $reply->intent);
        $this->assertSame('MemberSummaryAnswer', $reply->answerFunction);
        $this->assertSame($page['active'], $stats['Active']);
        $this->assertSame($page['inactive'], $stats['Inactive']);
        $this->assertSame($page['blacklisted'], $stats['Blacklisted']);
        $this->assertSame($page['active'] + $page['inactive'] + $page['blacklisted'] + $page['withdrawn'] + $page['deceased'], $stats['Members']);
        $this->assertSame($cards['total_active_borrowers'], $stats['With a Loan Being Repaid']);

        // Walk-ins, co-makers and pending or rejected registrations are left out.
        $this->assertSame(5, $stats['Active']);
        $this->assertSame(1, $stats['Inactive']);
        $this->assertSame(1, $stats['Blacklisted']);
        $this->assertSame(7, $stats['Members']);
        // Collectible loans only: the inactive member's counts, the completed one doesn't.
        $this->assertSame(4, $stats['With a Loan Being Repaid']);

        $this->assertStringContainsString('you have 7 members across all branches: 5 active, 1 inactive and 1 blacklisted.', $reply->answer);
        $this->assertStringContainsString('4 members have a loan being repaid.', $reply->answer);
        $this->assertStringNotContainsStringIgnoringCase('borrower', $reply->answer);
        $this->assertContains(['label' => 'Open Members', 'url' => '/borrowers'], $reply->links);
        $this->assertContains(['label' => 'Open Member Report', 'url' => '/reports/borrower_report'], $reply->links);
    }

    public function test_the_active_question_leads_with_the_active_count(): void
    {
        $reply = $this->ask($this->headOffice(), question: 'How many active members do we have?');

        $this->assertMatchesRegularExpression('/^As of today, .+, you have 5 active members across all branches\. 4 members have a loan being repaid\.$/', $reply->answer);
    }

    public function test_new_and_repeat_members_follow_the_period_like_the_member_report(): void
    {
        $user = $this->headOffice();
        $thisMonth = LindaPeriod::named('this_month');
        Borrower::query()->where('first_name', 'Ivan')->update(['created_at' => now()->subMonths(3)]);

        $reply = $this->ask($user, $thisMonth, question: 'How many new members this month?');
        $cards = $this->memberReportCards($user, $thisMonth);
        $stats = $this->stats($reply);

        $this->assertSame($cards['new_borrowers'], $stats['New Members']);
        $this->assertSame($cards['repeat_borrowers'], $stats['Repeat Members']);
        $this->assertSame(6, $stats['New Members']);
        $this->assertSame(1, $stats['Repeat Members']);
        $this->assertStringContainsString('6 new members joined across all branches.', $reply->answer);
        $this->assertContains([
            'label' => 'Open Member Report',
            'url' => '/reports/borrower_report?'.http_build_query(['from' => $thisMonth->from->toDateString(), 'to' => $thisMonth->to->toDateString()]),
        ], $reply->links);

        // No period on a new-members question: this month, as the other period figures.
        $this->assertSame(6, $this->stats($this->ask($user, question: 'How many new members?'))['New Members']);

        $repeat = $this->ask($user, question: 'How many repeat borrowers do we have?');
        $this->assertStringStartsWith('1 member has had 2 or more loans released (repeat members)', $repeat->answer);
    }

    public function test_status_counts_ignore_the_period(): void
    {
        $reply = $this->ask($this->headOffice(), LindaPeriod::named('last_month'), question: 'How many active members last month?');

        $this->assertSame(5, $this->stats($reply)['Active']);
        $this->assertStringContainsString('These figures are as of today', $reply->answer);
    }

    public function test_a_period_without_other_words_reads_new_and_repeat_members(): void
    {
        $reply = $this->ask($this->headOffice(), LindaPeriod::named('last_month'), question: 'how about last month?');

        $this->assertSame(0, $this->stats($reply)['New Members']);
        $this->assertArrayNotHasKey('Active', $this->stats($reply));
    }

    public function test_a_branch_user_sees_only_their_branches_and_a_named_branch_narrows_it(): void
    {
        $branchUser = $this->branchUser();
        $stats = $this->stats($this->ask($branchUser));
        $page = $this->membersPageStats($branchUser);

        $this->assertSame($page['active'], $stats['Active']);
        $this->assertSame(3, $stats['Active']);
        $this->assertSame(3, $stats['With a Loan Being Repaid']);

        $head = $this->headOffice();
        $far = $this->ask($head, branch: 'Far');
        $farStats = $this->stats($far);

        $this->assertSame($this->membersPageStats($head, $this->far->id)['active'], $farStats['Active']);
        $this->assertSame($this->memberReportCards($head, null, $this->far->id)['total_active_borrowers'], $farStats['With a Loan Being Repaid']);
        $this->assertSame(2, $farStats['Active']);
        $this->assertSame(1, $farStats['With a Loan Being Repaid']);
        $this->assertStringContainsString('for Far Branch', $far->answer);
        $this->assertContains(['label' => 'Open Member Report', 'url' => '/reports/borrower_report?branch='.$this->far->id], $far->links);

        // Another branch reads exactly like one that doesn't exist.
        $this->assertSame('no_branch', $this->ask($branchUser, branch: 'Far')->outcome);
    }

    public function test_permissions_choose_the_figures(): void
    {
        // Members page only: the status counts, no Member Report figures.
        $membersOnly = $this->ask($this->headOffice(['borrowers:view']));
        $this->assertSame('answered', $membersOnly->outcome);
        $this->assertArrayNotHasKey('With a Loan Being Repaid', $this->stats($membersOnly));
        $this->assertStringNotContainsString('loan being repaid', $membersOnly->answer);
        $this->assertSame([['label' => 'Open Members', 'url' => '/borrowers']], $membersOnly->links);

        // Member Report only: new and repeat members, and the status counts are refused.
        $reportsOnly = $this->headOffice(['reports:view']);
        $this->assertSame('denied', $this->ask($reportsOnly)->outcome);
        $this->assertSame('answered', $this->ask($reportsOnly, question: 'How many new members?')->outcome);

        // Neither: refused, with no figures.
        $neither = $this->ask($this->headOffice(['loans:view']));
        $this->assertSame('denied', $neither->outcome);
        $this->assertSame([], $neither->blocks);
        $this->assertSame('denied', $this->ask($this->headOffice(['borrowers:view']), question: 'How many new members?')->outcome);
    }

    public function test_the_reply_lists_no_member(): void
    {
        $reply = $this->ask($this->headOffice());

        $this->assertSame([], $reply->memberIds);
        $this->assertStringNotContainsString('Ana', json_encode($reply->toArray()));
    }
}
