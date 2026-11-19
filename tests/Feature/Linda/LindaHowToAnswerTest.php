<?php

namespace Tests\Feature\Linda;

use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;

/**
 * Linda round 3, "How do I…" and "Where is…": answered on the server from
 * the menu (LindaMenu), with a link to the page, never by the model. A page
 * the caller can't open is refused in words; a how or where question about
 * nothing in the menu gets the "not yet" reply with what Linda can answer.
 */
class LindaHowToAnswerTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

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
    }

    /** @return array<string, mixed> */
    private function chat(User $user, string $message): array
    {
        return $this->actingAs($user)->postJson(self::URL, ['message' => $message])->assertOk()->json('data') ?? [];
    }

    public function test_a_how_to_question_is_answered_from_the_menu_with_a_link(): void
    {
        $staff = $this->branchUser(['linda:use', 'borrowers:view', 'borrowers:create']);

        $reply = $this->chat($staff, 'How do I add a member?');

        $this->assertSame('how_to', $reply['intent']);
        $this->assertSame('Go to Members → New Member in the side menu.', $reply['answer']);
        $this->assertSame([['label' => 'Open New Member', 'url' => '/borrowers/new']], $reply['links']);
        $this->assertSame([], $this->fake->requests);

        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame('matcher', $log->source);
        $this->assertSame('HowToAnswer', $log->answer_function);
        $this->assertSame('answered', $log->outcome);
    }

    public function test_tagalog_and_bisaya_and_two_places(): void
    {
        $staff = $this->headOffice(['linda:use', 'payments:view', 'accounting:view', 'cda:view', 'cda:view_audit']);

        $this->assertSame('Go to Payments → Payment History in the side menu.', $this->chat($staff, 'Saan makikita ang payment history?')['answer']);
        $this->assertSame('Go to Accounting → Trial Balance in the side menu.', $this->chat($staff, 'Asa nako makita ang trial balance?')['answer']);

        $two = $this->chat($staff, 'Where are the financial statements?');
        $this->assertSame('You\'ll find that in two places in the side menu: CDA Compliance → Financial Statements and Accounting → Financial Statements.', $two['answer']);
        $this->assertSame(['/cda/financial-statements', '/accounting/statements'], array_column($two['links'], 'url'));
        $this->assertSame([], $this->fake->requests);
    }

    public function test_a_page_the_caller_cant_open_is_refused_and_left_out(): void
    {
        $viewer = $this->branchUser(['linda:use', 'borrowers:view']);
        $reply = $this->chat($viewer, 'How do I add a member?');

        $this->assertSame('Your role doesn\'t have access to that page. Please ask your administrator if you need it.', $reply['answer']);
        $this->assertSame([], $reply['links']);

        $accounting = $this->headOffice(['linda:use', 'accounting:view']);
        $one = $this->chat($accounting, 'Where are the financial statements?');
        $this->assertSame('Go to Accounting → Financial Statements in the side menu.', $one['answer']);
    }

    public function test_a_how_to_question_the_menu_doesnt_cover_gets_not_yet_from_the_model_path(): void
    {
        $staff = $this->headOffice(['linda:use', 'loans:view']);

        $reply = $this->chat($staff, 'How do I approve a loan?');

        $this->assertSame('how_to', $reply['intent']);
        $this->assertSame(LindaWording::notYet(), $reply['answer']);
        $this->assertCount(1, $this->fake->requests);
    }

    public function test_a_lookup_never_becomes_a_how_to_answer(): void
    {
        $staff = $this->headOffice(['linda:use', 'borrowers:view', 'loans:view', 'borrowers:create']);
        $this->member($this->branch, 'Juan', 'Dela Cruz');

        $reply = $this->chat($staff, 'Where is Juan Dela Cruz?');

        $this->assertNotSame('how_to', $reply['intent']);
        $this->assertSame([], $this->fake->requests);
    }
}
