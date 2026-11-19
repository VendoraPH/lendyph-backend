<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Round 3: member questions (counts, active, new and repeat members) in
 * English, Tagalog/Taglish and Bisaya/Cebuano route to `borrower_summary`,
 * while questions that only mention members beside another subject keep it.
 */
class LindaMatcherMembersTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->atManila('2026-11-17 10:00');
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function memberQuestions(): array
    {
        return [
            'en how many members' => ['How many members do we have?', null, null],
            'en active members' => ['How many active members do we have?', null, null],
            'en active members in a branch' => ['How many active members in Main branch?', null, 'main'],
            'en inactive members' => ['How many inactive members are there?', null, null],
            'en blacklisted members' => ['How many blacklisted members do we have?', null, null],
            'en new members this month' => ['How many new members this month?', 'this_month', null],
            'en new members last month' => ['new members last month', 'last_month', null],
            'en repeat borrowers' => ['How many repeat borrowers do we have?', null, null],
            'en repeat members this year' => ['repeat members this year', 'this_year', null],
            'en total members' => ['Total number of members', null, null],
            'tl ilan ang miyembro' => ['Ilan ang miyembro natin?', null, null],
            'tl ilang active na miyembro' => ['Ilang active na miyembro natin?', null, null],
            'tl aktibong miyembro sa Main' => ['Ilan ang aktibong miyembro sa Main?', null, 'main'],
            'tl bagong miyembro' => ['Ilan ang bagong miyembro ngayong buwan?', 'this_month', null],
            'tl bagong members' => ['Ilan ang bagong members ngayong taon?', 'this_year', null],
            'tl members natin' => ['Ilan ang members natin?', null, null],
            'ceb pila ka miyembro' => ['Pila ka miyembro nato?', null, null],
            'ceb aktibo nga miyembro' => ['Pila ka aktibo nga miyembro nato?', null, null],
            'ceb bag-ong miyembro' => ['Pila ka bag-ong miyembro karong bulana?', 'this_month', null],
            'ceb bag-ong members' => ['Pila ka bag-ong members karong tuiga?', 'this_year', null],
            'ceb pila ka members sa Main' => ['Pila ka members sa Main nga branch?', null, 'main'],
        ];
    }

    #[DataProvider('memberQuestions')]
    public function test_member_questions_route_to_the_member_intent(string $question, ?string $period, ?string $branch): void
    {
        $match = LindaMatcher::match($question);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::BorrowerSummary, $match->intent, $question);
        $this->assertSame($period, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
        $this->assertFalse($match->followUp);
    }

    /**
     * @return array<string, array{string, LindaIntent}>
     */
    public static function otherSubjects(): array
    {
        return [
            'the suggested overdue question' => ['Which members currently have overdue payments?', LindaIntent::OverdueAccounts],
            'find overdue members' => ['Find overdue members', LindaIntent::OverdueAccounts],
            'naatraso' => ['Pila ka members ang naatraso?', LindaIntent::OverdueAccounts],
            'collections from members' => ['How much did we collect from members this month?', LindaIntent::CollectionSummary],
            'active loans' => ['How many active loans do we have?', LindaIntent::LoanSummary],
            'aktibo nga loans' => ['Pila ang aktibo nga loans?', LindaIntent::LoanSummary],
        ];
    }

    #[DataProvider('otherSubjects')]
    public function test_a_member_word_beside_another_subject_keeps_that_subject(string $question, LindaIntent $intent): void
    {
        $this->assertSame($intent, LindaMatcher::match($question)?->intent, $question);
    }

    public function test_a_loan_count_about_members_is_not_a_member_count(): void
    {
        $this->assertNotSame(LindaIntent::BorrowerSummary, LindaMatcher::match('How many loans do our members have?')?->intent);
    }

    public function test_a_follow_up_carries_the_member_topic(): void
    {
        $match = LindaMatcher::match('how about last month?', LindaIntent::BorrowerSummary);

        $this->assertSame(LindaIntent::BorrowerSummary, $match?->intent);
        $this->assertSame('last_month', $match->period?->key);
        $this->assertTrue($match->followUp);

        $branch = LindaMatcher::match('sa Main naman?', LindaIntent::BorrowerSummary);
        $this->assertSame(LindaIntent::BorrowerSummary, $branch?->intent);
        $this->assertSame('main', $branch->branchName);
    }

    public function test_a_named_member_still_goes_to_the_lookup(): void
    {
        $this->assertNull(LindaMatcher::match('Find member Juan Dela Cruz'));
        $this->assertNull(LindaMatcher::match('members named Juan'));
    }
}
