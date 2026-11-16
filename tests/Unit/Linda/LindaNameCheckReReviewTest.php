<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaNameCheck;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The full-name check after the re-review: middle initials and stored
 * middle names between the names, accents folded both ways, invisible
 * characters ignored, co-makers and walk-ins covered, a capped candidate
 * read kept local, and a failure that fails closed.
 */
class LindaNameCheckReReviewTest extends LindaTestCase
{
    /** @var list<array<string, mixed>> */
    private const PEOPLE = [
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Juan', 'middle_name' => 'Tan', 'last_name' => 'Dela Cruz'],
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Rosa', 'middle_name' => null, 'last_name' => 'Peña'],
        ['kind' => LindaNameCheck::MEMBER, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Pena'],
        ['kind' => LindaNameCheck::CO_MAKER, 'first_name' => 'Lito', 'middle_name' => null, 'last_name' => 'Lapid'],
        ['kind' => LindaNameCheck::WALK_IN, 'full_name' => 'Carmen Soriano'],
    ];

    /** @var list<array{list<string>, list<string>}> */
    private array $asked = [];

    /** @return array<string, array{string, string, string}> */
    public static function hits(): array
    {
        return [
            'a middle initial with a dot' => ['Is Juan A. Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a middle initial without a dot' => ['Is Juan A Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a stop word as the stored middle name' => ['Is Juan Tan Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'typed without the accent' => ['Is Rosa Pena late?', LindaNameCheck::MEMBER, 'Rosa Pena'],
            'typed with the accent' => ['Is Ana Peña late?', LindaNameCheck::MEMBER, 'Ana Peña'],
            'a zero-width space inside a name' => ["Is Ju\u{200B}an Dela Cruz overdue?", LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a soft hyphen inside a name' => ["Is Juan Dela Cr\u{00AD}uz overdue?", LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a co-maker' => ['Is Lito Lapid a good co-maker?', LindaNameCheck::CO_MAKER, 'Lito Lapid'],
            'a walk-in' => ['Did Carmen Soriano cash in today?', LindaNameCheck::WALK_IN, 'Carmen Soriano'],
            'a walk-in, reversed' => ['Soriano, Carmen GCash', LindaNameCheck::WALK_IN, 'Carmen Soriano'],
        ];
    }

    #[DataProvider('hits')]
    public function test_a_real_name_is_found(string $question, string $kind, string $name): void
    {
        $hit = LindaNameCheck::find($question, $this->people(...));

        $this->assertInstanceOf(LindaNameCheck::class, $hit, $question);
        $this->assertSame($kind, $hit->kind, $question);
        $this->assertSame($name, $hit->name, $question);
    }

    public function test_a_stop_word_between_the_names_that_is_not_the_middle_name_is_no_hit(): void
    {
        $this->assertNull(LindaNameCheck::find('Is Juan or Dela Cruz overdue?', $this->people(...)));
    }

    public function test_candidates_are_asked_for_by_first_and_last_name_spans(): void
    {
        LindaNameCheck::find('Is Juan Dela Cruz overdue?', $this->people(...));

        $this->assertCount(1, $this->asked);
        [$lastNames, $firstNames] = $this->asked[0];
        $this->assertContains('dela cruz', $lastNames);
        $this->assertContains('juan', $firstNames);
        $this->assertContains('juan dela cruz', $firstNames);
    }

    /**
     * A read that hit its cap may have left the person out, so the question
     * stays local: a member lookup with no target, or no name for the others.
     */
    public function test_a_capped_read_keeps_the_question_local(): void
    {
        $member = LindaNameCheck::find('Is Juan Santos late?', fn (array $last, array $first): array => [['kind' => LindaNameCheck::MEMBER, 'capped' => true]]);
        $this->assertSame(LindaNameCheck::MEMBER, $member?->kind);
        $this->assertNull($member->name);

        $staff = LindaNameCheck::find('Is Juan Santos late?', fn (array $last, array $first): array => [['kind' => LindaNameCheck::STAFF, 'capped' => true]]);
        $this->assertSame(LindaNameCheck::STAFF, $staff?->kind);
        $this->assertNull($staff->name);

        $named = LindaNameCheck::find('Is Juan Dela Cruz late?', fn (array $last, array $first): array => [
            ['kind' => LindaNameCheck::MEMBER, 'capped' => true],
            ...self::PEOPLE,
        ]);
        $this->assertSame('Juan Dela Cruz', $named?->name);
    }

    public function test_a_regex_failure_fails_closed(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $hit = LindaNameCheck::find('How much interest did we earn this month?', fn (): array => []);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertSame(LindaNameCheck::MEMBER, $hit?->kind);
        $this->assertNull($hit->name);
    }

    public function test_invalid_utf8_fails_closed(): void
    {
        $hit = LindaNameCheck::find("Is Juan \xC3\x28 late?", fn (): array => []);

        $this->assertSame(LindaNameCheck::MEMBER, $hit?->kind);
        $this->assertNull($hit->name);
    }

    /**
     * @param  list<string>  $lastNames
     * @param  list<string>  $firstNames
     * @return list<array<string, mixed>>
     */
    private function people(array $lastNames, array $firstNames = []): array
    {
        $this->asked[] = [$lastNames, $firstNames];

        return self::PEOPLE;
    }
}
