<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\Linda\Answers\NotYetAnswer;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaFacts;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\Linda\Model\LindaWriteRequest;
use App\Services\MenuVisibility\MenuVisibility;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Linda and Menu Visibility (finish run round 6, follow-up 2): a question about
 * a hidden module gets a short local answer, never the model and never a figure
 * or a link; how-to answers never link or name a hidden menu; open questions'
 * packs leave hidden modules out; and the suggestions leave them out too.
 * Modules that are shown answer as before.
 */
class LindaHiddenModulesTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private const TURNED_OFF = 'That part of the app is turned off here.';

    private FakeModel $fake;

    private Borrower $ana;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'fake');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.monthly_cap', '1000.00');
        config()->set('linda.usd_to_php', '62.00');
        config()->set('linda.open_questions', true);
        $this->fake = new FakeModel;
        $this->app->instance(FakeModel::class, $this->fake);

        $this->ana = $this->member($this->branch, 'Ana', 'Reyes');
        $this->pay($this->releaseTo($this->ana, 60000), 2500);
    }

    private function hide(string ...$modules): void
    {
        app(MenuVisibility::class)->save($modules, User::query()->where('username', 'super_admin')->firstOrFail());
    }

    private function asker(): User
    {
        return $this->headOffice([
            'reports:view', 'loans:view', 'borrowers:view', 'payments:view', 'savings:view', 'share_capital:view',
            'cda:view', 'credit_scoring:view', 'accounting:view', 'expenses:view', 'reminders:view', 'linda:use',
        ]);
    }

    private function chat(string $message, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->asker())->postJson(self::URL, ['message' => $message])->assertOk()->json('data');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hiddenTopics(): array
    {
        return [
            'savings' => ['savings', 'How much savings do we have?'],
            'share capital' => ['share_capital', 'How much share capital do we have?'],
            'CDA' => ['cda', 'When is our next CDA deadline?'],
            'credit scoring' => ['credit_scoring', 'How many high risk members do we have?'],
            'income (accounting)' => ['accounting', 'How much income did we earn this month?'],
            'expenses (accounting)' => ['accounting', 'How much were our expenses this month?'],
            'reminders' => ['loans.reminders', 'How many reminders were sent this month?'],
            'savings, put another way' => ['savings', 'Tell me something interesting about our deposits lately'],
        ];
    }

    #[DataProvider('hiddenTopics')]
    public function test_a_hidden_modules_topic_answers_locally_with_no_figure_link_or_model(string $module, string $question): void
    {
        $shown = $this->chat($question);
        $this->assertNotSame(self::TURNED_OFF, $shown['answer']);

        $this->hide($module);
        $this->fake->requests = [];
        $this->fake->writeRequests = [];

        $hidden = $this->chat($question);

        $this->assertSame(self::TURNED_OFF, $hidden['answer']);
        $this->assertSame([], $hidden['blocks']);
        $this->assertSame([], $hidden['links']);
        $this->assertSame([], $this->fake->requests, 'The question reached the model.');
        $this->assertSame([], $this->fake->writeRequests);
        $this->assertSame('turned_off', LindaQuestion::query()->latest('id')->value('outcome'));
    }

    public function test_a_topic_the_model_picks_answers_locally_too(): void
    {
        $this->hide('savings');

        $reply = app(LindaAnswerer::class)->answer($this->asker(), LindaIntent::SavingsSummary, LindaPeriod::named('this_month'), null);

        $this->assertSame([self::TURNED_OFF, 'turned_off', [], []], [$reply->answer, $reply->outcome, $reply->blocks, $reply->links]);
    }

    public function test_lookups_stay_with_their_core_module(): void
    {
        $this->hide('savings', 'accounting', 'cda');
        $found = $this->chat('Find member Ana Reyes');
        $this->assertNotSame(self::TURNED_OFF, $found['answer']);
        $this->assertStringContainsString('Ana Reyes', $found['answer']);

        $this->hide('members');
        $this->assertSame(self::TURNED_OFF, $this->chat('Find member Ana Reyes')['answer']);
    }

    public function test_how_to_never_links_or_names_a_hidden_menu(): void
    {
        $shown = $this->chat('Where is Savings Accounts?');
        $this->assertSame(['/savings'], array_column($shown['links'], 'url'));

        $this->hide('savings');

        foreach (['Where is Savings Accounts?', 'How do I add a savings account?', 'Where are the savings settings?'] as $question) {
            $reply = $this->chat($question);
            $this->assertSame(self::TURNED_OFF, $reply['answer'], $question);
            $this->assertSame([], $reply['links'], $question);
        }

        // A menu that is shown is unchanged.
        $this->assertSame(['/loans'], array_column($this->chat('Where is All Loans?')['links'], 'url'));
    }

    public function test_the_open_question_pack_leaves_out_a_hidden_modules_figures(): void
    {
        $this->fake->writer = fn (LindaWriteRequest $request): string => 'Here is how it looks.';
        $question = 'Why do our share capital and collections look like this overall?';

        $this->chat($question);
        $this->assertSame(['collection_summary', 'share_capital_summary'], array_values(array_unique(array_column($this->fake->writeRequests[0]->facts['topics'], 'topic'))));

        $this->hide('share_capital');
        $this->chat($question);
        $this->assertSame(['collection_summary'], array_values(array_unique(array_column($this->fake->writeRequests[1]->facts['topics'], 'topic'))));

        $pack = app(LindaFacts::class)->build($this->asker(), LindaPeriod::named('this_month'), null, [LindaIntent::ShareCapitalSummary, LindaIntent::CollectionSummary]);
        $this->assertSame(['collection_summary'], array_values(array_unique(array_column($pack['facts']['topics'], 'topic'))));
    }

    public function test_links_to_a_hidden_page_are_left_out_of_any_answer(): void
    {
        $shown = $this->chat('How much have we collected this month?');
        $this->assertNotEmpty(array_filter(array_column($shown['links'], 'url'), fn (string $url) => str_starts_with($url, '/reports')));

        $this->hide('reports');

        $hidden = $this->chat('How much have we collected this month?');
        $this->assertSame($shown['answer'], $hidden['answer']);
        $this->assertSame([], array_filter(array_column($hidden['links'], 'url'), fn (string $url) => str_starts_with($url, '/reports')));
    }

    public function test_the_suggestions_and_what_linda_can_answer_leave_out_hidden_modules(): void
    {
        config()->set('linda.driver', 'none');
        $question = 'Can you sing for me?';

        $this->assertSame(LindaWording::unmatched(), $this->chat($question)['answer']);

        $this->hide('savings', 'share_capital', 'credit_scoring', 'loans.reminders', 'cda', 'accounting');
        $answer = $this->chat($question)['answer'];

        foreach (['savings', 'share capital', 'credit scoring', 'payment reminders', 'CDA', 'income', 'expenses'] as $topic) {
            $this->assertStringNotContainsString($topic, $answer);
        }
        $this->assertStringContainsString('How much have we collected this month?', $answer);

        $this->hide('loans');
        $answer = $this->chat($question)['answer'];
        $this->assertStringNotContainsString('How many active loans do we have?', $answer);
        $this->assertStringNotContainsString('disbursed', $answer);
        $this->assertStringContainsString('How much have we collected this month?', $answer);
    }

    public function test_the_not_yet_answer_names_no_hidden_module(): void
    {
        $question = 'Where is the moon calendar page?';
        $this->assertSame(LindaWording::notYet(), $this->chat($question)['answer']);

        $this->hide('savings', 'share_capital', 'cda', 'accounting');

        foreach ([$this->chat($question)['answer'], NotYetAnswer::for(LindaIntent::OpenQuestion, $this->asker())->answer] as $answer) {
            foreach (['savings', 'share capital', 'CDA', 'income', 'expenses'] as $module) {
                $this->assertStringNotContainsString($module, $answer);
            }

            $this->assertStringContainsString('collections', $answer);
        }
    }

    public function test_when_linda_herself_is_hidden_her_api_answers_404(): void
    {
        $this->hide('linda');

        $this->actingAs($this->asker())->postJson(self::URL, ['message' => 'How much have we collected this month?'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    }
}
