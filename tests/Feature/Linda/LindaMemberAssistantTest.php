<?php

namespace Tests\Feature\Linda;

use App\Models\User;
use App\Services\Linda\Model\FakeModel;

/**
 * Member questions through `POST /api/linda/chat`: the matcher answers them
 * in English, Tagalog and Bisaya, a follow-up carries the topic, and a
 * question only the model reads sends it the question and earlier topics,
 * never member figures or names.
 */
class LindaMemberAssistantTest extends LindaAnswersTestCase
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
        $this->asker = $this->headOffice(['reports:view', 'loans:view', 'borrowers:view', 'linda:use']);

        $this->releaseTo($this->member($this->branch, 'Rosalinda', 'Magbanua'));
        $this->member($this->branch, 'Teodoro', 'Villanueva');
        $this->member($this->far, 'Perpetua', 'Dumaguing');
    }

    public function test_the_example_question_is_answered(): void
    {
        $this->actingAs($this->asker);

        $response = $this->postJson(self::URL, ['message' => 'how many active members do we have?'])->assertOk();

        $this->assertSame('borrower_summary', $response->json('data.intent'));
        $this->assertStringContainsString('you have 3 active members across all branches. 1 member has a loan being repaid.', $response->json('data.answer'));
        $this->assertSame([], $this->fake->requests);
    }

    public function test_tagalog_and_bisaya_questions_are_answered_without_the_model(): void
    {
        $this->actingAs($this->asker);

        foreach (['Ilang active na miyembro natin?', 'Pila ka aktibo nga miyembro nato?', 'Ilan ang bagong miyembro ngayong buwan?', 'Pila ka bag-ong miyembro karong bulana?'] as $question) {
            $response = $this->postJson(self::URL, ['message' => $question])->assertOk();
            $this->assertSame('borrower_summary', $response->json('data.intent'), $question);
            $this->assertStringNotContainsString("can't answer", $response->json('data.answer'), $question);
        }

        $this->assertSame([], $this->fake->requests);
    }

    public function test_a_follow_up_carries_the_member_topic_and_its_branch(): void
    {
        $this->actingAs($this->asker);
        $first = 'How many active members do we have?';
        $answer = $this->postJson(self::URL, ['message' => $first])->json('data.answer');

        $followUp = $this->postJson(self::URL, [
            'message' => 'sa Main naman?',
            'history' => [
                ['role' => 'user', 'content' => $first],
                ['role' => 'assistant', 'content' => $answer, 'intent' => 'borrower_summary'],
            ],
        ])->assertOk();

        $this->assertSame('borrower_summary', $followUp->json('data.intent'));
        $this->assertStringContainsString('you have 2 members for Main Branch: 2 active, 0 inactive and 0 blacklisted.', $followUp->json('data.answer'));
    }

    public function test_the_model_never_receives_member_figures_or_names(): void
    {
        $this->actingAs($this->asker);
        $first = 'How many members do we have?';
        $answer = $this->postJson(self::URL, ['message' => $first])->json('data.answer');
        $this->assertStringContainsString('3 members', $answer);

        $response = $this->postJson(self::URL, [
            'message' => 'How many new members joined this month?',
            'history' => [
                ['role' => 'user', 'content' => $first],
                ['role' => 'assistant', 'content' => $answer, 'intent' => 'borrower_summary'],
            ],
        ])->assertOk();

        $this->assertSame('borrower_summary', $response->json('data.intent'));
        $this->assertStringContainsString('3 new members joined', $response->json('data.answer'));
        $this->assertCount(1, $this->fake->requests);

        $sent = json_encode([$this->fake->requests[0]->question, $this->fake->requests[0]->topics]);
        $this->assertSame('["How many new members joined this month?",["borrower_summary"]]', $sent);
        foreach (['Rosalinda', 'Magbanua', 'Teodoro', 'Perpetua', '3 members', 'active'] as $secret) {
            $this->assertStringNotContainsString($secret, $sent);
        }
    }
}
