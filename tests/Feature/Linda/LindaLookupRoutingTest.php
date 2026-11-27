<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\GCashNonMember;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaNameCheck;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\Linda\Model\LindaModel;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaUnderstanding;
use App\Services\Linda\Model\LindaUsage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * `POST /api/linda/chat` after the round 2 reviews: names and record numbers
 * stay on the server (the full-name check, a pending lookup, short record
 * numbers), the model sees earlier questions as topics only, a follow-up
 * skips lookups, and a branch name is never a member lookup.
 */
class LindaLookupRoutingTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private User $asker;

    private FakeModel $fake;

    private Borrower $juan;

    private Loan $loan;

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

        $this->juan = $this->member($this->branch, 'Juan', 'Dela Cruz');
        $this->loan = $this->releaseTo($this->juan, 60000);
    }

    /**
     * D2: a question that names a member by full name, in any form, is a
     * member lookup and never reaches the model.
     *
     * @return array<string, array{string}>
     */
    public static function namedMembers(): array
    {
        return [
            'is X overdue' => ['Is Juan Dela Cruz overdue?'],
            'X balance' => ['Juan Dela Cruz balance'],
            'how about X' => ['how about Juan Dela Cruz?'],
            'reversed' => ['Is Dela Cruz, Juan overdue?'],
            'lower case' => ['is juan dela cruz overdue'],
        ];
    }

    #[DataProvider('namedMembers')]
    public function test_a_members_full_name_never_reaches_the_model(string $question): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.$question);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('borrower_lookup', $response->json('data.intent'));
        $this->assertSame('answered', $row->outcome);
        $this->assertSame([$this->juan->id], $row->member_ids);
    }

    public function test_the_full_name_check_reads_the_whole_deployment_and_the_answer_keeps_its_scope(): void
    {
        $this->member($this->far, 'Rosa', 'Malaya');
        $mainOnly = $this->userWith(['reports:view', 'loans:view', 'borrowers:view', 'payments:view', 'linda:use'], $this->branch);

        $response = $this->actingAs($mainOnly)->postJson(self::URL, ['message' => 'Is Rosa Malaya overdue?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaWording::noMemberNamed('Rosa Malaya'), $response->json('data.answer'));
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, LindaQuestion::query()->latest('id')->value('source'));
    }

    public function test_a_question_naming_no_one_still_reaches_the_model(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How much interest did we earn this month?'])->assertOk();

        $this->assertCount(1, $this->fake->requests);
    }

    public function test_a_common_word_equal_to_a_first_name_alone_still_reaches_the_model(): void
    {
        $this->member($this->branch, 'May', 'Bituin');

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How much interest did we earn in May?'])->assertOk();

        $this->assertCount(1, $this->fake->requests);
    }

    public function test_a_staff_name_with_performance_words_is_the_officer_performance_answer(): void
    {
        User::factory()->inBranches($this->branch)->create(['first_name' => 'Pedro', 'last_name' => 'Bantay']);

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How is Pedro Bantay performing as an officer?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $month = LindaPeriod::named('this_month');
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('officer_performance', $response->json('data.intent'));
        $this->assertSame($month->from->toDateString(), $row->period_from?->toDateString());
    }

    public function test_a_staff_name_with_a_period_reads_that_period(): void
    {
        User::factory()->inBranches($this->branch)->create(['first_name' => 'Pedro', 'last_name' => 'Bantay']);

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How did Pedro Bantay rank last month?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame('officer_performance', $row->intent);
        $this->assertSame(LindaPeriod::named('last_month')->from->toDateString(), $row->period_from?->toDateString());
    }

    public function test_a_staff_name_without_performance_words_gets_a_local_not_yet(): void
    {
        User::factory()->inBranches($this->branch)->create(['first_name' => 'Pedro', 'last_name' => 'Bantay']);

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Is Pedro Bantay around today?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('not_yet', $row->outcome);
        $this->assertSame(LindaWording::staffNotYet(), $response->json('data.answer'));
    }

    /**
     * D2's cost: one indexed read per table (members, staff and co-makers by
     * last name, walk-ins by full name), and one read of the unlinked CDA
     * officers by name (round 4; a small register).
     */
    public function test_the_full_name_check_reads_members_and_staff_by_an_indexed_last_name(): void
    {
        $this->assertContains(['last_name'], array_column(Schema::getIndexes('borrowers'), 'columns'));
        $this->assertContains(['last_name'], array_column(Schema::getIndexes('users'), 'columns'));
        $this->assertContains(['last_name'], array_column(Schema::getIndexes('co_makers'), 'columns'));
        $this->assertContains(['full_name'], array_column(Schema::getIndexes('gcash_non_members'), 'columns'));

        DB::enableQueryLog();
        $hit = LindaNameCheck::find('Is Juan Dela Cruz overdue this month?');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(LindaNameCheck::MEMBER, $hit?->kind);
        $this->assertCount(5, $queries);
        $this->assertStringContainsString('cda_officers', $queries[4]['query']);
    }

    /**
     * H1: the name typed after "Which member?" is that lookup's target.
     *
     * @return array<string, array{string, string}>
     */
    public static function pendingReplies(): array
    {
        return [
            'a member' => ['Juan Dela Cruz', 'answered'],
            'a name that is no member' => ['Pedro Walangtao', 'not_found'],
            'how about' => ['how about Juan Dela Cruz?', 'answered'],
            'kay' => ['kay Juan Dela Cruz', 'answered'],
        ];
    }

    #[DataProvider('pendingReplies')]
    public function test_a_reply_to_which_member_is_answered_locally(string $reply, string $outcome): void
    {
        $first = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Pila ang utang ni?'])->assertOk();
        $this->assertSame(LindaWording::whichRecord(LindaLookupRequest::MEMBER), $first->json('data.answer'));

        $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => $reply,
            'history' => [
                ['role' => 'user', 'content' => 'Pila ang utang ni?'],
                ['role' => 'assistant', 'content' => '', 'intent' => $first->json('data.intent')],
            ],
        ])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame($outcome, $row->outcome);
    }

    public function test_a_lookup_intent_from_the_model_gets_the_no_target_prompt(): void
    {
        $this->app->instance(FakeModel::class, new class implements LindaModel
        {
            public function understand(#[\SensitiveParameter] LindaModelRequest $request): ?LindaUnderstanding
            {
                return LindaUnderstanding::fromAnswer(LindaIntent::BorrowerLookup, null, null, false, new LindaUsage($request->model, 10, 5));
            }
        });

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'find Main'])->assertOk();

        $this->assertSame(LindaWording::whichRecord(LindaLookupRequest::MEMBER), $response->json('data.answer'));
        $this->assertSame('no_target', LindaQuestion::query()->latest('id')->value('outcome'));
    }

    /**
     * M1 (security) item 6: a "name" that is one of the user's branches is
     * no lookup.
     */
    public function test_a_branch_name_is_never_a_member_lookup(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'collections from Main'])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $this->assertSame('collection_summary', $response->json('data.intent'));
        $this->assertSame(LindaQuestion::SOURCE_MODEL, LindaQuestion::query()->latest('id')->value('source'));
    }

    /**
     * M2 (code): a follow-up skips an earlier lookup, also when it works out
     * the intent before an earlier question.
     */
    public function test_a_follow_up_after_a_lookup_keeps_the_period_and_takes_the_branch(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'sa Main naman?',
            'history' => [
                ['role' => 'user', 'content' => 'collect last week'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'collection_summary'],
                ['role' => 'user', 'content' => 'utang ni Juan'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'borrower_lookup'],
            ],
        ])->assertOk();

        $expected = app(LindaAnswerer::class)->answer($this->asker, LindaIntent::CollectionSummary, LindaPeriod::named('last_week'), 'Main', 'sa Main naman?');
        $this->assertSame('collection_summary', $response->json('data.intent'));
        $this->assertSame($expected->answer, $response->json('data.answer'));
        $this->assertSame([], $this->fake->requests);
    }

    /** @return array<string, array{string}> */
    public static function shortNumbers(): array
    {
        return [
            'LN-42' => ['Status of LN-42'],
            'ln#42' => ['ln#42'],
            'RCP-7' => ['RCP-7'],
            'BRW-12' => ['BRW-12 status'],
            'a phone in brackets' => ['Whose number is (0917) 123 4567?'],
            'an en dash' => ['Status of LN–42'],
        ];
    }

    #[DataProvider('shortNumbers')]
    public function test_short_record_numbers_and_phones_never_reach_the_model(string $question): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.$question);
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, LindaQuestion::query()->latest('id')->value('source'));
    }

    /**
     * D1: earlier questions go to the model as topics only.
     */
    public function test_the_model_never_sees_an_earlier_questions_text(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'How much interest did we earn this month?',
            'history' => [
                ['role' => 'user', 'content' => 'Which members currently have overdue payments?'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'overdue_accounts'],
                ['role' => 'user', 'content' => 'Pila ang utang ni Juan Dela Cruz?'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'borrower_lookup'],
                ['role' => 'user', 'content' => 'How many active loans do we have sa Riverside?'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'loan_summary'],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $request = $this->fake->requests[0];
        $this->assertSame(['overdue_accounts', 'loan_summary'], $request->topics);
        $sent = serialize($request);
        foreach (['Which members', 'Juan', 'Dela Cruz', 'active loans', 'Riverside'] as $text) {
            $this->assertStringNotContainsString($text, $sent);
        }
    }

    public function test_a_follow_up_the_model_reads_still_works_end_to_end(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'and how about last month po talaga?',
            'history' => [
                ['role' => 'user', 'content' => 'How much interest did we earn this month?'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'income_summary'],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $this->assertSame(['income_summary'], $this->fake->requests[0]->topics);
        $this->assertSame('income_summary', $response->json('data.intent'));
        $this->assertSame(LindaPeriod::named('last_month')->from->toDateString(), LindaQuestion::query()->latest('id')->first()->period_from?->toDateString());
    }

    /**
     * L3 (security): invalid UTF-8 is refused.
     */
    public function test_invalid_utf8_is_refused(): void
    {
        $this->actingAs($this->asker)
            ->post(self::URL, ['message' => "How much \xC3\x28 collected?"], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->actingAs($this->asker)
            ->post(self::URL, ['message' => 'collections', 'history' => [['role' => 'user', 'content' => "bad \xFF"]]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('history.0.content');

        $this->assertSame([], $this->fake->requests);
    }

    /**
     * Re-review Medium 2: a "name" that is a loan product is no lookup.
     */
    public function test_a_loan_product_name_is_never_a_member_lookup(): void
    {
        LoanProduct::factory()->create(['name' => 'Pangkabuhayan Loan']);

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'collections of Pangkabuhayan'])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $this->assertSame(LindaQuestion::SOURCE_MODEL, LindaQuestion::query()->latest('id')->value('source'));
    }

    /**
     * Re-review Low 4: a branch name doesn't throw away the rest of a lookup.
     */
    public function test_a_branch_name_keeps_the_rest_of_the_lookup(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'collections from Main and balance of Pedro Walangtao'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('not_found', $row->outcome);
    }

    /**
     * Re-review Low 1: a database error in the full-name check answers 503
     * data_unavailable, logs a digest only, and never reaches the model.
     */
    public function test_a_database_error_in_the_name_check_is_data_unavailable(): void
    {
        Log::spy();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'last_name') && str_contains($query->sql, 'borrowers')) {
                throw new RuntimeException('secret detail Juan Dela Cruz');
            }
        });

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Is Juan Dela Cruz overdue?'])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'data_unavailable');

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame('data_unavailable', $row->error_code);
        $this->assertNull($row->model);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains(serialize($context), 'secret detail') && ! str_contains($message, 'Juan'))->once();
    }

    /**
     * Re-review item 7: co-makers and walk-ins named in full stay local.
     *
     * @return array<string, array{string}>
     */
    public static function otherPeople(): array
    {
        return [
            'a co-maker' => ['Is Lito Lapid a reliable co-maker?'],
            'a walk-in' => ['Did Carmen Soriano cash in today?'],
        ];
    }

    #[DataProvider('otherPeople')]
    public function test_a_co_maker_or_walk_in_gets_a_local_not_yet(string $question): void
    {
        CoMaker::factory()->create(['borrower_id' => $this->juan->id, 'first_name' => 'Lito', 'last_name' => 'Lapid']);
        GCashNonMember::factory()->create(['full_name' => 'Carmen Soriano']);

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('not_yet', $row->outcome);
        $this->assertSame(LindaWording::otherPersonNotYet(), $response->json('data.answer'));
    }

    public function test_an_accent_typed_without_it_stays_local(): void
    {
        $this->member($this->branch, 'Rosa', 'Peña');

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Is Rosa Pena overdue?'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, LindaQuestion::query()->latest('id')->value('source'));
    }
}
