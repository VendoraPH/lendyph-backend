<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaLoanNumbers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Which values of a question are checked against the deployment's imported
 * Loan Nos. (finish run round 6): each whitespace-separated token with the
 * punctuation around it trimmed (and as typed), plus runs of two or three
 * tokens for a Loan No. holding spaces. A value needs a digit. A bare number
 * of 1 to 4 digits counts only right after a loan word ("loan 42", "loan no.
 * 42", "utang 42"), so a count or a year ("top 10", "2026") is never taken
 * for a loan.
 */
class LindaLoanNumbersTest extends LindaTestCase
{
    /** @return array<string, array{string, list<string>}> */
    public static function holds(): array
    {
        return [
            'a code' => ['What is the status of L-2019-0042?', ['L-2019-0042']],
            'any case, in brackets' => ['Kumusta ang (l-2019-0042)', ['l-2019-0042']],
            'a short code' => ['How about D-1', ['D-1']],
            'a long bare number' => ['Status of 19042', ['19042']],
            'a bare number after loan' => ['Status of loan 42', ['42']],
            'after loan no.' => ['loan no. 42 please', ['42']],
            'after loan #' => ['loan #42', ['42', '#42']],
            'after utang' => ['Pila ang utang 7?', ['7']],
            'with spaces' => ['Status of BC 2019 07', ['BC 2019 07', '2019 07']],
            'a possessive' => ["What is BC 2019 07's status?", ['BC 2019 07']],
            'a curly possessive' => ['What is L-2019-0042’s balance?', ['L-2019-0042']],
            'slash-joined' => ['Compare L-1/L-2', ['L-1', 'L-2']],
            'comma and semicolon joined' => ['L-1,L-2;L-3 please', ['L-1', 'L-2', 'L-3']],
            'a Loan No. holding a slash, whole' => ['Status of L/2019/42', ['L/2019/42']],
        ];
    }

    /** @param  list<string>  $expected */
    #[DataProvider('holds')]
    public function test_the_values_checked_include_the_loan_no(string $question, array $expected): void
    {
        $candidates = LindaLoanNumbers::candidates($question);

        foreach ($expected as $value) {
            $this->assertContains($value, $candidates, $question);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function neverChecked(): array
    {
        return [
            'a count' => ['Top 10 officers this month', '10'],
            'a year' => ['Collections in 2026', '2026'],
            'a word' => ['Collections this month', 'Collections'],
            'a short number in a phrase' => ['How about the last 3 months', '3'],
            'the app\'s own loan number' => ['Status of LN-0042', 'LN-0042'],
            'the app\'s own number with a space' => ['Status of PN 42', 'PN 42'],
            'a receipt number' => ['Find RCP-000123', 'RCP-000123'],
        ];
    }

    #[DataProvider('neverChecked')]
    public function test_counts_years_words_and_the_apps_own_numbers_are_never_checked(string $question, string $value): void
    {
        $this->assertNotContains($value, LindaLoanNumbers::candidates($question), $question);
    }

    public function test_a_question_with_no_digit_checks_nothing(): void
    {
        $this->assertSame([], LindaLoanNumbers::candidates('How much did we collect this month?'));
    }

    public function test_no_value_is_longer_than_the_column(): void
    {
        $long = str_repeat('A', 49).'12';

        $this->assertNotContains($long, LindaLoanNumbers::candidates("Status of {$long}"));
    }
}
