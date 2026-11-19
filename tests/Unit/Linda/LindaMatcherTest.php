<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatch;
use App\Services\Linda\LindaMatcher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The deterministic matcher: it answers only when it understands every word,
 * and leaves anything else to the model or the help text.
 */
class LindaMatcherTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sunday, 00:30 in Manila: still November 7 in UTC.
        $this->atManila('2026-11-08 00:30');
    }

    /**
     * The five suggested questions, word for word from lendyph-frontend
     * src/lib/linda.ts (LINDA_SUGGESTED_QUESTIONS).
     *
     * @return array<string, array{string, LindaIntent, ?string}>
     */
    public static function suggestedQuestions(): array
    {
        return [
            'collected' => ['How much have we collected this month?', LindaIntent::CollectionSummary, 'this_month'],
            'overdue' => ['Which members currently have overdue payments?', LindaIntent::OverdueAccounts, null],
            'active loans' => ['How many active loans do we have?', LindaIntent::LoanSummary, null],
            'disbursed' => ['How much have we disbursed this month?', LindaIntent::DisbursementSummary, 'this_month'],
            'summary' => ['Give me a summary of our lending operation this month.', LindaIntent::GeneralLendingSummary, 'this_month'],
        ];
    }

    #[DataProvider('suggestedQuestions')]
    public function test_the_five_suggested_questions(string $question, LindaIntent $intent, ?string $periodKey): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match);
        $this->assertSame($intent, $match->intent);
        $this->assertSame($periodKey, $match->period?->key);
        $this->assertNull($match->branchName);
        $this->assertFalse($match->followUp);
    }

    public function test_this_month_reads_as_manila_dates(): void
    {
        $match = LindaMatcher::match('How much have we collected this month?', null);

        $this->assertSame([
            'intent' => 'collection_summary',
            'period' => ['from' => '2026-11-01', 'to' => '2026-11-08', 'label' => 'November 1 to 8, 2026', 'key' => 'this_month'],
            'branch_name' => null,
            'follow_up' => false,
        ], $match?->toArray());
    }

    /**
     * @return array<string, array{string, LindaIntent, ?string}>
     */
    public static function variants(): array
    {
        return [
            'collect, past tense' => ['How much did we collect last month?', LindaIntent::CollectionSummary, 'last_month'],
            'collections, no period' => ['Total collections', LindaIntent::CollectionSummary, 'this_month'],
            'collections today' => ['collections today', LindaIntent::CollectionSummary, 'today'],
            'collections for a month' => ['What were our collections for October?', LindaIntent::CollectionSummary, null],
            'collection summary' => ['Collection summary this week', LindaIntent::CollectionSummary, 'this_week'],
            'overdue members' => ['Show me the overdue members', LindaIntent::OverdueAccounts, null],
            'past due' => ['Who is past due?', LindaIntent::OverdueAccounts, null],
            'past-due with a hyphen' => ['list past-due accounts', LindaIntent::OverdueAccounts, null],
            'delayed' => ['Which accounts are delayed?', LindaIntent::OverdueAccounts, null],
            'outstanding' => ['How many outstanding loans?', LindaIntent::LoanSummary, null],
            'active loans now' => ['active loans right now', LindaIntent::LoanSummary, null],
            'disbursed yesterday' => ['How much was disbursed yesterday?', LindaIntent::DisbursementSummary, 'yesterday'],
            'released' => ['How much have we released this week?', LindaIntent::DisbursementSummary, 'this_week'],
            'disbursement, no period' => ['Disbursements please', LindaIntent::DisbursementSummary, 'this_month'],
            'overview' => ['Overview', LindaIntent::GeneralLendingSummary, 'this_month'],
            'summary this year' => ['Give me a summary for this year', LindaIntent::GeneralLendingSummary, 'this_year'],
            'summary for a range' => ['summary from November 1 to 5', LindaIntent::GeneralLendingSummary, null],
        ];
    }

    #[DataProvider('variants')]
    public function test_variants(string $question, LindaIntent $intent, ?string $periodKey): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match, $question);
        $this->assertSame($intent, $match->intent);
        $this->assertSame($periodKey, $match->period?->key);
        $this->assertFalse($match->followUp);
    }

    public function test_a_named_month_and_a_range_carry_their_dates(): void
    {
        $this->assertSame(
            ['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'October 1 to 31, 2026', 'key' => null],
            LindaMatcher::match('What were our collections for October?', null)?->period?->toArray(),
        );
        $this->assertSame(
            ['from' => '2026-11-01', 'to' => '2026-11-05', 'label' => 'November 1 to 5, 2026', 'key' => null],
            LindaMatcher::match('summary from November 1 to 5', null)?->period?->toArray(),
        );
    }

    /**
     * @return array<string, array{string, LindaIntent, ?string, ?string}>
     */
    public static function taglish(): array
    {
        return [
            'nakolekta ngayong buwan' => ['Magkano ang nakolekta natin ngayong buwan?', LindaIntent::CollectionSummary, 'this_month', null],
            'koleksyon kahapon' => ['Magkano ang koleksyon kahapon?', LindaIntent::CollectionSummary, 'yesterday', null],
            'collected, Taglish' => ['Magkano na ang na-collect natin this month po?', LindaIntent::CollectionSummary, 'this_month', null],
            'atraso' => ['Sino ang may atraso?', LindaIntent::OverdueAccounts, null, null],
            'overdue, Taglish' => ['Sinong mga members ang overdue ngayon?', LindaIntent::OverdueAccounts, null, null],
            'active loans, Taglish' => ['Ilan ang active loans natin?', LindaIntent::LoanSummary, null, null],
            'nailabas' => ['Magkano ang nailabas ngayong linggo?', LindaIntent::DisbursementSummary, 'this_week', null],
            'buod' => ['Pakibigay ang buod ng nakaraang buwan', LindaIntent::GeneralLendingSummary, 'last_month', null],
            'sa a branch' => ['Magkano ang nakolekta sa Cabadbaran?', LindaIntent::CollectionSummary, 'this_month', 'cabadbaran'],
            'sa a branch, with a period after' => ['Ilan ang atraso sa San Jose ngayong buwan?', LindaIntent::OverdueAccounts, 'this_month', 'san jose'],
        ];
    }

    #[DataProvider('taglish')]
    public function test_taglish(string $question, LindaIntent $intent, ?string $periodKey, ?string $branch): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match, $question);
        $this->assertSame($intent, $match->intent);
        $this->assertSame($periodKey, $match->period?->key);
        $this->assertSame($branch, $match->branchName);
    }

    public function test_a_branch_phrase_is_returned_as_text(): void
    {
        $match = LindaMatcher::match('How much have we collected in Main branch this month?', null);

        $this->assertSame(LindaIntent::CollectionSummary, $match?->intent);
        $this->assertSame('main', $match->branchName);
        $this->assertSame('this_month', $match->period?->key);

        $this->assertSame('san jose', LindaMatcher::match('overdue at the San Jose branch', null)?->branchName);
        $this->assertSame('main', LindaMatcher::match('Main branch disbursements last week', null)?->branchName);
    }

    /**
     * Each of the five intents the matcher answers, and one the model chose.
     *
     * @return array<string, array{LindaIntent}>
     */
    public static function previousIntents(): array
    {
        return [
            'collection' => [LindaIntent::CollectionSummary],
            'overdue' => [LindaIntent::OverdueAccounts],
            'active loans' => [LindaIntent::LoanSummary],
            'disbursement' => [LindaIntent::DisbursementSummary],
            'summary' => [LindaIntent::GeneralLendingSummary],
            'an intent the model chose' => [LindaIntent::IncomeSummary],
        ];
    }

    #[DataProvider('previousIntents')]
    public function test_how_about_last_month_after_each_intent(LindaIntent $previous): void
    {
        $match = LindaMatcher::match('how about last month?', $previous);

        $this->assertSame([
            'intent' => $previous->value,
            'period' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'October 1 to 31, 2026', 'key' => 'last_month'],
            'branch_name' => null,
            'follow_up' => true,
        ], $match?->toArray());
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function followUps(): array
    {
        return [
            'what about' => ['What about yesterday?', 'yesterday', null],
            'eh' => ['eh kahapon?', 'yesterday', null],
            'paano naman' => ['Paano naman ngayong linggo?', 'this_week', null],
            'a period on its own' => ['Last week?', 'last_week', null],
            'a range on its own' => ['November 1 to 5', null, null],
            'a branch on its own' => ['how about sa Cabadbaran?', null, 'cabadbaran'],
            'a branch and a period' => ['what about in Main branch last month', 'last_month', 'main'],
        ];
    }

    #[DataProvider('followUps')]
    public function test_follow_ups_reuse_the_previous_intent(string $question, ?string $periodKey, ?string $branch): void
    {
        $match = LindaMatcher::match($question, LindaIntent::DisbursementSummary);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::DisbursementSummary, $match->intent);
        $this->assertSame($periodKey, $match->period?->key);
        $this->assertSame($branch, $match->branchName);
        $this->assertTrue($match->followUp);
    }

    public function test_a_follow_up_without_its_own_period_leaves_the_period_to_the_caller(): void
    {
        // No default here: the previous turn's period still applies.
        $this->assertNull(LindaMatcher::match('how about sa Cabadbaran?', LindaIntent::CollectionSummary)?->period);
    }

    public function test_a_follow_up_naming_a_new_subject_takes_that_subject(): void
    {
        $match = LindaMatcher::match('how about overdue?', LindaIntent::CollectionSummary);

        $this->assertSame(LindaIntent::OverdueAccounts, $match?->intent);
        $this->assertTrue($match->followUp);
    }

    public function test_a_new_question_after_another_is_not_a_follow_up(): void
    {
        $match = LindaMatcher::match('How much have we disbursed this month?', LindaIntent::CollectionSummary);

        $this->assertSame(LindaIntent::DisbursementSummary, $match?->intent);
        $this->assertFalse($match->followUp);
    }

    public function test_a_follow_up_needs_something_to_follow(): void
    {
        $this->assertNull(LindaMatcher::match('how about last month?', null));
        $this->assertNull(LindaMatcher::match('last month', null));
        $this->assertNull(LindaMatcher::match('how about last month?', LindaIntent::UnsupportedQuestion));
        $this->assertNull(LindaMatcher::match('how about?', LindaIntent::CollectionSummary));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unanswerable(): array
    {
        return [
            'an unknown word left over' => ['How much have we collected from GCash this month?'],
            'another unknown word' => ['How many active loans do we have in pesos?'],
            'interest earned is left to the model' => ['How much did we earn from interest this month?'],
            'no subject' => ['How many loans do we have?'],
            'off topic' => ['What is the weather today?'],
            'two subjects' => ['collections and overdue this month'],
            'an unknown word with a follow-up' => ['how about interest last month?'],
            'a period over 10 years' => ['collections from January 1, 2010 to November 8, 2026'],
            'a period that has not started' => ['collections December 1 to 5, 2026'],
            'empty' => ['   '],
        ];
    }

    #[DataProvider('unanswerable')]
    public function test_no_match_when_anything_is_left_over(string $question): void
    {
        $this->assertNull(LindaMatcher::match($question, LindaIntent::CollectionSummary));
    }

    public function test_a_match_is_a_plain_value(): void
    {
        $match = LindaMatcher::match('Sino ang may atraso?', null);

        $this->assertInstanceOf(LindaMatch::class, $match);
        $this->assertSame([
            'intent' => 'overdue_accounts',
            'period' => null,
            'branch_name' => null,
            'follow_up' => false,
        ], $match->toArray());
    }
}
