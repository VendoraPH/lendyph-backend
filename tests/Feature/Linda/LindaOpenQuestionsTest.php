<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\PeriodOpener;
use App\Services\Linda\LindaAnswerer;
use App\Services\Linda\LindaFacts;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaPrompt;
use App\Services\Linda\Model\LindaUsage;
use App\Services\Linda\Model\LindaWriteRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Linda round 3, open questions (LINDA_OPEN_QUESTIONS, off by default): a
 * question no fixed topic covers is written by the model from a pack of
 * summary figures (LindaFacts) built by the topic answers' own code, under
 * the caller's permissions and branches. The answer is shown only when every
 * number in it is in the pack and it holds no identifier; otherwise Linda
 * falls back. Lookups and named people never reach this path.
 */
class LindaOpenQuestionsTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private const OPEN = 'Why does our lending look like this overall?';

    private const COLLECTIONS = 'Why are our collections like this overall?';

    private const SCOPED = 'Why do the portfolio, share capital and active loans look like this overall?';

    private FakeModel $fake;

    private Borrower $ana;

    private Loan $loan;

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
        $this->ana->update(['contact_number' => '09171234567', 'email' => 'ana.reyes@example.com']);
        $this->loan = $this->releaseTo($this->ana, 60000);
        $this->pay($this->loan, 2500);
        $far = $this->member($this->far, 'Cora', 'Lim');
        $this->releaseTo($far, 30000);
    }

    private function asker(array $permissions = ['reports:view', 'loans:view', 'borrowers:view', 'savings:view', 'share_capital:view']): User
    {
        return $this->headOffice([...$permissions, 'linda:use']);
    }

    /** @return array<string, mixed> */
    private function chat(User $user, string $message): array
    {
        return $this->actingAs($user)->postJson(self::URL, ['message' => $message])->assertOk()->json('data');
    }

    /** The first figure in a pack, as shown there. */
    private static function firstFigure(LindaWriteRequest $request): string
    {
        return $request->facts['topics'][0]['figures'][0]['value'];
    }

    public function test_the_switch_off_changes_nothing(): void
    {
        config()->set('linda.open_questions', false);

        $reply = $this->chat($this->asker(), 'Why are our collections low this month?');

        $this->assertSame('collection_summary', $reply['intent']);
        $this->assertSame([], $this->fake->writeRequests);
        $this->assertStringNotContainsString('open_question', LindaPrompt::system());
        $this->assertNotContains('open_question', LindaPrompt::schema()['properties']['intent']['enum']);

        config()->set('linda.open_questions', true);
        $this->assertStringContainsString('open_question', LindaPrompt::system());
    }

    public function test_the_pack_holds_only_summary_figures(): void
    {
        $this->fake->writer = fn (LindaWriteRequest $request): string => 'Collections were '.self::firstFigure($request).'.';

        $this->chat($this->asker(), self::OPEN);

        $this->assertCount(1, $this->fake->writeRequests);
        $facts = $this->fake->writeRequests[0]->facts;
        $json = json_encode($facts, JSON_UNESCAPED_UNICODE);

        $this->assertSame(['as_of', 'branches', 'period', 'topics'], array_keys($facts));
        foreach (['Ana', 'Reyes', 'Cora', 'Lim', $this->ana->borrower_code, $this->loan->loan_account_number, $this->loan->application_number, '09171234567', 'ana.reyes@example.com'] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $json);
        }
        $this->assertNotEmpty($this->loan->loan_account_number);
        foreach (Repayment::query()->pluck('receipt_number') as $receipt) {
            $this->assertStringNotContainsString((string) $receipt, $json);
        }
        $this->assertFalse(LindaFacts::hasIdentifier($json));
        $this->assertSame(self::OPEN, $this->fake->writeRequests[0]->question);
    }

    public function test_a_good_answer_is_shown_and_both_calls_are_booked(): void
    {
        $this->fake->writer = fn (LindaWriteRequest $request): string => 'Collections were '.self::firstFigure($request).' this month.';

        $reply = $this->chat($this->asker(), self::COLLECTIONS);
        $figure = self::firstFigure($this->fake->writeRequests[0]);

        $this->assertSame('open_question', $reply['intent']);
        $this->assertSame('Collections were '.$figure.' this month.'."\n\n".LindaWording::writtenFromFigures(), $reply['answer']);
        $this->assertContains('/reports/daily_collection?'.http_build_query(['from' => now('Asia/Manila')->startOfMonth()->toDateString(), 'to' => now('Asia/Manila')->toDateString()]), array_column($reply['links'], 'url'));

        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame('used', $log->open_answer);
        $this->assertSame(2 * FakeModel::INPUT_TOKENS, $log->input_tokens);
        $this->assertSame(2 * FakeModel::OUTPUT_TOKENS, $log->output_tokens);
    }

    public function test_a_number_not_in_the_pack_falls_back(): void
    {
        $this->fake->writer = fn (): string => 'Collections were ₱987,654.32 this month.';

        $reply = $this->chat($this->asker(), self::OPEN);

        $this->assertSame(LindaWording::notYet(), $reply['answer']);
        $this->assertSame('fell_back', LindaQuestion::query()->latest('id')->firstOrFail()->open_answer);
    }

    public function test_an_identifier_falls_back(): void
    {
        foreach (['Ask about LN-000001 first.', 'Call 09171234567.', 'Email ana.reyes@example.com.', 'See BRW 0001.', 'Ana Reyes paid.'] as $extra) {
            $this->fake->writer = fn (LindaWriteRequest $request): string => 'Collections were '.self::firstFigure($request).'. '.$extra;

            $reply = $this->chat($this->asker(), self::OPEN);

            $this->assertSame(LindaWording::notYet(), $reply['answer'], $extra);
            $this->assertSame('fell_back', LindaQuestion::query()->latest('id')->firstOrFail()->open_answer, $extra);
        }
    }

    public function test_branch_scope_and_permissions_shape_the_pack(): void
    {
        $this->fake->writer = fn (LindaWriteRequest $request): string => 'Collections were '.self::firstFigure($request).'.';

        $this->chat($this->userWith(['reports:view', 'loans:view', 'linda:use'], $this->branch), self::SCOPED);
        $branchPack = $this->fake->writeRequests[0]->facts;
        $topics = array_column($branchPack['topics'], 'topic');

        $this->assertSame('Main Branch', $branchPack['branches']);
        $this->assertNotContains('savings_summary', $topics);
        $this->assertNotContains('share_capital_summary', $topics);
        $this->assertNotContains('cda_summary', $topics);
        $this->assertContains('portfolio_summary', $topics);

        $this->chat($this->asker(), self::SCOPED);
        $headPack = $this->fake->writeRequests[1]->facts;
        $this->assertSame('all branches', $headPack['branches']);
        $this->assertContains('share_capital_summary', array_column($headPack['topics'], 'topic'));

        $branchLoans = collect($branchPack['topics'])->firstWhere('topic', 'loan_summary')['figures'][0]['value'];
        $headLoans = collect($headPack['topics'])->firstWhere('topic', 'loan_summary')['figures'][0]['value'];
        $this->assertSame('1', $branchLoans);
        $this->assertSame('2', $headLoans);
    }

    public function test_lookups_and_named_people_never_reach_it(): void
    {
        foreach (['Why is Ana Reyes overdue?', 'Why is '.$this->loan->loan_account_number.' late?', 'Why is BRW-000001 inactive?'] as $question) {
            $this->chat($this->asker(), $question);
        }

        $this->assertSame([], $this->fake->requests);
        $this->assertSame([], $this->fake->writeRequests);
    }

    // -----------------------------------------------------------------
    // Security review of #327
    // -----------------------------------------------------------------

    public function test_only_the_topics_the_classifier_picks_are_built(): void
    {
        $this->chat($this->asker(), 'Why are our collections lower than our disbursements overall?');
        $this->assertSame(['collection_summary', 'disbursement_summary'], array_column($this->fake->writeRequests[0]->facts['topics'], 'topic'));

        // None picked: the lending summary alone, never every topic.
        $this->chat($this->asker(), self::OPEN);
        $this->assertSame(['general_lending_summary'], array_values(array_unique(array_column($this->fake->writeRequests[1]->facts['topics'], 'topic'))));
    }

    public function test_cda_is_left_out_when_a_branch_is_named(): void
    {
        app(PeriodOpener::class)->open(2026, $this->admin);
        $head = $this->asker(['reports:view', CdaAccess::VIEW]);

        $this->chat($head, 'Why are our CDA readiness and collections like this overall?');
        $this->assertSame(['collection_summary', 'cda_summary'], array_column($this->fake->writeRequests[0]->facts['topics'], 'topic'));

        $this->chat($head, 'Why are our CDA readiness and collections like this overall in Far branch?');
        $this->assertSame('Far Branch', $this->fake->writeRequests[1]->facts['branches']);
        $this->assertSame(['collection_summary'], array_column($this->fake->writeRequests[1]->facts['topics'], 'topic'));

        // CDA alone, with a branch named: nothing to write from, so it falls back.
        $reply = $this->chat($head, 'Why is our CDA readiness like this overall in Far branch?');
        $this->assertCount(2, $this->fake->writeRequests);
        $this->assertSame(LindaWording::notYet(), $reply['answer']);
    }

    public function test_the_second_cap_check_counts_the_first_calls_cost(): void
    {
        $classifier = LindaPrices::cost(new LindaUsage('claude-haiku-5-5', FakeModel::INPUT_TOKENS, FakeModel::OUTPUT_TOKENS));
        config()->set('linda.monthly_cap', '1.00');
        // Below the cap before the question, at or above it once the classifier's cost is added.
        LindaQuestion::query()->create([
            'user_id' => $this->admin->id, 'asked_at' => now(), 'source' => LindaQuestion::SOURCE_MODEL,
            'cost' => (string) BigDecimal::of('1.00')->minus(BigDecimal::of($classifier)->dividedBy(2, 6, RoundingMode::Down)),
        ]);

        $reply = $this->chat($this->asker(), self::COLLECTIONS);

        $this->assertCount(1, $this->fake->requests);
        $this->assertSame([], $this->fake->writeRequests);
        $this->assertSame(LindaWording::notYet(), $reply['answer']);
        $this->assertSame('fell_back', LindaQuestion::query()->latest('id')->firstOrFail()->open_answer);
    }

    public function test_a_failed_write_is_booked_at_the_write_calls_worst_case(): void
    {
        $this->fake->writeFailure = 'timeout';

        $this->chat($this->asker(), self::COLLECTIONS);

        $classifier = LindaPrices::cost(new LindaUsage('claude-haiku-5-5', FakeModel::INPUT_TOKENS, FakeModel::OUTPUT_TOKENS));
        $write = LindaPrices::worstCaseWriteEstimate('claude-haiku-5-5');
        $log = LindaQuestion::query()->latest('id')->firstOrFail();

        $this->assertTrue(BigDecimal::of($write)->isGreaterThan(LindaPrices::worstCaseEstimate('claude-haiku-5-5')));
        $this->assertSame('fell_back', $log->open_answer);
        $this->assertSame((string) BigDecimal::of($classifier)->plus($write)->toScale(6), (string) BigDecimal::of((string) $log->cost)->toScale(6));
    }

    // -----------------------------------------------------------------
    // Re-review of #327
    // -----------------------------------------------------------------

    public function test_each_stats_block_keeps_its_own_title_in_the_pack(): void
    {
        $this->chat($this->asker(), 'Why does our lending look like this overall last month?');

        $entries = $this->fake->writeRequests[0]->facts['topics'];
        $titleOf = fn (string $label): ?string => collect($entries)->first(fn (array $entry) => in_array($label, array_column($entry['figures'], 'label'), true))['title'] ?? null;
        $lastMonth = 'From '.LindaPeriod::named('last_month')->label;

        $this->assertSame(['general_lending_summary', 'general_lending_summary'], array_column($entries, 'topic'));
        $this->assertSame($lastMonth, $titleOf('Total Received'));
        $this->assertSame($lastMonth, $titleOf('Net Disbursed'));
        $this->assertSame(LindaWording::asOfToday(), $titleOf('Active Loans'));
        $this->assertSame(LindaWording::asOfToday(), $titleOf('Outstanding'));
        $this->assertSame(LindaWording::asOfToday(), $titleOf('Total Past Due'));
    }

    public function test_a_pack_that_cannot_be_encoded_falls_back_and_books_the_classifier(): void
    {
        $this->app->instance(LindaFacts::class, new class(app(LindaAnswerer::class)) extends LindaFacts
        {
            public function build(User $user, LindaPeriod $period, ?string $branchName, array $picked = []): array
            {
                $pack = parent::build($user, $period, $branchName, $picked);
                $pack['facts']['branches'] = "Main \xB1\x31";

                return $pack;
            }
        });

        $reply = $this->chat($this->asker(), self::COLLECTIONS);

        $log = LindaQuestion::query()->latest('id')->firstOrFail();
        $classifier = LindaPrices::cost(new LindaUsage('claude-haiku-5-5', FakeModel::INPUT_TOKENS, FakeModel::OUTPUT_TOKENS));

        $this->assertSame(LindaWording::notYet(), $reply['answer']);
        $this->assertSame([], $this->fake->writeRequests);
        $this->assertSame('fell_back', $log->open_answer);
        $this->assertSame(FakeModel::INPUT_TOKENS, $log->input_tokens);
        $this->assertSame((string) BigDecimal::of($classifier)->toScale(6), (string) BigDecimal::of((string) $log->cost)->toScale(6));
    }
}
