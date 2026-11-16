<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one cleanup every Linda question goes through before it is matched.
 *
 * No database and no application boot: this is string handling only.
 */
class LindaTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function questions(): array
    {
        return [
            'trims, lower-cases and collapses spaces' => ["  How MUCH   have we\tcollected ", 'how much have we collected'],
            'strips punctuation' => ['How much have we collected this month?!', 'how much have we collected this month'],
            'drops apostrophes inside words' => ["What's overdue? We've ‘got’ it", 'whats overdue weve got it'],
            'keeps the peso sign and digits' => ['Over ₱1,500.00 overdue', 'over ₱1500.00 overdue'],
            'a hyphen between words is a space' => ['past-due accounts', 'past due accounts'],
            'a dash beside a number reads as "to"' => ['Nov 1-5', 'nov 1 to 5'],
            'an en dash between dates reads as "to"' => ['Nov 1 – Nov 5', 'nov 1 to nov 5'],
            'an em dash between words is a space' => ['collections — this month', 'collections this month'],
            'keeps ISO dates whole' => ['From 2026-10-01 to 2026-10-15.', 'from 2026-10-01 to 2026-10-15'],
            'commas between a day and a year are dropped' => ['November 1 to 8, 2026', 'november 1 to 8 2026'],
            'keeps letters with marks' => ['Sa PARAÑAQUE branch', 'sa parañaque branch'],
            'an empty question stays empty' => ['  ?! ', ''],
        ];
    }

    #[DataProvider('questions')]
    public function test_clean(string $question, string $expected): void
    {
        $this->assertSame($expected, LindaText::clean($question));
    }

    public function test_words_splits_a_cleaned_question(): void
    {
        $this->assertSame(['how', 'about', 'last', 'month'], LindaText::words('how about last month'));
        $this->assertSame([], LindaText::words(''));
    }
}
