<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaPeriod;
use App\Services\Linda\LindaText;
use App\Services\Linda\Model\LindaModel;
use App\Services\Linda\Model\LindaModelFactory;
use App\Services\Linda\Model\LindaModelRequest;
use App\Services\Linda\Model\LindaModelUnavailable;
use App\Services\Linda\Model\LindaPrices;
use App\Services\Linda\Model\LindaUnderstanding;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scores Linda's question understanding on the synthetic question set
 * (tests/Fixtures/linda/evaluation.php), through the production flow: the
 * matcher first, and the model only when the matcher finds nothing.
 *
 * A follow-up without its own period or branch keeps the earlier question's,
 * and a question with no period whose intent defaults to this month gets this
 * month, as the answers do.
 *
 * ## It costs money on a live driver
 * The `anthropic` driver makes one paid call per model-handled case, so it
 * runs only with --confirm-spend. The `fake` driver and the `none` driver
 * never leave the server.
 *
 * ## It writes nothing
 * No database, no log row, no file: it prints the synthetic questions, the
 * scores, the tokens, the cost and the latency.
 */
#[Signature('linda:evaluate
    {--driver= : none, fake or anthropic (default: config linda.driver)}
    {--model= : The model id to ask (default: config linda.model)}
    {--confirm-spend : Required for the anthropic driver: each model-handled case is a paid call}
    {--limit= : Run at most this many cases}
    {--model-only : Skip the cases the matcher answers}')]
#[Description('Score Linda\'s question understanding on the synthetic question set. Writes nothing')]
class EvaluateLinda extends Command
{
    public const QUESTION_SET = 'tests/Fixtures/linda/evaluation.php';

    private const DRIVERS = ['none', 'fake', 'anthropic'];

    private const NOTHING = '—';

