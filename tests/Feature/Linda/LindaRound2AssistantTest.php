<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\Linda\Model\LindaModel;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaUnderstanding;
use App\Services\Linda\Model\LindaUsage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `POST /api/linda/chat` end to end for Linda round 2: every lookup, in
 * English, Tagalog and Bisaya, is answered on the server by
 * LindaAnswerer::lookup() and never reaches the model; aggregate questions
 * in Bisaya are answered by the matcher; efficiency and performance
 * questions with no period read this month.
 */
class LindaRound2AssistantTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private User $asker;

    private FakeModel $fake;

    private Borrower $juan;

    private Loan $loan;

    private Repayment $receipt;

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

        $this->juan = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'contact_number' => '09171234567',
            'email' => 'juan.delacruz@example.test',
        ]);
        $this->loan = $this->releaseTo($this->juan, 60000);
        $this->pay($this->loan, 1500);
        $this->receipt = Repayment::query()->where('loan_id', $this->loan->id)->latest('id')->firstOrFail();
    }

    /**
     * The question, with {member}, {loan}, {application} and {receipt} for
     * the records' numbers (and {loan-short} without its leading zeros), and
     * the lookup kind and outcome expected.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function lookups(): array
    {
        return [
            'en member code' => ['Find member {member}', 'member', 'answered'],
            'en member name' => ['How much does Juan Dela Cruz owe?', 'member', 'answered'],
            'en possessive' => ["What is Juan Dela Cruz's balance?", 'member', 'answered'],
            'en loan' => ['What is the status of loan {loan}?', 'loan', 'answered'],
            'en loan short' => ['Status of {loan-short}', 'loan', 'answered'],
            'en application' => ['Details of {application}', 'loan', 'answered'],
            'en receipt' => ['Find receipt {receipt}', 'receipt', 'answered'],
            'en email' => ['Whose email is juan.delacruz@example.test?', 'member', 'no_target'],
            'en phone' => ['Who has the number 09171234567?', 'member', 'no_target'],
            'tl member name' => ['Magkano ang utang ni Juan Dela Cruz?', 'member', 'answered'],
            'tl find' => ['Hanapin si {member}', 'member', 'answered'],
            'tl loan' => ['Ano ang balance ng {loan}?', 'loan', 'answered'],
            'tl receipt' => ['Hanapin ang resibo {receipt}', 'receipt', 'answered'],
            'tl payments' => ['Magkano ang bayad ni Juan Dela Cruz?', 'member', 'answered'],
            'ceb member name' => ['Pila ang utang ni Juan Dela Cruz?', 'member', 'answered'],
            'ceb balance' => ['Unsa ang balanse ni Juan Dela Cruz?', 'member', 'answered'],
            'ceb kang' => ['Pila ang utang kang Juan Dela Cruz?', 'member', 'answered'],
            'ceb find loan' => ['Pangitaa ang {loan}', 'loan', 'answered'],
            'ceb receipt' => ['Pangitaa ang resibo {receipt}', 'receipt', 'answered'],
            'ceb no target' => ['Pila ang utang ni?', 'member', 'no_target'],
        ];
    }

    #[DataProvider('lookups')]
    public function test_a_lookup_is_answered_on_the_server_and_never_reaches_the_model(string $question, string $kind, string $outcome): void
    {
        $question = strtr($question, [
            '{member}' => $this->juan->borrower_code,
            '{loan}' => $this->loan->loan_account_number,
            '{loan-short}' => 'LN-'.str_pad(ltrim(substr((string) $this->loan->loan_account_number, 3), '0'), 3, '0', STR_PAD_LEFT),
            '{application}' => $this->loan->application_number,
            '{receipt}' => $this->receipt->receipt_number,
        ]);

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.$question);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame($outcome, $row->outcome);
        $this->assertNull($row->model);
        $this->assertSame('0.000000', $row->cost);

        $intent = ['member' => 'borrower_lookup', 'loan' => 'loan_details', 'receipt' => 'transaction_lookup'][$kind];
        $this->assertSame($intent, $response->json('data.intent'));

        if ($outcome === 'no_target') {
            $this->assertSame(LindaWording::whichRecord($kind), $response->json('data.answer'));
            $this->assertNull($row->member_ids);

            return;
        }

        $this->assertSame([$this->juan->id], $row->member_ids);
        $this->assertSame([$this->loan->id], $row->loan_ids);
        $this->assertStringContainsString($this->juan->borrower_code, $response->json('data.answer'));
        $this->assertStringNotContainsString('09171234567', $response->getContent());
        $this->assertStringNotContainsString('juan.delacruz', $response->getContent());
    }

    public function test_a_lookup_reply_equals_the_answerers_lookup(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Pila ang utang ni Juan Dela Cruz?'])->assertOk();

        $expected = app(LindaAnswerer::class)->lookup($this->asker, new LindaLookupRequest(LindaLookupRequest::MEMBER, LindaIntent::BorrowerLookup, null, 'Juan Dela Cruz'));
        $this->assertSame($expected->answer, $response->json('data.answer'));
        $this->assertEquals($expected->blocks, $response->json('data.blocks'));
        $this->assertSame($expected->links, $response->json('data.links'));
        $this->assertSame('MemberLookupAnswer', LindaQuestion::query()->latest('id')->value('answer_function'));
    }

    public function test_an_earlier_lookup_is_never_sent_to_the_model_later(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'How much interest income did we earn this month?',
            'history' => [
                ['role' => 'user', 'content' => 'Pila ang utang ni Juan Dela Cruz?', 'intent' => 'borrower_lookup'],
                ['role' => 'assistant', 'content' => ''],
                ['role' => 'user', 'content' => 'Find receipt '.$this->receipt->receipt_number, 'intent' => 'transaction_lookup'],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $this->assertSame([], $this->fake->requests[0]->topics);
    }

    public function test_a_period_after_a_receipt_lookup_is_never_read_as_another_receipt_lookup(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'Show the payments last month',
            'history' => [
                ['role' => 'user', 'content' => 'Find receipt '.$this->receipt->receipt_number],
                ['role' => 'assistant', 'content' => '', 'intent' => 'transaction_lookup'],
            ],
        ])->assertOk();

        $this->assertNotSame('transaction_lookup', $response->json('data.intent'));
        $this->assertNotSame(LindaWording::whichRecord(LindaLookupRequest::RECEIPT), $response->json('data.answer'));
        $this->assertCount(1, $this->fake->requests);
        $this->assertSame([], $this->fake->requests[0]->topics);
    }

    public function test_a_follow_up_after_a_lookup_carries_on_the_topic_asked_before_it(): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'how about last month?',
            'history' => [
                ['role' => 'user', 'content' => 'How much have we collected this month?'],
                ['role' => 'assistant', 'content' => '', 'intent' => 'collection_summary'],
                ['role' => 'user', 'content' => 'Find receipt '.$this->receipt->receipt_number],
                ['role' => 'assistant', 'content' => '', 'intent' => 'transaction_lookup'],
            ],
        ])->assertOk();

        $this->assertSame('collection_summary', $response->json('data.intent'));
        $this->assertStringContainsString(LindaPeriod::named('last_month')->label, (string) $response->json('data.answer'));
        $this->assertSame(LindaQuestion::SOURCE_MATCHER, LindaQuestion::query()->latest('id')->value('source'));
        $this->assertCount(0, $this->fake->requests);
    }

    public function test_a_lookup_intent_from_the_model_is_answered_locally_with_no_target(): void
    {
        $this->app->instance(FakeModel::class, new class implements LindaModel
        {
            public function understand(#[\SensitiveParameter] LindaModelRequest $request): ?LindaUnderstanding
            {
                return LindaUnderstanding::fromAnswer(LindaIntent::LoanDetails, null, null, false, new LindaUsage($request->model, 10, 5));
            }
        });

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Tell me about that one account from before'])->assertOk();

        $this->assertSame('loan_details', $response->json('data.intent'));
        $this->assertSame(LindaWording::whichRecord(LindaLookupRequest::LOAN), $response->json('data.answer'));
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_MODEL, $row->source);
        $this->assertSame('no_target', $row->outcome);
    }

    /** @return array<string, array{string, LindaIntent, ?string, ?string}> */
    public static function bisayaQuestions(): array
    {
        return [
            'collected' => ['Pila ang nakolekta karong bulana?', LindaIntent::CollectionSummary, 'this_month', null],
            'overdue' => ['Kinsa ang naay atraso?', LindaIntent::OverdueAccounts, null, null],
            'active loans' => ['Pila ka active loans nato?', LindaIntent::LoanSummary, null, null],
            'released' => ['Pila ang na-release niaging semana sa Main?', LindaIntent::DisbursementSummary, 'last_week', 'Main'],
            'yesterday' => ['Gahapon, pila ang bayad?', LindaIntent::CollectionSummary, 'yesterday', null],
            'efficiency' => ['Pila ang porsyento sa koleksyon karong bulana?', LindaIntent::CollectionEfficiency, 'this_month', null],
            'branches' => ['Unsang branch ang pinakadaghan og nakolekta karong bulana?', LindaIntent::BranchPerformance, 'this_month', null],
        ];
    }

    #[DataProvider('bisayaQuestions')]
    public function test_bisaya_aggregate_questions_are_answered_by_the_matcher(string $question, LindaIntent $intent, ?string $periodKey, ?string $branch): void
    {
        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $expected = app(LindaAnswerer::class)->answer($this->asker, $intent, $periodKey === null ? null : LindaPeriod::named($periodKey), $branch, $question);
        $this->assertSame($intent->value, $response->json('data.intent'));
        $this->assertSame($expected->answer, $response->json('data.answer'));
        $this->assertEquals($expected->blocks, $response->json('data.blocks'));
        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaQuestion::SOURCE_MATCHER, LindaQuestion::query()->latest('id')->value('source'));
    }

    /** @return array<string, array{string, string}> */
    public static function undatedFigures(): array
    {
        return [
            'efficiency' => ['What is our collection rate?', 'collection_efficiency'],
            'branches' => ['Which branch has the most overdue?', 'performance'],
            'officers' => ['How is each collector doing?', 'performance'],
            'payments' => ['Who paid?', 'repayments_list'],
        ];
    }

    #[DataProvider('undatedFigures')]
    public function test_efficiency_performance_and_payments_without_a_period_read_this_month(string $question, string $report): void
    {
        $month = LindaPeriod::named('this_month');

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame('answered', LindaQuestion::query()->latest('id')->value('outcome'));
        $this->assertContains(
            '/reports/'.$report.'?from='.$month->from->toDateString().'&to='.$month->to->toDateString(),
            array_column($response->json('data.links'), 'url'),
        );
        $this->assertSame($month->from->toDateString(), LindaQuestion::query()->latest('id')->first()->period_from?->toDateString());
    }
}
