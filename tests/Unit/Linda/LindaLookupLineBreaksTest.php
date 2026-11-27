<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaLookup;
use App\Services\Linda\LindaLookupRequest;

/**
 * A line break in a question (Shift+Enter, or a pasted cell) is read both
 * ways, kept and as a space, and the question stays on the server when either
 * reading is a lookup (finish run round 6). Reading it as a space only ever
 * adds a lookup: "PN⏎42" is the app's own number, while "Juan⏎Man" stays a
 * name and a phone number split over lines stays a phone number.
 */
class LindaLookupLineBreaksTest extends LindaTestCase
{
    public function test_a_name_after_a_line_break_is_still_a_member_lookup(): void
    {
        $lookup = LindaLookup::parse("How about Juan\nMan?");

        $this->assertNotNull($lookup);
        $this->assertSame(LindaLookupRequest::MEMBER, $lookup->kind);
    }

    public function test_a_phone_number_split_over_lines_is_still_a_lookup(): void
    {
        $this->assertSame(LindaLookupRequest::MEMBER, LindaLookup::parse("12\n123 456 789")?->kind);
    }

    public function test_a_prefix_and_its_number_split_by_a_line_break_read_as_one_number(): void
    {
        foreach (["How about PN\n42?", "How about PN\r\n42?", "How about LN\r0042?"] as $question) {
            $lookup = LindaLookup::parse($question);

            $this->assertSame(LindaLookupRequest::LOAN, $lookup?->kind, json_encode($question));
            $this->assertNotNull($lookup->code, json_encode($question));
        }
    }

    public function test_a_reply_reads_both_ways_too(): void
    {
        $this->assertSame('LN-0042', LindaLookup::reply("LN\n0042", LindaLookupRequest::LOAN)?->code);
        $this->assertSame(LindaLookupRequest::MEMBER, LindaLookup::reply("Juan\nMan", LindaLookupRequest::MEMBER)?->kind);
    }

    public function test_a_question_with_no_lookup_either_way_is_still_none(): void
    {
        $this->assertNull(LindaLookup::parse("How much did we collect\nthis month?"));
    }
}
