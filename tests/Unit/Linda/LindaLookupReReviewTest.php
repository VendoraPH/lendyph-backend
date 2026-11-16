<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaLookup;
use App\Services\Linda\LindaLookupRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The lookup parser after the re-review: ordinary words beside a money word
 * are never names, a name there must be capitalised as typed, Bisaya "kay"
 * before a verb is "because", invisible characters are ignored, and a
 * month-year ("Nov-2026") is a period, never a record code.
 */
class LindaLookupReReviewTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-16 09:00');
    }

    /** @return array<string, array{string}> */
    public static function notLookups(): array
    {
        return [
            'salary loans' => ['How many salary loans are active?'],
            'kabuuang koleksyon' => ['Magkano ang kabuuang koleksyon ngayong buwan?'],
            'collection agents' => ['Who are the best collection agents this month?'],
            'delinquency rate' => ['What is our loan delinquency rate?'],
            'loan size' => ['average loan size'],
            'fully paid loans' => ['Fully paid loans this month'],
            'kay before a verb' => ['Pila ang bayad karong bulana kay gikinahanglan nako?'],
            'kay before another verb' => ['Ngano ubos ang koleksyon kay nagkadaghan ang atraso?'],
            'a lower-case word before a money word' => ['How many emergency loans?'],
            'business loans' => ['business loans released this week'],
            'loan volume' => ['loan volume this year'],
            'a capitalised first word before a money word' => ['Regular loans this month'],
            'a capitalised first word alone' => ['Pangkabuhayan loans this month'],
            'a month and a year' => ['collections Nov-2026'],
            'a full month and a year' => ['collections for October-2026'],
            'thanks' => ['thanks, nice loan report'],
        ];
    }

    #[DataProvider('notLookups')]
    public function test_ordinary_words_are_not_lookups(string $question): void
    {
        $this->assertNull(LindaLookup::parse($question), $question);
    }

    /** @return array<string, array{string, string}> */
    public static function stillLookups(): array
    {
        return [
            'a capitalised name at the start' => ['Juan Dela Cruz balance', 'Juan Dela Cruz'],
            'a capitalised name after the start' => ['show Rosa Santos utang', 'Rosa Santos'],
            'a name after the money word' => ['balance Juan Dela Cruz', 'Juan Dela Cruz'],
            'kay before a name' => ['Magkano ang balance kay Ana Lim?', 'Ana Lim'],
            'a zero-width space inside a name' => ["utang ni Ju\u{200B}an Dela Cruz", 'Juan Dela Cruz'],
            'a soft hyphen inside a name' => ["balance of Ma\u{00AD}ria Santos", 'Maria Santos'],
        ];
    }

    #[DataProvider('stillLookups')]
    public function test_real_names_are_still_lookups(string $question, string $name): void
    {
        $this->assertSame($name, LindaLookup::parse($question)?->name, $question);
    }

    public function test_a_dash_of_any_kind_joins_a_record_code(): void
    {
        $this->assertSame('LN-42', LindaLookup::parse("Status of LN\u{2E3A}42")?->code);
        $this->assertSame(LindaLookupRequest::LOAN, LindaLookup::parse("Status of LN\u{FE58}42")?->kind);
    }
}
