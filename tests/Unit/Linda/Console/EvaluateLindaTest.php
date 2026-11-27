<?php

namespace Tests\Unit\Linda\Console;

use App\Console\Commands\EvaluateLinda;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookup;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaNameCheck;
use App\Services\Linda\Model\AnthropicModel;
use App\Services\Linda\Model\FakeModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Tests\Unit\Linda\LindaTestCase;
use Tests\Unit\Linda\Model\LindaConfig;

/**
 * `linda:evaluate`: the question set through the production flow (the
 * matcher first, the model only when it finds nothing), scored, and never a
 * paid call without --confirm-spend.
 */
class EvaluateLindaTest extends LindaTestCase
{
    use LindaConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atManila('2026-11-16 09:00');
        $this->setLindaConfig('fake');
    }

    public function test_a_live_driver_is_refused_without_confirm_spend(): void
    {
        $this->app->bind(AnthropicModel::class, fn () => throw new LogicException('The Anthropic model was built.'));

        $this->artisan('linda:evaluate', ['--driver' => 'anthropic'])
            ->expectsOutputToContain('--confirm-spend')
            ->assertFailed();
    }

    public function test_the_anthropic_driver_without_a_key_is_refused_before_any_call(): void
    {
        config()->set('linda.anthropic.api_key', null);
        $this->app->bind(AnthropicModel::class, fn () => throw new LogicException('The Anthropic model was built.'));

        $this->artisan('linda:evaluate', ['--driver' => 'anthropic', '--confirm-spend' => true])
            ->expectsOutputToContain('LINDA_ANTHROPIC_API_KEY')
            ->assertFailed();
    }

    public function test_a_model_without_prices_is_refused(): void
    {
        $this->artisan('linda:evaluate', ['--model' => 'claude-mystery-9'])
            ->expectsOutputToContain('claude-mystery-9')
            ->assertFailed();
    }

    public function test_a_question_set_outside_the_fixtures_is_refused(): void
    {
        $this->artisan('linda:evaluate', ['--set' => '.env'])
            ->expectsOutputToContain('tests/Fixtures/linda/')
            ->assertFailed();
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->artisan('linda:evaluate', ['--driver' => 'chat_completions'])->assertFailed();
    }

    public function test_it_runs_green_on_the_fake_driver_and_writes_nothing(): void
    {
        $fake = new FakeModel;
        $this->app->instance(FakeModel::class, $fake);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->artisan('linda:evaluate')
            ->expectsOutputToContain('Matcher-handled')
            ->expectsOutputToContain('Model-handled')
            ->expectsOutputToContain('Intent accuracy')
            ->expectsOutputToContain('Period accuracy')
            ->expectsOutputToContain('Branch accuracy')
            ->expectsOutputToContain('Cost: ₱')
            ->expectsOutputToContain('Latency p50')
            ->assertSuccessful();

        $this->assertSame(0, $queries);
        $this->assertNotEmpty($fake->requests);
    }

    public function test_the_model_only_sees_what_the_matcher_could_not_answer(): void
    {
        $fake = new FakeModel;
        $this->app->instance(FakeModel::class, $fake);
        $cases = require base_path('tests/Fixtures/linda/evaluation.php');

        $this->artisan('linda:evaluate')->assertSuccessful();

        $sent = array_map(fn ($request) => $request->question, $fake->requests);
        $this->assertNotContains('How much have we collected this month?', $sent);
        $this->assertNotContains('What is our collection efficiency this month?', $sent);
        $this->assertNotContains('Pila ang nakolekta karong bulana?', $sent);
        $this->assertContains('How much did we earn in penalties this year?', $sent);
        $this->assertLessThan(count($cases), count($fake->requests));
    }

    public function test_model_only_skips_the_matcher_cases_and_limit_stops_early(): void
    {
        $fake = new FakeModel;
        $this->app->instance(FakeModel::class, $fake);

        $this->artisan('linda:evaluate', ['--model-only' => true, '--limit' => 5])
            ->expectsOutputToContain('Cases run: 5')
            ->assertSuccessful();

        $this->assertCount(5, $fake->requests);
    }

    public function test_the_none_driver_scores_the_matcher_alone(): void
    {
        $this->artisan('linda:evaluate', ['--driver' => 'none'])
            ->expectsOutputToContain('Model-handled: 0')
            ->assertSuccessful();
    }

    public function test_model_failures_are_counted_not_fatal_and_never_print_more_than_the_code(): void
    {
        $this->app->instance(FakeModel::class, FakeModel::failingWith('overloaded'));
        Log::spy();

        $this->artisan('linda:evaluate', ['--model-only' => true, '--limit' => 5])
            ->expectsOutputToContain('Errors: ')
            ->expectsOutputToContain('overloaded')
            ->assertSuccessful();
    }

    /**
     * A follow-up that names no branch keeps the one an earlier follow-up
     * named ("how about sa Riverside?"), as the endpoint does: each earlier
     * question is read with its own intent.
     */
    public function test_a_branch_an_earlier_follow_up_named_carries_on(): void
    {
        $cases = require base_path(EvaluateLinda::QUESTION_SET);
        $number = collect($cases)->search(fn (array $case) => $case['question'] === 'What about yesterday?'
            && ($case['earlier'][1][0] ?? null) === 'how about sa Riverside?');

        $this->assertIsInt($number);

        $this->artisan('linda:evaluate', ['--driver' => 'none'])
            ->expectsOutputToContain(sprintf('PASS %3d %-7s %s', $number + 1, 'matcher', 'What about yesterday?'))
            ->assertSuccessful();
    }

    /**
     * The follow-up rule of the money review (M1), the endpoint's: a
     * follow-up keeps the period and branch of the question it follows, never
     * an older one's.
     */
    public function test_a_follow_up_keeps_the_question_it_follows_not_an_older_one(): void
    {
        $cases = require base_path(EvaluateLinda::QUESTION_SET);
        $find = fn (string $question, string $earlier) => collect($cases)->search(fn (array $case) => $case['question'] === $question
            && (end($case['earlier']) ?: [null])[0] === $earlier);

        $thisMonth = $find('how about sa Riverside?', 'How much did we collect?');
        $allBranches = $find('how about last month?', 'How much have we disbursed?');
        $this->assertIsInt($thisMonth);
        $this->assertIsInt($allBranches);

        $this->artisan('linda:evaluate', ['--driver' => 'none'])
            ->expectsOutputToContain(sprintf('PASS %3d %-7s %s', $thisMonth + 1, 'matcher', 'how about sa Riverside?'))
            ->expectsOutputToContain(sprintf('PASS %3d %-7s %s', $allBranches + 1, 'matcher', 'how about last month?'))
            ->assertSuccessful();
    }

    /**
     * Lookups the matcher misses end at the local lookup parser, as they do
     * on the endpoint, and never reach the model: no member name, record
     * number, email or phone number in the question or in earlier questions.
     */
    public function test_lookups_end_at_the_lookup_parser_and_never_reach_the_model(): void
    {
        $fake = new FakeModel;
        $this->app->instance(FakeModel::class, $fake);
        $cases = require base_path(EvaluateLinda::QUESTION_SET);
        $number = collect($cases)->search(fn (array $case) => $case['question'] === 'Find receipt RCP-000123');
        $bisaya = collect($cases)->search(fn (array $case) => $case['question'] === 'Unsa ang utang ni Juan Dela Cruz?');
        $this->assertIsInt($bisaya);

        $this->artisan('linda:evaluate')
            ->expectsOutputToContain(sprintf('PASS %3d %-7s %s', $number + 1, 'lookup', 'Find receipt RCP-000123'))
            ->expectsOutputToContain(sprintf('PASS %3d %-7s %s', $bisaya + 1, 'lookup', 'Unsa ang utang ni Juan Dela Cruz?'))
            ->expectsOutputToContain('Lookup-handled: ')
            ->expectsOutputToContain('Lookup target accuracy: ')
            ->expectsOutputToContain('Lookups sent to the model: 0')
            ->assertSuccessful();

        $targets = [];
        foreach ($cases as $case) {
            foreach ($case['target'] ?? [] as $value) {
                $targets[] = $value;
            }
        }
        $this->assertNotEmpty($targets);

        foreach ($fake->requests as $request) {
            $sent = serialize($request);
            foreach ($targets as $target) {
                $this->assertStringNotContainsString($target, $sent);
            }
            $this->assertStringNotContainsString('juan.delacruz', $sent);
            $this->assertStringNotContainsString('0917', $sent);
        }
    }

    /**
     * Every lookup case in the question set, and every case naming one of its
     * people, is answered before the model (by the lookup parser, a reply to
     * a lookup turn or the full-name check), and no other case is: the
     * matcher answers it or the parser leaves it alone. Checked here without
     * running the command, in the endpoint's order.
     */
    public function test_no_lookup_case_reaches_the_model_and_no_other_case_is_caught(): void
    {
        $cases = require base_path(EvaluateLinda::QUESTION_SET);
        $lookups = ['borrower_lookup', 'loan_details', 'transaction_lookup'];

        foreach ($cases as $case) {
            $earlier = $case['earlier'] ?? [];
            $topics = array_values(array_filter(array_map(fn (array $entry) => LindaIntent::from($entry[1]), $earlier), fn (LindaIntent $intent) => ! $intent->isLookup()));
            $previous = $topics === [] ? null : end($topics);
            $pending = $earlier === [] ? null : LindaIntent::from($earlier[count($earlier) - 1][1])->lookupKind();
            $match = LindaMatcher::match($case['question'], $previous);
            $request = LindaLookup::parse($case['question']) ?? ($pending === null ? null : LindaLookup::reply($case['question'], $pending));
            $people = $case['people'] ?? [];
            $named = LindaNameCheck::find($case['question'], fn (): array => array_map(
                fn (array $person): array => $person[0] === LindaNameCheck::WALK_IN
                    ? ['kind' => $person[0], 'full_name' => $person[1].' '.$person[2]]
                    : ['kind' => $person[0], 'first_name' => $person[1], 'middle_name' => null, 'last_name' => $person[2]],
                $people,
            ));

            if ($people !== []) {
                $this->assertNull($match, $case['question']);
                $this->assertTrue($request !== null || $named !== null, $case['question']);

                continue;
            }

            if (in_array($case['intent'], $lookups, true)) {
                $this->assertNull($match, $case['question']);
                $this->assertSame($case['intent'], $request?->intent->value, $case['question']);
                if (array_key_exists('target', $case)) {
                    $this->assertSame($case['target']['code'] ?? null, $request->code, $case['question']);
                    $this->assertSame($case['target']['name'] ?? null, $request->name, $case['question']);
                }

                continue;
            }

            $this->assertTrue($match !== null || $request === null, $case['question']);
        }
    }

    /**
     * A lookup case the parser misses would go to the model: the run fails.
     */
    public function test_a_lookup_that_would_reach_the_model_fails_the_run(): void
    {
        $fake = new FakeModel;
        $this->app->instance(FakeModel::class, $fake);

        $this->artisan('linda:evaluate', ['--set' => 'tests/Fixtures/linda/evaluation-lookup-leak.php'])
            ->expectsOutputToContain('Lookups sent to the model: 1')
            ->assertFailed();
    }

    public function test_it_prints_the_accuracy_for_each_language(): void
    {
        $this->artisan('linda:evaluate', ['--driver' => 'none'])
            ->expectsOutputToContain('English: ')
            ->expectsOutputToContain('Tagalog/Taglish: ')
            ->expectsOutputToContain('Bisaya/Cebuano: ')
            ->expectsOutputToContain('Mixed: ')
            ->assertSuccessful();
    }

    public function test_the_question_set_is_well_formed(): void
    {
        $cases = require base_path('tests/Fixtures/linda/evaluation.php');

        $this->assertGreaterThanOrEqual(250, count($cases));
        $languages = array_count_values(array_column($cases, 'language'));
        $this->assertGreaterThanOrEqual(60, $languages['ceb'] ?? 0);
        $this->assertGreaterThanOrEqual(5, $languages['mixed'] ?? 0);
        $this->assertSame(count($cases), array_sum($languages));
        $lookups = array_filter($cases, fn (array $case) => in_array($case['intent'], ['borrower_lookup', 'loan_details', 'transaction_lookup'], true));
        $this->assertGreaterThanOrEqual(30, count($lookups));
        $newIntents = array_filter($cases, fn (array $case) => in_array($case['intent'], ['collection_efficiency', 'branch_performance', 'officer_performance'], true));
        $this->assertGreaterThanOrEqual(20, count($newIntents));

        foreach ($cases as $n => $case) {
            $this->assertIsString($case['question'], "case {$n}");
            $this->assertNotNull(LindaIntent::tryFrom($case['intent']), "case {$n}");
            foreach ($case['earlier'] ?? [] as [$question, $intent]) {
                $this->assertIsString($question, "case {$n}");
                $this->assertNotNull(LindaIntent::tryFrom($intent), "case {$n}");
            }
            $this->assertArrayHasKey('period', $case, "case {$n}");
            $this->assertArrayHasKey('branch', $case, "case {$n}");
            $this->assertContains($case['language'] ?? null, ['en', 'tl', 'ceb', 'mixed'], "case {$n}");
        }
    }
}
