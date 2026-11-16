<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaNameCheck;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * D2: the full-name check run before any model call. A question that names
 * one of the deployment's members or staff by first and last name (either
 * order, any case, a middle name allowed) is answered locally; one word on
 * its own never is.
 */
class LindaNameCheckTest extends LindaTestCase
{
    /** @var list<array{kind: string, first_name: string, middle_name: ?string, last_name: string}> */
    private const PEOPLE = [
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Juan', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz'],
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Maria Clara', 'middle_name' => null, 'last_name' => 'Santos'],
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'May', 'middle_name' => null, 'last_name' => 'Lim'],
        ['kind' => LindaNameCheck::STAFF, 'first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Reyes'],
    ];

    /** @var list<list<string>> */
    private array $asked = [];

    /** @return array<string, array{string, string, string}> */
    public static function hits(): array
    {
        return [
            'first then last' => ['Is Juan Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'before a money word' => ['Juan Dela Cruz balance', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a follow-up' => ['how about Juan Dela Cruz?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'last, first' => ['Is Dela Cruz, Juan late?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'lower case' => ['is juan dela cruz late', LindaNameCheck::MEMBER, 'juan dela cruz'],
            'extra spaces' => ['is  Juan   Dela   Cruz late', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a middle name, kept as typed' => ['Is Juan Santos Dela Cruz late?', LindaNameCheck::MEMBER, 'Juan Santos Dela Cruz'],
            'the middle name\'s initial, kept as typed' => ['Is Juan S. Dela Cruz late?', LindaNameCheck::MEMBER, 'Juan S. Dela Cruz'],
            'another initial, left out' => ['Is Juan Q. Dela Cruz late?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a two-word first name' => ['Is Maria Clara Santos up to date?', LindaNameCheck::MEMBER, 'Maria Clara Santos'],
            'a two-word first name, reversed' => ['Santos, Maria Clara status', LindaNameCheck::MEMBER, 'Maria Clara Santos'],
            'staff' => ['Is Pedro Reyes around today?', LindaNameCheck::STAFF, 'Pedro Reyes'],
        ];
    }

    #[DataProvider('hits')]
    public function test_a_full_name_is_found(string $question, string $kind, string $name): void
    {
        $hit = LindaNameCheck::find($question, $this->people(...));

        $this->assertInstanceOf(LindaNameCheck::class, $hit, $question);
        $this->assertSame($kind, $hit->kind, $question);
        $this->assertSame($name, $hit->name, $question);
    }

    /** @return array<string, array{string}> */
    public static function misses(): array
    {
        return [
            'a first name alone' => ['Is Juan overdue?'],
            'a last name alone' => ['Cruz collections?'],
            'a common word that is a first name' => ['How much interest did we earn in May?'],
            'first and last too far apart' => ['May we see the Main branch figures, then Lim?'],
            'nobody' => ['How much have we collected this month?'],
            'an empty question' => ['   '],
        ];
    }

    #[DataProvider('misses')]
    public function test_no_full_name_is_no_hit(string $question): void
    {
        $this->assertNull(LindaNameCheck::find($question, $this->people(...)), $question);
    }

    public function test_officer_and_ranking_words_mark_a_performance_question(): void
    {
        $this->assertTrue(LindaNameCheck::find('How is Pedro Reyes performing this month?', $this->people(...))?->performance);
        $this->assertTrue(LindaNameCheck::find('Is Pedro Reyes the top collector?', $this->people(...))?->performance);
        $this->assertFalse(LindaNameCheck::find('Is Pedro Reyes around?', $this->people(...))?->performance);
    }

    public function test_a_member_wins_over_staff_unless_the_question_asks_about_performance(): void
    {
        $people = fn (array $lastNames): array => [
            ['kind' => LindaNameCheck::STAFF, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz'],
            ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz'],
        ];

        $this->assertSame(LindaNameCheck::MEMBER, LindaNameCheck::find('Is Ana Cruz late?', $people)?->kind);
        $this->assertSame(LindaNameCheck::STAFF, LindaNameCheck::find('How is Ana Cruz performing?', $people)?->kind);
    }

    public function test_candidates_are_asked_for_by_one_to_three_word_spans_once(): void
    {
        LindaNameCheck::find('Is Juan Dela Cruz overdue?', $this->people(...));

        $this->assertCount(1, $this->asked);
        $spans = $this->asked[0];
        $this->assertContains('dela cruz', $spans);
        $this->assertContains('juan dela cruz', $spans);
        $this->assertContains('cruz', $spans);
        $this->assertNotContains('juan dela cruz overdue', $spans);
        // No read leaves out common words: first and last name together decide.
        $this->assertContains('overdue', $spans);
        $this->assertContains('is', $spans);
    }

    public function test_a_question_of_common_words_names_no_one_without_a_stored_match(): void
    {
        $this->assertNull(LindaNameCheck::find('how much is it?', $this->people(...)));
        $this->assertNull(LindaNameCheck::find('collections overdue performance', $this->people(...)));
        $this->assertCount(2, $this->asked);
    }

    /**
     * @param  list<string>  $lastNames
     * @return list<array{kind: string, first_name: string, middle_name: ?string, last_name: string}>
     */
    private function people(array $lastNames): array
    {
        $this->asked[] = $lastNames;

        return array_values(array_filter(self::PEOPLE, fn (array $person) => in_array(mb_strtolower($person['last_name']), $lastNames, true)));
    }
}
