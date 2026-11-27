<?php

namespace Tests\Unit\Linda;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookup;
use App\Services\Linda\LindaLookupRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The lookup parser after the round 2 reviews: record numbers in every form
 * staff type them, phone numbers with any separators, whole names, payment
 * questions read as receipt lookups, more phrasings kept local, category
 * words never read as names, and a parser that fails closed.
 */
class LindaLookupReviewFixTest extends LindaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-16 09:00');
    }

    /**
     * M1 (code) and M2 (security): a known prefix takes any number of digits
     * after a dash, a hash or a space, Unicode dashes, non-breaking spaces and
     * fullwidth digits included.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function shortCodes(): array
    {
        return [
            'a short loan number' => ['Status of LN-42', LindaLookupRequest::LOAN, 'LN-42'],
            'a hash, lower case' => ['ln#42', LindaLookupRequest::LOAN, 'LN-42'],
            'a short receipt' => ['RCP-7', LindaLookupRequest::RECEIPT, 'RCP-7'],
            'a short member code first' => ['BRW-12 status', LindaLookupRequest::MEMBER, 'BRW-12'],
            'a space' => ['status of LN 42', LindaLookupRequest::LOAN, 'LN-42'],
            'a hash and spaces' => ['RCP # 7 please', LindaLookupRequest::RECEIPT, 'RCP-7'],
            'a compound code' => ['SC-RCP-7', LindaLookupRequest::RECEIPT, 'SC-RCP-7'],
            'a time deposit' => ['TD-5', LindaLookupRequest::RECEIPT, 'TD-5'],
            'a GCash code with a hash' => ['GC#12', LindaLookupRequest::RECEIPT, 'GC-12'],
            'an application' => ['la-3', LindaLookupRequest::LOAN, 'LA-3'],
            'an en dash' => ['Status of LN–42', LindaLookupRequest::LOAN, 'LN-42'],
            'an em dash' => ['Status of LN—42', LindaLookupRequest::LOAN, 'LN-42'],
            'a hyphen' => ["Status of LN\u{2010}42", LindaLookupRequest::LOAN, 'LN-42'],
            'a non-breaking hyphen' => ["Status of LN\u{2011}000042", LindaLookupRequest::LOAN, 'LN-000042'],
            'a figure dash' => ["Status of LN\u{2012}42", LindaLookupRequest::LOAN, 'LN-42'],
            'a minus sign' => ['Status of LN−42', LindaLookupRequest::LOAN, 'LN-42'],
            'a fullwidth hyphen' => ['Status of LN－42', LindaLookupRequest::LOAN, 'LN-42'],
            'a non-breaking space' => ["Status of LN\u{00A0}42", LindaLookupRequest::LOAN, 'LN-42'],
            'fullwidth digits' => ['Status of LN-４２', LindaLookupRequest::LOAN, 'LN-42'],
            'fullwidth letters and digits' => ['ＲＣＰ－７', LindaLookupRequest::RECEIPT, 'RCP-7'],
            'no separator, three digits' => ['status of LN042', LindaLookupRequest::LOAN, 'LN-042'],
            'an unknown prefix with three digits' => ['what is XY-123', LindaLookupRequest::MEMBER, 'XY-123'],
        ];
    }

    #[DataProvider('shortCodes')]
    public function test_a_known_prefix_takes_any_number_of_digits(string $question, string $kind, string $code): void
    {
        $request = LindaLookup::parse($question);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $question);
        $this->assertSame($kind, $request->kind, $question);
        $this->assertSame($code, $request->code, $question);
        $this->assertNull($request->name);
    }

    public function test_an_unknown_prefix_or_no_separator_still_needs_three_digits(): void
    {
        $this->assertNull(LindaLookup::parse('what is XY-12'));
        $this->assertNull(LindaLookup::parse('LN42 days'));
    }

    /**
     * M2 (security): a phone number with spaces, dots, dashes or brackets
     * between its digits is a member lookup with no target.
     *
     * @return array<string, array{string}>
     */
    public static function phones(): array
    {
        return [
            'brackets and spaces' => ['Whose number is (0917) 123 4567?'],
            'dots' => ['Who has 0917.123.4567'],
            'a landline' => ['Who called from (032) 234-5678?'],
            'a short landline' => ['Whose number is 234-5678?'],
            'fullwidth digits' => ['Who has ０９１７ １２３ ４５６７?'],
            'square brackets' => ['Who has [0917] 123-4567'],
        ];
    }

    #[DataProvider('phones')]
    public function test_a_phone_number_with_separators_stays_local(string $question): void
    {
        $request = LindaLookup::parse($question);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $question);
        $this->assertSame(LindaLookupRequest::MEMBER, $request->kind);
        $this->assertNull($request->code);
        $this->assertNull($request->name);
    }

    /** @return array<string, array{string}> */
    public static function datesAndFigures(): array
    {
        return [
            'an ISO range' => ['collections from 2026-10-01 to 2026-10-31'],
            'a day range with a year' => ['collections October 1-15 2026'],
            'a day range with a spaced dash' => ['collections from October 1 - 15, 2026'],
            'aging buckets' => ['How many loans are 30 60 90 180 days late?'],
            'an amount' => ['How many loans are above ₱1,000,000.00?'],
        ];
    }

    #[DataProvider('datesAndFigures')]
    public function test_dates_and_figures_are_not_phone_numbers(string $question): void
    {
        $this->assertNull(LindaLookup::parse($question), $question);
    }

    /**
     * M3 (code): a capitalised stop word straight after a name word stays in
     * the name; an inner apostrophe is part of it; only a trailing 's drops.
     *
     * @return array<string, array{string, string}>
     */
    public static function wholeNames(): array
    {
        return [
            'Ang after a name' => ['Magkano ang balance ni Henry Ang?', 'Henry Ang'],
            'Tan after a name' => ['Pila ang utang ni Pedro Tan?', 'Pedro Tan'],
            'Tan before a verb' => ['How much does Pedro Tan owe?', 'Pedro Tan'],
            'Ang possessive' => ["What is Henry Ang's balance?", 'Henry Ang'],
            'an inner apostrophe' => ["balance of Mary O'Neil", "Mary O'Neil"],
            'an inner apostrophe before a verb' => ["How much does Liam O'Brien owe?", "Liam O'Brien"],
            'a trailing possessive dropped' => ["Find Juan's loans", 'Juan'],
            'an apostrophe and a possessive' => ["What's O'Neil's balance?", "O'Neil"],
        ];
    }

    #[DataProvider('wholeNames')]
    public function test_names_are_never_cut_short(string $question, string $name): void
    {
        $request = LindaLookup::parse($question);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $question);
        $this->assertSame($name, $request->name, $question);
    }

    public function test_a_lower_case_stop_word_after_a_name_ends_it(): void
    {
        $this->assertSame('henry', LindaLookup::parse('balance ni henry ang')?->name);
    }

    /**
     * M4 (code): a name with a payment-made word is a receipt lookup for that
     * member's latest payments; "bayad ni X" stays a member lookup.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function payments(): array
    {
        return [
            'did X pay' => ['Did Pedro pay?', 'Pedro', LindaLookupRequest::RECEIPT],
            'has X paid' => ['Has Maria Santos paid?', 'Maria Santos', LindaLookupRequest::RECEIPT],
            'how much has X paid' => ['How much has Maria Santos paid?', 'Maria Santos', LindaLookupRequest::RECEIPT],
            "X's payments" => ["Ana Lim's payments this month", 'Ana Lim', LindaLookupRequest::RECEIPT],
            'payments of X' => ['payments of Ana Lim', 'Ana Lim', LindaLookupRequest::RECEIPT],
            'payments by X' => ['payments by Ana Lim', 'Ana Lim', LindaLookupRequest::RECEIPT],
            'payments from X' => ['payments from Ana Lim', 'Ana Lim', LindaLookupRequest::RECEIPT],
            'nagbayad' => ['Nagbayad na ba si Rosa?', 'Rosa', LindaLookupRequest::RECEIPT],
            'nakabayad, Tagalog' => ['Nakabayad na ba si Rosa Santos?', 'Rosa Santos', LindaLookupRequest::RECEIPT],
            'mibayad' => ['Mibayad na ba si Pedro?', 'Pedro', LindaLookupRequest::RECEIPT],
            'nibayad' => ['Nibayad ba si Ana karon?', 'Ana', LindaLookupRequest::RECEIPT],
            'nakabayad, Bisaya' => ['Nakabayad na ba si Juan gahapon?', 'Juan', LindaLookupRequest::RECEIPT],
            'bayad ni stays a member lookup' => ['Magkano ang bayad ni Pedro?', 'Pedro', LindaLookupRequest::MEMBER],
            'owe stays a member lookup' => ['How much does Juan owe?', 'Juan', LindaLookupRequest::MEMBER],
        ];
    }

    #[DataProvider('payments')]
    public function test_a_payment_made_word_makes_a_receipt_lookup(string $question, string $name, string $kind): void
    {
        $request = LindaLookup::parse($question);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $question);
        $this->assertSame($kind, $request->kind, $question);
        $this->assertSame($kind === LindaLookupRequest::RECEIPT ? LindaIntent::TransactionLookup : LindaIntent::BorrowerLookup, $request->intent);
        $this->assertSame($name, $request->name, $question);
    }

    /**
     * M1 (security): more phrasings stay local.
     *
     * @return array<string, array{string, string}>
     */
    public static function morePhrasings(): array
    {
        return [
            // 1. Collection words, and "from" after them.
            'collections from X' => ['collections from Juan Dela Cruz', 'Juan Dela Cruz'],
            'nakolekta kang X' => ['Pila ang nakolekta kang Pedro?', 'Pedro'],
            'koleksyon ni X' => ['Magkano ang koleksyon ni Rosa Santos?', 'Rosa Santos'],
            'nasingil kay X' => ['Magkano ang nasingil kay Ana Lim?', 'Ana Lim'],
            // 2. A person marker and a name, with no money word.
            'kumusta si X' => ['Kumusta si Juan?', 'Juan'],
            'unsa na si X' => ['Unsa na si Pedro Reyes?', 'Pedro Reyes'],
            'si X ba' => ['si Rosa Santos ba?', 'Rosa Santos'],
            // 3. A follow-up phrase and only name-like words.
            'how about X' => ['how about Juan Dela Cruz?', 'Juan Dela Cruz'],
            'what about X' => ['What about Pedro?', 'Pedro'],
            'how about X naman' => ['how about Juan naman?', 'Juan'],
            // 4. Name-like words beside a money word.
            'X balance' => ['Juan Dela Cruz balance', 'Juan Dela Cruz'],
            'X utang' => ['Rosa Santos utang', 'Rosa Santos'],
            'balance X' => ['balance Juan Dela Cruz', 'Juan Dela Cruz'],
            // 5. "who is" in any case, and plain "find".
            'who is, lower case' => ['who is juan dela cruz', 'juan dela cruz'],
            'WHO IS' => ['WHO IS Pedro?', 'Pedro'],
            'find out about' => ['Find out about Juan Dela Cruz', 'Juan Dela Cruz'],
        ];
    }

    #[DataProvider('morePhrasings')]
    public function test_more_phrasings_are_member_lookups(string $question, string $name): void
    {
        $request = LindaLookup::parse($question);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $question);
        $this->assertSame($name, $request->name, $question);
    }

    public function test_collections_from_a_member_are_a_receipt_lookup(): void
    {
        $this->assertSame(LindaLookupRequest::RECEIPT, LindaLookup::parse('collections from Juan Dela Cruz')?->kind);
        $this->assertSame(LindaLookupRequest::RECEIPT, LindaLookup::parse('Pedro Reyes payments')?->kind);
    }

    /**
     * L1 (code): category words after "by" or "of" are never names, and other
     * aggregate phrasings stay questions for the matcher or the model.
     *
     * @return array<string, array{string}>
     */
    public static function notNames(): array
    {
        return [
            'by product' => ['payments by product'],
            'by type' => ['collections by type this month'],
            'by purpose' => ['loans by purpose'],
            'by area' => ['payments by area'],
            'by aging bucket' => ['balance by aging bucket'],
            'by term' => ['loans by term'],
            'by collector' => ['collections by collector'],
            'by branch' => ['collections by branch'],
            'by officer' => ['payments by officer'],
            'by method' => ['payments by method'],
            'by status' => ['loans by status'],
            'of the month' => ['payments of the month'],
            'by week' => ['collections by week'],
            'by day' => ['payments by day'],
            'by year' => ['collections by year'],
            'from GCash' => ['collections from GCash this month'],
            'from a branch' => ['collections from North branch'],
            'from members' => ['How much did we collect from members this month?'],
            'daily collections' => ['Daily collections'],
            'advance payments' => ['advance payments this month'],
            'loan balance' => ['total loan balance'],
            'how about a period' => ['how about last month?'],
            'how about a subject' => ['how about overdue?'],
            'what about a branch phrase' => ['what about the San Jose branch?'],
            'who is behind' => ['who is behind on payments?'],
            'who is the top officer' => ['Who is the top loan officer?'],
            'nobody paid' => ['Wala ni isa ang nakabayad kahapon?'],
            'Bisaya because' => ['Pila ang atraso kay wala pa nakabayad?'],
            'Bisaya because, daghan' => ['Nganong ubos ang koleksyon kay daghan atraso?'],
            'find out, no name' => ['Can you find out how much we collected?'],
        ];
    }

    #[DataProvider('notNames')]
    public function test_aggregate_questions_are_not_lookups(string $question): void
    {
        $this->assertNull(LindaLookup::parse($question), $question);
    }

    /**
     * L3 (security): the parser fails closed.
     */
    public function test_invalid_utf8_is_a_lookup(): void
    {
        $request = LindaLookup::parse("How much have we collected \xC3\x28 this month?");

        $this->assertInstanceOf(LindaLookupRequest::class, $request);
        $this->assertNull($request->name);
        $this->assertNull($request->code);
    }

    public function test_a_regex_failure_while_parsing_is_a_lookup(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $request = LindaLookup::parse('How much have we collected this month?');
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertInstanceOf(LindaLookupRequest::class, $request);
        $this->assertNull($request->name);
    }

    /**
     * H1: a short reply right after a lookup turn is that lookup's target.
     *
     * @return array<string, array{string, string, string, ?string, ?string}>
     */
    public static function replies(): array
    {
        return [
            'a name after which member' => ['Juan Dela Cruz', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Juan Dela Cruz'],
            'a name after which loan is a member' => ['Juan Dela Cruz', LindaLookupRequest::LOAN, LindaLookupRequest::MEMBER, null, 'Juan Dela Cruz'],
            'how about' => ['how about Juan Dela Cruz?', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Juan Dela Cruz'],
            'kumusta si' => ['kumusta si Rosa', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Rosa'],
            'si' => ['si Pedro', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Pedro'],
            'ni' => ['ni Pedro Reyes', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Pedro Reyes'],
            'kay' => ['kay Ana', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Ana'],
            'kang' => ['kang Ana Lim', LindaLookupRequest::RECEIPT, LindaLookupRequest::MEMBER, null, 'Ana Lim'],
            'six words' => ['Maria Clara de los Santos Cruz', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, null, 'Maria Clara de los Santos Cruz'],
            'a loan code' => ['LN-42', LindaLookupRequest::MEMBER, LindaLookupRequest::LOAN, 'LN-42', null],
            'a bare number after which loan' => ['42', LindaLookupRequest::LOAN, LindaLookupRequest::LOAN, 'LN-42', null],
            'a bare number after which receipt' => ['#123', LindaLookupRequest::RECEIPT, LindaLookupRequest::RECEIPT, 'RCP-123', null],
            'a bare number after which member' => ['12', LindaLookupRequest::MEMBER, LindaLookupRequest::MEMBER, 'BRW-12', null],
        ];
    }

    #[DataProvider('replies')]
    public function test_a_short_reply_after_a_lookup_is_its_target(string $reply, string $pending, string $kind, ?string $code, ?string $name): void
    {
        $request = LindaLookup::reply($reply, $pending);

        $this->assertInstanceOf(LindaLookupRequest::class, $request, $reply);
        $this->assertSame($kind, $request->kind, $reply);
        $this->assertSame($code, $request->code, $reply);
        $this->assertSame($name, $request->name, $reply);
    }

    /** @return array<string, array{string}> */
    public static function notReplies(): array
    {
        return [
            'a period' => ['last month'],
            'a subject' => ['how about overdue?'],
            'thanks' => ['thanks'],
            'never mind' => ['never mind'],
            'seven words' => ['one two three four five six seven'],
            'a question' => ['How much have we collected this month?'],
        ];
    }

    #[DataProvider('notReplies')]
    public function test_other_replies_after_a_lookup_are_not_its_target(string $reply): void
    {
        $this->assertNull(LindaLookup::reply($reply, LindaLookupRequest::MEMBER), $reply);
    }
}
