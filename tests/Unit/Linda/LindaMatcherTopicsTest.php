<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Round 3's further topics in English, Tagalog/Taglish and Bisaya/Cebuano:
 * each routes to its own intent, with its period and branch.
 */
class LindaMatcherTopicsTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->atManila('2026-11-17 10:00');
    }

    /**
     * @return array<string, array{string, LindaIntent, ?string, ?string}>
     */
    public static function topics(): array
    {
        return [
            // Portfolio summary.
            'en portfolio' => ['How big is our loan portfolio?', LindaIntent::PortfolioSummary, null, null],
            'en portfolio summary' => ['Show the portfolio summary', LindaIntent::PortfolioSummary, null, null],
            'en PAR 30' => ['What is our PAR 30?', LindaIntent::PortfolioSummary, null, null],
            'en portfolio at risk in Main' => ['What is the portfolio at risk in Main branch?', LindaIntent::PortfolioSummary, null, 'main'],
            'en principal balance' => ['What is our total principal balance?', LindaIntent::PortfolioSummary, null, null],
            'tl PAR natin' => ['Pakita ang PAR natin', LindaIntent::PortfolioSummary, null, null],
            'tl portfolio sa Main' => ['Magkano ang portfolio natin sa Main?', LindaIntent::PortfolioSummary, null, 'main'],
            'ceb PAR nato' => ['Pila ang PAR nato?', LindaIntent::PortfolioSummary, null, null],
            'ceb portfolio nato' => ['Pila ang portfolio nato karon?', LindaIntent::PortfolioSummary, null, null],
        ];
    }

    #[DataProvider('topics')]
    public function test_topic_questions_route_to_their_intent(string $question, LindaIntent $intent, ?string $period, ?string $branch): void
    {
        $match = LindaMatcher::match($question);

        $this->assertNotNull($match, $question);
        $this->assertSame($intent, $match->intent, $question);
        $this->assertSame($period, $match->period?->key, $question);
        $this->assertSame($branch, $match->branchName, $question);
    }
}
