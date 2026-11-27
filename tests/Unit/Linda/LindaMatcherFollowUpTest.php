<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;

/**
 * Edges of the matcher's follow-ups and branch phrases beyond the spec in
 * LindaMatcherTest.
 */
class LindaMatcherFollowUpTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-16 09:00');
    }

    public function test_a_follow_up_phrase_with_a_subject_and_nothing_before_is_a_new_question(): void
    {
        $match = LindaMatcher::match('how about collections?', null);

        $this->assertSame(LindaIntent::CollectionSummary, $match?->intent);
        $this->assertFalse($match->followUp);
        $this->assertSame('this_month', $match->period?->key);
    }

    public function test_a_follow_up_with_its_subject_leaves_the_period_to_the_caller(): void
    {
        $match = LindaMatcher::match('how about collections?', LindaIntent::DisbursementSummary);

        $this->assertSame(LindaIntent::CollectionSummary, $match?->intent);
        $this->assertTrue($match->followUp);
        $this->assertNull($match->period);
    }

    public function test_several_follow_up_words_together(): void
    {
        $match = LindaMatcher::match('eh paano naman kahapon?', LindaIntent::CollectionSummary);

        $this->assertSame(LindaIntent::CollectionSummary, $match?->intent);
        $this->assertSame('yesterday', $match->period?->key);
        $this->assertTrue($match->followUp);
    }

    public function test_sa_before_a_filler_word_is_not_a_branch(): void
    {
        $match = LindaMatcher::match('Ilan ang atraso sa mga members?', null);

        $this->assertSame(LindaIntent::OverdueAccounts, $match?->intent);
        $this->assertNull($match->branchName);
    }

    public function test_the_word_branch_with_no_name_is_not_understood(): void
    {
        $this->assertNull(LindaMatcher::match('collections at the branch', null));
    }

    public function test_a_branch_name_is_at_most_three_words(): void
    {
        $this->assertNull(LindaMatcher::match('collections in north east river side branch', null));
        $this->assertSame('north east river', LindaMatcher::match('collections in north east river branch', null)?->branchName);
    }

    public function test_a_lookup_is_never_carried_on_by_a_follow_up(): void
    {
        foreach ([LindaIntent::TransactionLookup, LindaIntent::LoanDetails, LindaIntent::BorrowerLookup] as $lookup) {
            $this->assertNull(LindaMatcher::match('Show the payments last month', $lookup), $lookup->value);
            $this->assertNull(LindaMatcher::match('how about last month?', $lookup), $lookup->value);
        }

        $this->assertSame(LindaIntent::CollectionSummary, LindaMatcher::match('how about last month?', LindaIntent::CollectionSummary)?->intent);
    }
}
