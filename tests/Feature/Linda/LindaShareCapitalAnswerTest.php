<?php

namespace Tests\Feature\Linda;

use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaWording;

/**
 * Linda round 3, share capital questions: the share capital member list's
 * `meta.totals` (GET /share-capital/members, ShareCapitalMemberService):
 * members with share capital, total credits, debits and the balance, in the
 * caller's branches, with `share_capital:view`.
 */
class LindaShareCapitalAnswerTest extends LindaTopicTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $ana = $this->member($this->branch, 'Ana', 'Reyes');
        $ben = $this->member($this->branch, 'Ben', 'Cruz');
        $cora = $this->member($this->far, 'Cora', 'Far');

        ShareCapitalLedger::factory()->create(['borrower_id' => $ana->id, 'credit' => 5000, 'debit' => 0]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ana->id, 'credit' => 0, 'debit' => 1250.5]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $ben->id, 'credit' => 2000, 'debit' => 0]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $cora->id, 'credit' => 3000, 'debit' => 0]);
    }

    /** @return array<string, int|float> */
    private function listTotals(?User $user = null): array
    {
        return $this->actingAs($user)->getJson('/api/share-capital/members')->assertOk()->json('meta.totals');
    }

    public function test_the_figures_equal_the_share_capital_member_list(): void
    {
        $head = $this->headOffice(['share_capital:view']);
        $totals = $this->listTotals($head);
        $reply = $this->ask($head, LindaIntent::ShareCapitalSummary);
        $stats = $this->stats($reply);

        $this->assertSame('answered', $reply->outcome);
        $this->assertSame('ShareCapitalSummaryAnswer', $reply->answerFunction);
        $this->assertSame($totals['member_count'], $stats['Members With Share Capital']);
        $this->assertEquals($totals['balance'], $stats['Share Capital Balance']);
        $this->assertEquals($totals['total_credits'], $stats['Total Credits']);
        $this->assertEquals($totals['total_debits'], $stats['Total Debits']);
        $this->assertSame(
            'As of today, '.LindaWording::today().', share capital across all branches is ₱8,749.50, held by 3 members (₱10,000.00 paid in, ₱1,250.50 taken out).',
            $reply->answer,
        );
        $this->assertSame(['/share-capital'], $this->linkUrls($reply));
        $this->assertSame([], $reply->memberIds);
        $this->assertStringNotContainsString('Ana', json_encode($reply->toArray()));
    }

    public function test_a_branch_user_and_a_named_branch_narrow_it_and_the_permission_is_needed(): void
    {
        $branchUser = $this->branchUser(['share_capital:view']);
        $own = $this->stats($this->ask($branchUser, LindaIntent::ShareCapitalSummary));
        $this->assertSame($this->listTotals($branchUser)['member_count'], $own['Members With Share Capital']);
        $this->assertSame(2, $own['Members With Share Capital']);
        $this->assertSame('no_branch', $this->ask($branchUser, LindaIntent::ShareCapitalSummary, branch: 'Far')->outcome);

        $far = $this->stats($this->ask($this->headOffice(['share_capital:view']), LindaIntent::ShareCapitalSummary, branch: 'Far'));
        $this->assertSame(1, $far['Members With Share Capital']);
        $this->assertEquals(3000, $far['Share Capital Balance']);

        $this->assertSame('denied', $this->ask($this->headOffice(), LindaIntent::ShareCapitalSummary)->outcome);
    }

    /**
     * The member list counts each member's whole balance in their current
     * branch (Phase 6 MB-BE3), so a branch figure says it is held by members
     * now in that branch; the organisation-wide wording is unchanged.
     */
    public function test_a_branch_figure_says_it_is_held_by_members_now_in_that_branch(): void
    {
        $far = $this->ask($this->headOffice(['share_capital:view']), LindaIntent::ShareCapitalSummary, branch: 'Far')->answer;
        $this->assertMatchesRegularExpression('/share capital held by members now in [^,]*Far[^,]* is ₱3,000\.00, held by 1 member \(/u', $far);

        $own = $this->ask($this->branchUser(['share_capital:view']), LindaIntent::ShareCapitalSummary)->answer;
        $this->assertStringContainsString('share capital held by members now in ', $own);

        $all = $this->ask($this->headOffice(['share_capital:view']), LindaIntent::ShareCapitalSummary)->answer;
        $this->assertStringContainsString('share capital across all branches is ', $all);
        $this->assertStringNotContainsString('members now in', $all);
    }

    public function test_share_capital_questions_route_to_the_share_capital_intent(): void
    {
        foreach ([
            'How much share capital do we have?', 'share capital in Main branch', 'Total capital build-up',
            'Magkano ang share capital natin?', 'Ilan ang may share capital?', 'Pila ang share capital nato?',
            'Pila ang kapital sa mga miyembro?',
        ] as $question) {
            $this->assertSame(LindaIntent::ShareCapitalSummary, LindaMatcher::match($question)?->intent, $question);
        }
    }
}
