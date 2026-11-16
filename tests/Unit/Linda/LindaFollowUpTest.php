<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaFollowUp;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;

/**
 * What a follow-up keeps from the conversation (money review M1): it walks
 * back only through follow-ups, and stops at the first question that wasn't
 * one. That question's period (or its this-month default) and its branch,
 * none included, are what the follow-up keeps. One rule for the endpoint and
 * `linda:evaluate`.
 */
class LindaFollowUpTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->atManila('2026-11-16 09:00');
    }

    public function test_a_follow_up_after_a_question_with_no_period_keeps_its_this_month_not_an_older_month(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, 'cabadbaran', true, [
            ['question' => 'How much did we collect last month?', 'intent' => 'collection_summary'],
            ['question' => 'How much did we collect?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame('this_month', $period?->key);
        $this->assertSame('cabadbaran', $branch);
    }

    public function test_a_follow_up_after_a_question_for_all_branches_keeps_all_branches_not_an_older_branch(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::DisbursementSummary, LindaPeriod::named('last_month'), null, true, [
            ['question' => 'How much did we collect in Main branch?', 'intent' => 'collection_summary'],
            ['question' => 'How much have we disbursed?', 'intent' => 'disbursement_summary'],
        ]);

        $this->assertSame('last_month', $period?->key);
        $this->assertNull($branch);
    }

    public function test_it_walks_back_through_follow_ups_to_the_question_they_follow(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, LindaPeriod::named('yesterday'), null, true, [
            ['question' => 'How much did we collect last month?', 'intent' => 'collection_summary'],
            ['question' => 'how about sa Riverside?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame('yesterday', $period?->key);
        $this->assertSame('riverside', $branch);

        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, null, true, [
            ['question' => 'How much did we collect last month?', 'intent' => 'collection_summary'],
            ['question' => 'how about sa Riverside?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame('last_month', $period?->key);
        $this->assertSame('riverside', $branch);
    }

    public function test_a_new_subject_keeps_the_branch_but_never_the_earlier_period(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::OverdueAccounts, null, null, true, [
            ['question' => 'How much did we collect last month in Main branch?', 'intent' => 'collection_summary'],
        ]);

        $this->assertNull($period);
        $this->assertSame('main', $branch);
    }

    public function test_a_question_that_isnt_a_follow_up_keeps_nothing_and_gets_its_default(): void
    {
        $earlier = [['question' => 'How much did we collect last month in Main branch?', 'intent' => 'collection_summary']];

        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, null, false, $earlier);
        $this->assertSame('this_month', $period?->key);
        $this->assertNull($branch);

        [$period, $branch] = LindaFollowUp::carry(LindaIntent::LoanSummary, null, null, false, $earlier);
        $this->assertNull($period);
        $this->assertNull($branch);
    }

    public function test_an_earlier_question_the_matcher_cant_read_ends_the_walk_with_its_own_period(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, null, true, [
            ['question' => 'How much did we collect in Main branch last month?', 'intent' => 'collection_summary'],
            ['question' => 'What did the cooperative take in last week?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame('last_week', $period?->key);
        $this->assertNull($branch);
    }

    public function test_the_period_search_stops_at_a_change_of_subject(): void
    {
        [$period] = LindaFollowUp::carry(LindaIntent::DisbursementSummary, null, null, true, [
            ['question' => 'disbursed last year', 'intent' => 'disbursement_summary'],
            ['question' => 'how about collected?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame('this_month', $period?->key);
    }
}
