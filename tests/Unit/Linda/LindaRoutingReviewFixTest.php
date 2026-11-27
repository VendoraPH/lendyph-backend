<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaFollowUp;
use App\Services\Linda\LindaHistory;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaPrompt;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The routing pieces the round 2 reviews changed: the pending lookup the
 * history carries (H1), a follow-up that skips lookups (M2), the lookup kind
 * of an intent (nit), aggregate "find" questions the matcher answers (L2),
 * and earlier questions sent to the model as topics only (D1).
 */
class LindaRoutingReviewFixTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-16 09:00');
    }

    public function test_the_history_carries_the_kind_of_a_lookup_answered_last(): void
    {
        $history = LindaHistory::from([
            ['role' => 'user', 'content' => 'How much have we collected this month?'],
            ['role' => 'assistant', 'content' => '', 'intent' => 'collection_summary'],
            ['role' => 'user', 'content' => 'Pila ang utang ni?'],
            ['role' => 'assistant', 'content' => '', 'intent' => 'borrower_lookup'],
        ], 5);

        $this->assertSame(LindaLookupRequest::MEMBER, $history->pendingLookup);
        $this->assertSame(LindaIntent::CollectionSummary, $history->previousIntent);
    }

    public function test_a_later_turn_clears_the_pending_lookup(): void
    {
        $history = LindaHistory::from([
            ['role' => 'user', 'content' => 'status of loan 42'],
            ['role' => 'assistant', 'content' => '', 'intent' => 'loan_details'],
            ['role' => 'user', 'content' => 'Which members currently have overdue payments?'],
            ['role' => 'assistant', 'content' => '', 'intent' => 'overdue_accounts'],
        ], 5);

        $this->assertNull($history->pendingLookup);
        $this->assertSame(LindaLookupRequest::LOAN, LindaHistory::from([
            ['role' => 'user', 'content' => 'status of loan 42'],
            ['role' => 'assistant', 'content' => '', 'intent' => 'loan_details'],
        ], 5)->pendingLookup);
        $this->assertNull(LindaHistory::from([], 5)->pendingLookup);
    }

    public function test_a_follow_up_skips_earlier_lookups(): void
    {
        [$period, $branch] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, 'main', true, [
            ['question' => 'collect last week', 'intent' => 'collection_summary'],
            ['question' => 'utang ni Juan', 'intent' => 'borrower_lookup'],
        ]);

        $this->assertSame('main', $branch);
        $this->assertSame(LindaPeriod::named('last_week')->from->toDateString(), $period?->from->toDateString());
    }

    public function test_a_follow_up_reads_the_intent_before_an_earlier_question_skipping_lookups(): void
    {
        [$period] = LindaFollowUp::carry(LindaIntent::CollectionSummary, null, null, true, [
            ['question' => 'How much did we collect last week?', 'intent' => 'collection_summary'],
            ['question' => 'Find receipt RCP-000123', 'intent' => 'transaction_lookup'],
            ['question' => 'sa Main naman?', 'intent' => 'collection_summary'],
        ]);

        $this->assertSame(LindaPeriod::named('last_week')->from->toDateString(), $period?->from->toDateString());
    }

    public function test_each_lookup_intent_has_its_lookup_kind(): void
    {
        $this->assertSame(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup->lookupKind());
        $this->assertSame(LindaLookupRequest::LOAN, LindaIntent::LoanDetails->lookupKind());
        $this->assertSame(LindaLookupRequest::RECEIPT, LindaIntent::TransactionLookup->lookupKind());
        $this->assertNull(LindaIntent::CollectionSummary->lookupKind());
    }

    /** @return array<string, array{string, LindaIntent}> */
    public static function aggregateFinds(): array
    {
        return [
            'find overdue members' => ['Find overdue members', LindaIntent::OverdueAccounts],
            'search past-due loans' => ['Search past-due loans', LindaIntent::OverdueAccounts],
            'hanapin ang atraso' => ['Hanapin ang mga atraso', LindaIntent::OverdueAccounts],
            'pangitaa ang atraso' => ['Pangitaa ang naay atraso', LindaIntent::OverdueAccounts],
            'pangita active loans' => ['pangita active loans', LindaIntent::LoanSummary],
        ];
    }

    #[DataProvider('aggregateFinds')]
    public function test_aggregate_find_questions_are_answered_by_the_matcher(string $question, LindaIntent $intent): void
    {
        $this->assertSame($intent, LindaMatcher::match($question)?->intent, $question);
    }

    public function test_a_find_with_a_name_or_a_code_is_no_match(): void
    {
        $this->assertNull(LindaMatcher::match('Find Juan Dela Cruz'));
        $this->assertNull(LindaMatcher::match('Find overdue Juan'));
        $this->assertNull(LindaMatcher::match('search LN-000042'));
    }

    public function test_a_model_request_keeps_only_the_topics_of_earlier_turns(): void
    {
        $request = new LindaModelRequest('and now?', [
            'collection_summary', LindaIntent::OverdueAccounts, null, 'make_coffee', 'borrower_lookup', LindaIntent::LoanDetails,
            'disbursement_summary', 'income_summary', 'loan_summary', 'expense_summary',
        ], 'claude-haiku-5-5');

        $this->assertSame(['overdue_accounts', 'disbursement_summary', 'income_summary', 'loan_summary', 'expense_summary'], $request->topics);
        $this->assertSame(LindaIntent::ExpenseSummary, $request->previousIntent());
        $this->assertNull((new LindaModelRequest('q', [], 'claude-haiku-5-5'))->previousIntent());
    }

    public function test_the_user_message_holds_the_question_and_the_earlier_topics_only(): void
    {
        $message = LindaPrompt::userMessage('how about sa Main?', ['collection_summary', 'disbursement_summary']);

        $this->assertSame(1, preg_match('/(\{.*\})/s', $message, $json));
        $this->assertSame(['earlier_topics' => ['collection_summary', 'disbursement_summary'], 'question' => 'how about sa Main?'], json_decode($json[1], true));
        $this->assertStringContainsString('earlier_topics', LindaPrompt::system());
        $this->assertStringNotContainsString('earlier question\'s period', LindaPrompt::system());
    }
}
