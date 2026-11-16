<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Round 2 of the matcher: Bisaya/Cebuano beside English and Tagalog, "in" as
 * a branch marker, and the collection efficiency, branch performance and
 * officer performance subjects.
 */
class LindaMatcherRound2Test extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sunday, 00:30 in Manila: still November 7 in UTC.
        $this->atManila('2026-11-08 00:30');
    }

    /**
     * @return array<string, array{string, LindaIntent, ?string, ?string}>
     */
    public static function bisaya(): array
    {
        return [
            // The plan's examples.
            'nakolekta karong bulana' => ['Pila ang nakolekta karong bulana?', LindaIntent::CollectionSummary, 'this_month', null],
            'naay atraso' => ['Kinsa ang naay atraso?', LindaIntent::OverdueAccounts, null, null],
            'pila ka active loans' => ['Pila ka active loans nato?', LindaIntent::LoanSummary, null, null],
            'na-release niaging semana sa Main' => ['Pila ang na-release niaging semana sa Main?', LindaIntent::DisbursementSummary, 'last_week', 'main'],
            'gahapon, bayad' => ['Gahapon, pila ang bayad?', LindaIntent::CollectionSummary, 'yesterday', null],
            // Collections.
            'nakubra' => ['Pila ang nakubra gahapon?', LindaIntent::CollectionSummary, 'yesterday', null],
            'nadawat nga bayad' => ['Pila ang nadawat nga bayad karong semanaha?', LindaIntent::CollectionSummary, 'this_week', null],
            'koleksyon sa Riverside' => ['Unsa ang koleksyon sa Riverside karon?', LindaIntent::CollectionSummary, 'today', 'riverside'],
            'nakolekta, polite' => ['Palihug pakita ang nakolekta nato niaging bulan', LindaIntent::CollectionSummary, 'last_month', null],
            // Overdue.
            'naatraso' => ['Pila ka members ang naatraso?', LindaIntent::OverdueAccounts, null, null],
            'nalangan sa North' => ['Kinsa ang nalangan sa North?', LindaIntent::OverdueAccounts, null, 'north'],
            'lapas na sa due' => ['Kinsa ang lapas na sa due?', LindaIntent::OverdueAccounts, null, null],
            'wala pa nakabayad' => ['Kinsa ang wala pa nakabayad?', LindaIntent::OverdueAccounts, null, null],
            'dili pa bayad sa Main' => ['Pila ang dili pa bayad sa Main?', LindaIntent::OverdueAccounts, null, 'main'],
            // Active loans.
            'aktibo nga loans' => ['Pila ang aktibo nga loans?', LindaIntent::LoanSummary, null, null],
            'nagdagan nga loan' => ['Pila ka nagdagan nga loan sa Main?', LindaIntent::LoanSummary, null, 'main'],
            'pila ka loan' => ['Pila ka loan nato karon?', LindaIntent::LoanSummary, null, null],
            // Releases.
            'gi-release' => ['Pila ang gi-release karong bulana?', LindaIntent::DisbursementSummary, 'this_month', null],
            'gipagawas' => ['Pila ang gipagawas niaging bulan?', LindaIntent::DisbursementSummary, 'last_month', null],
            'nahatag nga loan' => ['Pila ang nahatag nga loan gahapon?', LindaIntent::DisbursementSummary, 'yesterday', null],
            'pila ka loan, with a release' => ['Pila ka loan ang na-release karong semanaha?', LindaIntent::DisbursementSummary, 'this_week', null],
            // Summary.
            'kinatibuk-an' => ['Ipakita ang kinatibuk-an karong tuiga', LindaIntent::GeneralLendingSummary, 'this_year', null],
            'summary, Bisaya' => ['Unsa ang summary nato karong bulana bai?', LindaIntent::GeneralLendingSummary, 'this_month', null],
            // A branch named with "nga branch".
            'sa Main nga branch' => ['Pila ang nakolekta sa Main nga branch?', LindaIntent::CollectionSummary, 'this_month', 'main'],
            'tanan nga branch' => ['Pila ang nakolekta sa tanan nga branch?', LindaIntent::CollectionSummary, 'this_month', null],
            // A range.
            'gikan hangtod' => ['Pila ang nakolekta gikan sa Oktubre 1 hangtod 10?', LindaIntent::CollectionSummary, null, null],
        ];
    }

    #[DataProvider('bisaya')]
    public function test_bisaya(string $question, LindaIntent $intent, ?string $periodKey, ?string $branch): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match, $question);
        $this->assertSame($intent, $match->intent, $question);
        $this->assertSame($periodKey, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
        $this->assertFalse($match->followUp);
    }

    public function test_a_bisaya_range_carries_its_dates(): void
    {
        $this->assertSame(
            ['from' => '2026-10-01', 'to' => '2026-10-10'],
            array_slice(LindaMatcher::match('Pila ang nakolekta gikan sa Oktubre 1 hangtod 10?', null)?->period?->toArray() ?? [], 0, 2),
        );
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function bisayaFollowUps(): array
    {
        return [
            'unya' => ['Unya, niaging bulan?', 'last_month', null],
            'kumusta sa' => ['kumusta sa Main?', null, 'main'],
            'kumusta ang' => ['Kumusta ang gahapon?', 'yesterday', null],
            'ug ang' => ['Ug ang niaging semana?', 'last_week', null],
            'unsa kaha' => ['Unsa kaha sa North karong semanaha?', 'this_week', 'north'],
            'unsa man' => ['unsa man karon?', 'today', null],
            'pero ang' => ['Pero ang sa Riverside?', null, 'riverside'],
            'a period on its own' => ['Gahapon?', 'yesterday', null],
        ];
    }

    #[DataProvider('bisayaFollowUps')]
    public function test_bisaya_follow_ups_reuse_the_previous_intent(string $question, ?string $periodKey, ?string $branch): void
    {
        $match = LindaMatcher::match($question, LindaIntent::CollectionSummary);

        $this->assertNotNull($match, $question);
        $this->assertSame(LindaIntent::CollectionSummary, $match->intent);
        $this->assertSame($periodKey, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
        $this->assertTrue($match->followUp, $question);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function inBranches(): array
    {
        return [
            'collections in Main' => ['collections in Main', 'main'],
            'in a two-word branch, with a period' => ['How much did we release in San Jose last week?', 'san jose'],
            'in before a period' => ['collections in October', null],
            'in total is not a branch' => ['collections in total', null],
        ];
    }

    #[DataProvider('inBranches')]
    public function test_in_is_a_branch_marker_like_sa(string $question, ?string $branch): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match, $question);
        $this->assertSame($branch, $match->branchName, $question);
    }

    public function test_in_as_a_follow_up(): void
    {
        $match = LindaMatcher::match('how about in Riverside?', LindaIntent::OverdueAccounts);

        $this->assertSame(LindaIntent::OverdueAccounts, $match?->intent);
        $this->assertSame('riverside', $match->branchName);
        $this->assertTrue($match->followUp);
    }

    /**
     * @return array<string, array{string, LindaIntent, ?string, ?string}>
     */
    public static function newIntents(): array
    {
        return [
            // Collection efficiency.
            'collection efficiency' => ['What is our collection efficiency this month?', LindaIntent::CollectionEfficiency, 'this_month', null],
            'collection rate' => ['collection rate last week', LindaIntent::CollectionEfficiency, 'last_week', null],
            'efficiency in a branch' => ['efficiency in Main branch', LindaIntent::CollectionEfficiency, null, 'main'],
            'percent of dues collected' => ['What percent of dues did we collect last month?', LindaIntent::CollectionEfficiency, 'last_month', null],
            'porsyento, Tagalog' => ['Ilang porsyento ang nakolekta natin last week?', LindaIntent::CollectionEfficiency, 'last_week', null],
            'porsyento sa koleksyon, Bisaya' => ['Pila ang porsyento sa koleksyon karong bulana?', LindaIntent::CollectionEfficiency, 'this_month', null],
            // Branch performance.
            'which branch collected the most' => ['Which branch collected the most this month?', LindaIntent::BranchPerformance, 'this_month', null],
            'compare the branches' => ['Compare the branches by collections', LindaIntent::BranchPerformance, null, null],
            'branch performance' => ['branch performance last month', LindaIntent::BranchPerformance, 'last_month', null],
            'collections by branch' => ['collections by branch this week', LindaIntent::BranchPerformance, 'this_week', null],
            'best branch' => ['best branch this year', LindaIntent::BranchPerformance, 'this_year', null],
            'anong branch, Tagalog' => ['Anong branch ang may pinakamalaking koleksyon?', LindaIntent::BranchPerformance, null, null],
            'ikumpara, Tagalog' => ['Ikumpara ang mga branch ngayong buwan', LindaIntent::BranchPerformance, 'this_month', null],
            'unsang branch pinakadaghan, Bisaya' => ['Unsang branch ang pinakadaghan og nakolekta sa niaging semana?', LindaIntent::BranchPerformance, 'last_week', null],
            'itandi ang mga branch, Bisaya' => ['Itandi ang mga branch karong bulana', LindaIntent::BranchPerformance, 'this_month', null],
            'asa nga branch atraso, Bisaya' => ['Asa nga branch ang daghang atraso?', LindaIntent::BranchPerformance, null, null],
            'compare all branches' => ['compare all branches', LindaIntent::BranchPerformance, null, null],
            // Officer performance.
            'top account officers' => ['Who are the top account officers this month?', LindaIntent::OfficerPerformance, 'this_month', null],
            'each collector' => ['How is each collector doing?', LindaIntent::OfficerPerformance, null, null],
            'officer released the most' => ['Which loan officer released the most this month?', LindaIntent::OfficerPerformance, 'this_month', null],
            'AO performance' => ['AO performance last month', LindaIntent::OfficerPerformance, 'last_month', null],
            'pinakamagaling na collector, Tagalog' => ['Sino ang pinakamagaling na collector ngayong buwan?', LindaIntent::OfficerPerformance, 'this_month', null],
            'kinsa nga officer, Bisaya' => ['Kinsa nga officer ang pinakadaghan og nakolekta karong bulana?', LindaIntent::OfficerPerformance, 'this_month', null],
            'pinakamaayo nga officer, Bisaya' => ['Kinsa ang pinakamaayo nga officer sa Main?', LindaIntent::OfficerPerformance, null, 'main'],
        ];
    }

    #[DataProvider('newIntents')]
    public function test_efficiency_and_performance_in_three_languages(string $question, LindaIntent $intent, ?string $periodKey, ?string $branch): void
    {
        $match = LindaMatcher::match($question, null);

        $this->assertNotNull($match, $question);
        $this->assertSame($intent, $match->intent, $question);
        $this->assertSame($periodKey, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function leftToTheModel(): array
    {
        return [
            'a person marker' => ['Unsa ang utang ni Juan Dela Cruz?'],
            'kang' => ['Pila ang bayad kang Pedro?'],
            'si' => ['Nakabayad na ba si Maria?'],
            'kay, Tagalog' => ['Magkano ang bayad kay Ana?'],
            'nadawat with no money word' => ['Pila ang nadawat?'],
            'a ranking word with no branch or officer' => ['top collections this month'],
            'two subjects' => ['Pila ang nakolekta ug ang atraso?'],
            'branch and officer together' => ['compare officers by branch'],
            'a named branch\'s performance' => ['Main branch performance'],
            'in pesos names no branch, and pesos is unknown' => ['How much have we collected in pesos?'],
        ];
    }

    #[DataProvider('leftToTheModel')]
    public function test_what_the_matcher_leaves_alone(string $question): void
    {
        $this->assertNull(LindaMatcher::match($question, null), $question);
    }
}
