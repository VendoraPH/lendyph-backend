<?php

namespace Tests\Feature\Linda;

use App\Models\LindaQuestion;
use App\Models\User;
use App\Services\Linda\LindaLinks;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\MenuVisibility\MenuVisibility;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Linda's "How do I…" and "Where is…" answers for CIC Reporting: each page
 * shows only with the permissions its page and API need (every one needs
 * `branches:view_all`), none while the CIC switch is off or Menu Visibility
 * hides `cic`, and the questions are answered on the server, never by the model.
 */
class LindaCicHowToTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private const TURNED_OFF = 'That part of the app is turned off here.';

    /** Every CIC link, by its LindaLinks key. */
    private const CIC_PAGES = [
        'cic_readiness' => '/cic',
        'cic_subjects' => '/cic/subjects',
        'cic_contracts' => '/cic/contracts',
        'cic_issues' => '/cic/issues',
        'cic_generate' => '/cic/generate',
        'cic_corrections' => '/cic/corrections',
        'cic_submissions' => '/cic/submissions',
        'cic_settings' => '/settings/cic',
    ];

    private FakeModel $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'fake');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.monthly_cap', '1000.00');
        config()->set('linda.usd_to_php', '62.00');
        config()->set('cic.enabled', true);
        $this->fake = new FakeModel;
        $this->app->instance(FakeModel::class, $this->fake);
    }

    /** @return array<string, mixed> */
    private function chat(User $user, string $message): array
    {
        return $this->actingAs($user)->postJson(self::URL, ['message' => $message])->assertOk()->json('data') ?? [];
    }

    private function cicAll(): User
    {
        return $this->headOffice(['linda:use', 'cic:view', 'cic:prepare', 'cic:settings']);
    }

    /** @return list<string> the CIC links $user gets */
    private function cicLinks(User $user): array
    {
        return array_column(LindaLinks::for($user, ...array_keys(self::CIC_PAGES)), 'url');
    }

    public function test_each_link_shows_only_with_its_permissions(): void
    {
        $this->assertSame(array_values(self::CIC_PAGES), $this->cicLinks($this->cicAll()));

        $viewer = $this->headOffice(['cic:view']);
        $this->assertSame(['/cic', '/cic/subjects', '/cic/contracts', '/cic/issues', '/cic/corrections', '/cic/submissions'], $this->cicLinks($viewer));

        $this->assertSame(['/cic/generate'], $this->cicLinks($this->headOffice(['cic:prepare'])));
        $this->assertSame(['/settings/cic'], $this->cicLinks($this->headOffice(['cic:settings'])));

        // Every CIC page covers the whole lender: a branch user gets none.
        $this->assertSame([], $this->cicLinks($this->branchUser(['cic:view', 'cic:prepare', 'cic:settings'])));
        $this->assertSame([], $this->cicLinks($this->headOffice(['reports:view'])));
    }

    public function test_no_link_while_cic_is_switched_off_or_hidden(): void
    {
        $user = $this->cicAll();

        config()->set('cic.enabled', false);
        $this->assertSame([], $this->cicLinks($user));

        config()->set('cic.enabled', true);
        app(MenuVisibility::class)->save(['cic'], User::query()->where('username', 'super_admin')->firstOrFail());
        $this->assertSame([], $this->cicLinks($user));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function questions(): array
    {
        $submit = ['/cic/generate', '/cic/submissions'];

        return [
            'en submit to cic' => ['How do I submit to CIC?', $submit],
            'en where is cic' => ['Where is CIC?', ['/cic']],
            'en cic report' => ['Where is the CIC report?', ['/cic/generate']],
            'en credit information' => ['Where is credit information?', ['/cic']],
            'en cic settings' => ['Where are the CIC settings?', ['/settings/cic']],
            'tl paano mag-submit sa cic' => ['Paano mag-submit sa CIC?', $submit],
            'tl saan ang cic report' => ['Saan makikita ang CIC report?', ['/cic/generate']],
            'ceb asa ang cic' => ['Asa ang CIC?', ['/cic']],
            'ceb unsaon pag-submit sa cic' => ['Unsaon pag-submit sa CIC?', $submit],
        ];
    }

    /** @param  list<string>  $urls */
    #[DataProvider('questions')]
    public function test_the_phrasings_link_their_page_without_the_model(string $question, array $urls): void
    {
        $reply = $this->chat($this->cicAll(), $question);

        $this->assertSame('how_to', $reply['intent'], $question);
        $this->assertSame($urls, array_column($reply['links'], 'url'), $question);
        $this->assertSame([], $this->fake->requests, $question);

        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame('matcher', $log->source);
        $this->assertSame('HowToAnswer', $log->answer_function);
        $this->assertSame('answered', $log->outcome);
    }

    public function test_the_answer_names_the_menu_place(): void
    {
        $reply = $this->chat($this->cicAll(), 'How do I submit to CIC?');

        $this->assertSame('You\'ll find that in two places in the side menu: CIC Reporting → Generate Report and CIC Reporting → Submission History.', $reply['answer']);
        $this->assertSame([['label' => 'Open Generate Report', 'url' => '/cic/generate'], ['label' => 'Open Submission History', 'url' => '/cic/submissions']], $reply['links']);
    }

    public function test_a_page_the_caller_cant_open_is_left_out_or_refused(): void
    {
        // A viewer without cic:prepare gets only Submission History.
        $viewer = $this->headOffice(['linda:use', 'cic:view']);
        $reply = $this->chat($viewer, 'How do I submit to CIC?');
        $this->assertSame('Go to CIC Reporting → Submission History in the side menu.', $reply['answer']);
        $this->assertSame(['/cic/submissions'], array_column($reply['links'], 'url'));

        // A branch user holding every CIC key still can't open a CIC page.
        $branch = $this->branchUser(['linda:use', 'cic:view', 'cic:prepare', 'cic:settings']);
        $denied = $this->chat($branch, 'Where is CIC?');
        $this->assertSame(LindaWording::menuDenied(), $denied['answer']);
        $this->assertSame([], $denied['links']);
        $this->assertSame([], $this->fake->requests);
    }

    public function test_none_while_cic_is_switched_off(): void
    {
        config()->set('cic.enabled', false);

        foreach (['Where is CIC?', 'How do I submit to CIC?', 'Where are the CIC settings?', 'Asa ang CIC?'] as $question) {
            $reply = $this->chat($this->cicAll(), $question);
            $this->assertSame(self::TURNED_OFF, $reply['answer'], $question);
            $this->assertSame([], $reply['links'], $question);
        }

        $this->assertSame([], $this->fake->requests);
    }

    public function test_none_while_menu_visibility_hides_cic(): void
    {
        $user = $this->cicAll();
        $this->assertSame(['/cic'], array_column($this->chat($user, 'Where is CIC?')['links'], 'url'));

        app(MenuVisibility::class)->save(['cic'], User::query()->where('username', 'super_admin')->firstOrFail());

        foreach (['Where is CIC?', 'Paano mag-submit sa CIC?', 'Where are the CIC settings?'] as $question) {
            $reply = $this->chat($user, $question);
            $this->assertSame(self::TURNED_OFF, $reply['answer'], $question);
            $this->assertSame([], $reply['links'], $question);
        }

        // The CDA pages, shown, are unchanged.
        $cda = $this->headOffice(['linda:use', 'cda:view']);
        $this->assertSame(['/cda/submissions'], array_column($this->chat($cda, 'Where is the submission history?')['links'], 'url'));
        $this->assertSame([], $this->fake->requests);
    }
}
