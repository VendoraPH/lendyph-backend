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
            'no digit, after loan' => ['Status of loan ABC-XYZ', ['ABC-XYZ']],
            'no digit, after loan no.' => ['loan no. ABCD please', ['ABCD']],
            'no digit, after loan #' => ['loan # ABC-XYZ', ['ABC-XYZ']],
            'glued to loan no.' => ['Loan No.BC-07 status', ['BC-07']],
            'glued to loan #' => ['Status of loan#ABC-XYZ?', ['ABC-XYZ']],
            'glued to loan no:' => ['Loan No:BC-07', ['BC-07']],
            'glued, any case' => ['LOAN NO.bc-07', ['bc-07']],
            'glued bare number' => ['loan no.42', ['42']],
            'glued to utang' => ['utang#XY-Z', ['XY-Z']],
            'glued, with spaces, no digit before the digits' => ['Loan No.BC 2019 07', ['BC 2019 07', 'BC 2019']],
            'with spaces, no digit, after loan' => ['Status of loan ABC XYZ', ['ABC XYZ']],
            'three words, no digit, glued' => ['loan#AB CD EF please', ['AB CD EF']],
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
            'no digit, no loan word' => ['Status of ABC-XYZ', 'ABC-XYZ'],
            'no digit, glued to another word' => ['Status#ABC-XYZ', 'ABC-XYZ'],
            'a loan word itself' => ['loan no. ABCD', 'no'],
            'the app\'s own number glued to loan' => ['loan#LN-0042', 'LN-0042'],
            'words with no digit, no loan word' => ['Status of ABC XYZ', 'ABC XYZ'],
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

    /** A value with no digit is checked only after a loan word (round 7). */
    public function test_without_a_loan_word_nothing_new_is_checked(): void
    {
        $this->assertSame([], LindaLoanNumbers::candidates('Status of ABC-XYZ and XY-Z today'));
    }

    public function test_no_value_is_longer_than_the_column(): void
    {
        $long = str_repeat('A', 49).'12';

        $this->assertNotContains($long, LindaLoanNumbers::candidates("Status of {$long}"));
    }
}