    public function handle(): int
    {
        $driver = (string) ($this->option('driver') ?? config('linda.driver', 'none'));
        $model = (string) ($this->option('model') ?? config('linda.model', 'claude-haiku-5-5'));
        $limit = $this->option('limit') === null ? null : max(0, (int) $this->option('limit'));

        if (! in_array($driver, self::DRIVERS, true)) {
            $this->error("Unknown driver \"{$driver}\". Use none, fake or anthropic.");

            return self::FAILURE;
        }

        if ($driver !== 'none' && ! LindaPrices::knows($model)) {
            $this->error("The model \"{$model}\" has no prices in config/linda.php.");

            return self::FAILURE;
        }

        if ($driver === 'anthropic') {
            if (! $this->option('confirm-spend')) {
                $this->error('The anthropic driver makes a paid call for each model-handled case. Pass --confirm-spend to run it.');

                return self::FAILURE;
            }

            if (trim((string) config('linda.anthropic.api_key')) === '') {
                $this->error('LINDA_ANTHROPIC_API_KEY is not set.');

                return self::FAILURE;
            }
        }

        /** @var list<array{question: string, earlier?: list<array{string, string}>, intent: string, period: string|array{from: string, to: string}|null, branch: ?string}> $cases */
        $cases = require base_path(self::QUESTION_SET);

        $this->evaluate($cases, LindaModelFactory::make($driver), $driver, $model, $limit, (bool) $this->option('model-only'));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{question: string, earlier?: list<array{string, string}>, intent: string, period: string|array{from: string, to: string}|null, branch: ?string}>  $cases
     */
    private function evaluate(array $cases, LindaModel $model, string $driver, string $modelId, ?int $limit, bool $modelOnly): void
    {
        $this->line("Driver: {$driver}".($driver === 'none' ? '' : ", model: {$modelId}"));

        $totals = ['run' => 0, 'matcher' => 0, 'model' => 0, 'none' => 0, 'intent' => 0, 'period' => 0, 'period_scored' => 0, 'branch' => 0, 'branch_scored' => 0, 'refusals' => 0];
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $errors = [];
        $latencies = [];
        $cost = BigDecimal::zero();

        foreach ($cases as $number => $case) {
            if ($limit !== null && $totals['run'] >= $limit) {
                break;
            }

            $earlier = array_map(fn (array $entry) => ['question' => $entry[0], 'intent' => $entry[1]], $case['earlier'] ?? []);
            $previous = $earlier === [] ? null : LindaIntent::from($earlier[count($earlier) - 1]['intent']);
            $match = LindaMatcher::match($case['question'], $previous);

            if ($modelOnly && $match !== null) {
                continue;
            }

            $totals['run']++;
            $actual = ['source' => 'matcher', 'intent' => null, 'period' => null, 'period_state' => null, 'branch' => null, 'follow_up' => false, 'error' => null];

            if ($match !== null) {
                $actual = ['intent' => $match->intent, 'period' => $match->period, 'branch' => $match->branchName, 'follow_up' => $match->followUp] + $actual;
            } else {
                $actual['source'] = 'model';
                $started = hrtime(true);
                $understanding = null;

                try {
                    $understanding = $model->understand(new LindaModelRequest($case['question'], $earlier, $modelId));
                } catch (LindaModelUnavailable $e) {
                    $actual['error'] = $e->reason;
                    $errors[$e->reason] = ($errors[$e->reason] ?? 0) + 1;
                }

                if ($understanding === null && $actual['error'] === null) {
                    $actual['source'] = 'none';
                } else {
                    $latencies[] = (hrtime(true) - $started) / 1e6;
                }

                if ($understanding instanceof LindaUnderstanding) {
                    $actual = $this->fromUnderstanding($understanding, $actual);
                    $totals['refusals'] += $understanding->refused ? 1 : 0;
                    $tokens['input'] += $understanding->usage->inputTokens;
                    $tokens['output'] += $understanding->usage->outputTokens;
                    $tokens['cache_read'] += $understanding->usage->cacheReadTokens;
                    $tokens['cache_write'] += $understanding->usage->cacheWriteTokens;
                    $cost = $cost->plus(LindaPrices::cost($understanding->usage));
                }
            }

            $totals[$actual['source']]++;
            $actual = $this->withCarriedContext($actual, $earlier);

            $score = $this->score($case, $actual);
            $totals['intent'] += $score['intent'] ? 1 : 0;
            if ($score['period'] !== null) {
                $totals['period_scored']++;
                $totals['period'] += $score['period'] ? 1 : 0;
                $totals['branch_scored']++;
                $totals['branch'] += $score['branch'] ? 1 : 0;
            }

            $passed = $score['intent'] && $score['period'] !== false && $score['branch'] !== false;
            $this->line(sprintf('%s %3d %-7s %s', $passed ? 'PASS' : 'FAIL', $number + 1, $actual['source'], $case['question']));

            if (! $passed) {
                $this->line('           expected: '.$this->describeExpected($case));
                $this->line('           actual:   '.$this->describeActual($actual));
            }
        }

        $this->newLine();
        $this->line("Cases run: {$totals['run']}");
        $this->line("Matcher-handled: {$totals['matcher']}");
        $this->line("Model-handled: {$totals['model']}");
        $this->line("Unanswered (no model): {$totals['none']}");
        $this->line('Intent accuracy: '.$this->ratio($totals['intent'], $totals['run']));
        $this->line('Period accuracy: '.$this->ratio($totals['period'], $totals['period_scored']));
        $this->line('Branch accuracy: '.$this->ratio($totals['branch'], $totals['branch_scored']));
        $this->line("Refusals: {$totals['refusals']}");
        $this->line('Errors: '.array_sum($errors).($errors === [] ? '' : ' ('.implode(', ', array_map(fn (string $reason, int $count) => "{$reason} {$count}", array_keys($errors), $errors)).')'));
        $this->line(sprintf('Tokens: %s input, %s output, %s cache read, %s cache write', ...array_map(fn (int $n) => number_format($n), array_values($tokens))));
        $this->line('Cost: ₱'.$cost->toScale(6, RoundingMode::HalfUp)->toString().' (₱'.$cost->toScale(2, RoundingMode::HalfUp)->toString().')');
        $this->line('Latency p50: '.$this->percentile($latencies, 50).', p95: '.$this->percentile($latencies, 95));
    }

    /**
     * @param  array<string, mixed>  $actual
     * @return array<string, mixed>
     */
    private function fromUnderstanding(LindaUnderstanding $understanding, array $actual): array
    {
        $actual['intent'] = $understanding->intent;
        $actual['period'] = $understanding->period;
        $actual['period_state'] = $understanding->periodRefused !== null ? 'refused' : ($understanding->periodUnclear ? 'unclear' : null);
        $actual['branch'] = $understanding->branchName;
        $actual['follow_up'] = $understanding->followUp;

        return $actual;
    }

    /**
     * A follow-up keeps the earlier question's branch when it names none, and
     * its period too when it keeps the earlier subject ("how about overdue?"
     * after a collections question asks about overdue as of today). A
     * question with no period whose intent defaults to this month gets this
     * month.
     *
     * @param  array<string, mixed>  $actual
     * @param  list<array{question: string, intent: string}>  $earlier
     * @return array<string, mixed>
     */
    private function withCarriedContext(array $actual, array $earlier): array
    {
        $intent = $actual['intent'];

        if (! $intent instanceof LindaIntent || $actual['period_state'] !== null) {
            return $actual;
        }

        if ($actual['follow_up']) {
            $sameSubject = $earlier !== [] && $earlier[count($earlier) - 1]['intent'] === $intent->value;

            foreach (array_reverse($earlier) as $entry) {
                if ($sameSubject) {
                    $actual['period'] ??= LindaPeriod::extract(LindaText::clean($entry['question']))['period'];
                }
                $actual['branch'] ??= LindaMatcher::match($entry['question'])?->branchName;
            }
        }

        if ($actual['period'] === null && $intent->defaultsToThisMonth()) {
            $actual['period'] = LindaPeriod::named('this_month');
        }

        return $actual;
    }

    /**
     * Whether the intent, period and branch are right. Period and branch are
     * null (not scored) for an expected unsupported question.
     *
     * @param  array{question: string, intent: string, period: string|array{from: string, to: string}|null, branch: ?string}  $case
     * @param  array<string, mixed>  $actual
     * @return array{intent: bool, period: ?bool, branch: ?bool}
     */
    private function score(array $case, array $actual): array
    {
        $intent = $actual['intent'] instanceof LindaIntent && $actual['intent']->value === $case['intent'];

        if ($case['intent'] === LindaIntent::UnsupportedQuestion->value) {
            return ['intent' => $intent, 'period' => null, 'branch' => null];
        }

        $expected = $case['period'];
        $period = match (true) {
            $expected === 'refused', $expected === 'unclear' => $actual['period_state'] === $expected,
            $expected === null => $actual['period'] === null && $actual['period_state'] === null,
            default => $actual['period'] instanceof LindaPeriod && $this->dates($expected) === [$actual['period']->from->toDateString(), $actual['period']->to->toDateString()],
        };

        return ['intent' => $intent, 'period' => $period, 'branch' => $actual['branch'] === $case['branch']];
    }

    /**
     * An expected period's dates in Manila, read at run time.
     *
     * @param  string|array{from: string, to: string}  $expected
     * @return array{string, string}
     */
    private function dates(string|array $expected): array
    {
        if (is_string($expected)) {
            $period = LindaPeriod::named($expected);

            return [$period->from->toDateString(), $period->to->toDateString()];
        }

        $today = LindaPeriod::today();

        return [$today->modify($expected['from'])->toDateString(), $today->modify($expected['to'])->toDateString()];
    }

    /**
     * @param  array{intent: string, period: string|array{from: string, to: string}|null, branch: ?string}  $case
     */
    private function describeExpected(array $case): string
    {
        $period = match (true) {
            $case['period'] === null => self::NOTHING,
            $case['period'] === 'refused', $case['period'] === 'unclear' => $case['period'],
            default => implode(' to ', $this->dates($case['period'])),
        };

        return implode(' · ', [$case['intent'], $period, $case['branch'] ?? self::NOTHING]);
    }

    /**
     * @param  array<string, mixed>  $actual
     */
    private function describeActual(array $actual): string
    {
        if ($actual['error'] !== null) {
            return 'model unavailable ('.$actual['error'].')';
        }

        if (! $actual['intent'] instanceof LindaIntent) {
            return 'no match';
        }

        $period = $actual['period_state'] ?? ($actual['period'] instanceof LindaPeriod
            ? $actual['period']->from->toDateString().' to '.$actual['period']->to->toDateString()
            : self::NOTHING);

        return implode(' · ', [$actual['intent']->value, $period, $actual['branch'] ?? self::NOTHING]);
    }

    private function ratio(int $right, int $of): string
    {
        return $of === 0 ? self::NOTHING : sprintf('%d/%d (%.1f%%)', $right, $of, $right / $of * 100);
    }

    /**
     * @param  list<float>  $milliseconds
     */
    private function percentile(array $milliseconds, int $percent): string
    {
        if ($milliseconds === []) {
            return self::NOTHING;
        }

        sort($milliseconds);
        $index = (int) ceil($percent / 100 * count($milliseconds)) - 1;

        return sprintf('%d ms', (int) round($milliseconds[max(0, $index)]));
    }
}
