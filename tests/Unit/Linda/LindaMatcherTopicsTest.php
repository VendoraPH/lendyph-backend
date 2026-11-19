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
            // Income and expenses.
            'en income this month' => ['How much income did we earn this month?', LindaIntent::IncomeSummary, 'this_month', null],
            'en interest income last month' => ['What was our interest income last month?', LindaIntent::IncomeSummary, 'last_month', null],
            'en revenue in Main' => ['revenue in Main branch this year', LindaIntent::IncomeSummary, 'this_year', 'main'],
            'en income, no period' => ['Show our income', LindaIntent::IncomeSummary, 'this_month', null],
            'en expenses' => ['What are our total expenses this month?', LindaIntent::ExpenseSummary, 'this_month', null],
            'en spend' => ['How much did we spend last month?', LindaIntent::ExpenseSummary, 'last_month', null],
            'tl kinita' => ['Magkano ang kinita natin noong isang buwan?', LindaIntent::IncomeSummary, 'last_month', null],
            'tl gastos' => ['Magkano ang gastos natin ngayong buwan?', LindaIntent::ExpenseSummary, 'this_month', null],
            'ceb ganansya' => ['Pila ang ganansya nato niaging bulan?', LindaIntent::IncomeSummary, 'last_month', null],
            'ceb gigasto sa Main' => ['Pila ang gigasto karong bulana sa Main?', LindaIntent::ExpenseSummary, 'this_month', 'main'],
            // Share capital.
            'en share capital' => ['How much share capital do we have?', LindaIntent::ShareCapitalSummary, null, null],
            'en share capital in Main' => ['share capital in Main branch', LindaIntent::ShareCapitalSummary, null, 'main'],
            'en capital build-up' => ['Total capital build-up', LindaIntent::ShareCapitalSummary, null, null],
            'en CBU now' => ['What is our CBU now?', LindaIntent::ShareCapitalSummary, null, null],
            'tl share capital' => ['Magkano ang share capital natin?', LindaIntent::ShareCapitalSummary, null, null],
            'tl ilan ang may share capital' => ['Ilan ang may share capital?', LindaIntent::ShareCapitalSummary, null, null],
            'ceb kapital' => ['Pila ang kapital sa mga miyembro?', LindaIntent::ShareCapitalSummary, null, null],
            'ceb share capital sa Main' => ['Pila ang share capital nato sa Main?', LindaIntent::ShareCapitalSummary, null, 'main'],
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
