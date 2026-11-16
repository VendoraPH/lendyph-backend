<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaNameCheck;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The full-name check against real database rows: members, staff, co-makers
 * and walk-ins inserted as they are stored, read through LindaNameCheck's own
 * queries (never a fake list), and end to end through `POST /api/linda/chat`.
 */
class LindaNameCheckDatabaseTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private User $asker;

    private FakeModel $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'fake');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.monthly_cap', '1000.00');
        config()->set('linda.usd_to_php', '62.00');
        $this->fake = new FakeModel;
        $this->app->instance(FakeModel::class, $this->fake);
        $this->asker = $this->headOffice(['reports:view', 'loans:view', 'borrowers:view', 'payments:view', 'linda:use']);

        $this->stored('Juan', 'Tan', 'Dela Cruz');
        $this->stored('May', null, 'Santos');
        $this->stored('Maya', null, 'Reyes');
        $this->stored('Isa', null, 'Cruz');
        $this->stored('Will', null, 'Tan');
        $this->stored('Rosa', null, 'Peña');
        $this->stored('Ana', null, 'Pena');
        $this->stored('Jose', null, 'Sta. Maria');
        $this->stored('Pedro', null, 'Sta Maria');
        $this->stored('Maria Clara', null, 'Sto. Tomas');
        User::factory()->inBranches($this->branch)->create(['first_name' => 'Will', 'last_name' => 'Bantay']);
        $owner = $this->stored('Lito', null, 'Owner');
        CoMaker::factory()->create(['borrower_id' => $owner->id, 'first_name' => 'May', 'last_name' => 'Lapid']);
    }

    /**
     * Every name-check case, read from the database.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function hits(): array
    {
        return [
            'a common word as first name (May)' => ['How reliable is May Santos?', LindaNameCheck::MEMBER, 'May Santos'],
            'Maya' => ['Is Maya Reyes current?', LindaNameCheck::MEMBER, 'Maya Reyes'],
            'Isa' => ['Isa Cruz', LindaNameCheck::MEMBER, 'Isa Cruz'],
            'Will Tan, both common words' => ['Will Tan', LindaNameCheck::MEMBER, 'Will Tan'],
            'a middle initial' => ['Is Juan A. Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a stop word as the stored middle name' => ['Is Juan Tan Dela Cruz overdue?', LindaNameCheck::MEMBER, 'Juan Tan Dela Cruz'],
            'reversed' => ['Is Dela Cruz, Juan overdue?', LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'typed without the accent' => ['Is Rosa Pena late?', LindaNameCheck::MEMBER, 'Rosa Pena'],
            'typed with the accent' => ['Is Ana Peña late?', LindaNameCheck::MEMBER, 'Ana Peña'],
            'a zero-width space' => ["Is Ju\u{200B}an Dela Cruz overdue?", LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a soft hyphen' => ["Is Juan Dela Cr\u{00AD}uz overdue?", LindaNameCheck::MEMBER, 'Juan Dela Cruz'],
            'a stored dotted surname typed without the dot' => ['Is Jose Sta Maria late?', LindaNameCheck::MEMBER, 'Jose Sta Maria'],
            'a stored plain surname typed with a dot' => ['Is Pedro Sta. Maria late?', LindaNameCheck::MEMBER, 'Pedro Sta. Maria'],
            'Sto. Tomas, a two-word first name' => ['Is Maria Clara Sto Tomas late?', LindaNameCheck::MEMBER, 'Maria Clara Sto Tomas'],
            'staff with a common-word first name' => ['Is Will Bantay around?', LindaNameCheck::STAFF, 'Will Bantay'],
            'a co-maker with a common-word first name' => ['Is May Lapid a reliable co-maker?', LindaNameCheck::CO_MAKER, 'May Lapid'],
        ];
    }

    #[DataProvider('hits')]
    public function test_a_stored_person_is_found_in_the_database(string $question, string $kind, ?string $name): void
    {
        $hit = LindaNameCheck::find($question);

        $this->assertInstanceOf(LindaNameCheck::class, $hit, $question);
        $this->assertSame($kind, $hit->kind, $question);
        $this->assertSame($name, $hit->name, $question);
    }

    /** @return array<string, array{string}> */
    public static function localQuestions(): array
    {
        return [
            'May Santos' => ['How reliable is May Santos?'],
            'Maya Reyes' => ['Maya Reyes balance?'],
            'Isa Cruz' => ['Isa Cruz'],
            'Will Tan' => ['Will Tan'],
            'Sta Maria' => ['Is Jose Sta Maria late?'],
        ];
    }

    #[DataProvider('localQuestions')]
    public function test_a_member_named_with_common_words_never_reaches_the_model(string $question): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.$question);
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, LindaQuestion::query()->latest('id')->value('source'));
    }

    public function test_a_question_naming_no_one_still_reaches_the_model(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How much interest income did we earn in May?'])->assertOk();

        $this->assertCount(1, $this->fake->requests);
    }

    /** @return array<string, array{string}> */
    public static function walkIns(): array
    {
        return [
            'a middle initial' => ['Carmen R. Soriano'],
            'reversed with a comma' => ['Soriano, Carmen'],
            'extra spaces and a dot' => ['carmen   soriano.'],
        ];
    }

    #[DataProvider('walkIns')]
    public function test_a_walk_in_stored_as_free_text_is_found(string $stored): void
    {
        GCashNonMember::factory()->create(['full_name' => $stored]);

        $hit = LindaNameCheck::find('Did Carmen Soriano cash in today?');
        $this->assertSame(LindaNameCheck::WALK_IN, $hit?->kind);

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Did Carmen Soriano cash in today?'])->assertOk();
        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaWording::otherPersonNotYet(), $response->json('data.answer'));
    }

    /**
     * Low 4: the person covering the most typed words wins; a tie is listed.
     */
    public function test_the_member_covering_the_most_typed_words_wins(): void
    {
        $longer = $this->stored('Juan', 'Pedro', 'Dela Cruz');
        $this->stored('Juan', null, 'Dela Cruz');

        $hit = LindaNameCheck::find('Is Juan Pedro Dela Cruz overdue?');
        $this->assertSame('Juan Pedro Dela Cruz', $hit?->name);

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Is Juan Pedro Dela Cruz overdue?'])->assertOk();
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame([], $this->fake->requests);
        $this->assertSame([$longer->id], $row->member_ids);
    }

    public function test_two_members_covering_the_same_words_are_listed_never_guessed(): void
    {
        $this->stored('Juan', 'Pedro', 'Dela Cruz');
        $this->stored('Juan Pedro', null, 'Dela Cruz');

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Is Juan Pedro Dela Cruz overdue?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame('ambiguous_record', LindaQuestion::query()->latest('id')->value('outcome'));
    }

    /**
     * Low 3: a re-read that gives up stays local, as a lookup with no target.
     */
    public function test_a_re_read_that_gives_up_asks_which_member(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'balance of Main and balance of Main and balance of Main and balance of Main',
        ])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaWording::whichRecord(LindaLookupRequest::MEMBER), $response->json('data.answer'));
    }

    private function stored(string $first, ?string $middle, string $last): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
        ]);
    }
}
