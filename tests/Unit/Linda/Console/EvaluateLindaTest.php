<?php

namespace Tests\Unit\Linda\Console;

use App\Console\Commands\EvaluateLinda;
use App\Services\Linda\LindaIntent;
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
        $this->assertContains('What is our collection efficiency this month?', $sent);
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

    public function test_the_question_set_is_well_formed(): void
    {
        $cases = require base_path('tests/Fixtures/linda/evaluation.php');

        $this->assertGreaterThanOrEqual(140, count($cases));

        foreach ($cases as $n => $case) {
            $this->assertIsString($case['question'], "case {$n}");
            $this->assertNotNull(LindaIntent::tryFrom($case['intent']), "case {$n}");
            foreach ($case['earlier'] ?? [] as [$question, $intent]) {
                $this->assertIsString($question, "case {$n}");
                $this->assertNotNull(LindaIntent::tryFrom($intent), "case {$n}");
            }
            $this->assertArrayHasKey('period', $case, "case {$n}");
            $this->assertArrayHasKey('branch', $case, "case {$n}");
        }
    }
}
