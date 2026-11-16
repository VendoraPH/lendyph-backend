<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Linda\LindaFollowUp;
use App\Services\Linda\LindaIntent;
use App\Services\Linda\LindaLookup;
use App\Services\Linda\LindaLookupRequest;
use App\Services\Linda\LindaMatcher;
use App\Services\Linda\LindaNameCheck;
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
use Throwable;

/**
 * Scores Linda's question understanding on the synthetic question set
 * (tests/Fixtures/linda/evaluation.php), through the production flow: the
 * matcher first, then the local lookup parser (LindaLookup, lookups end
 * there and are answered on the server), then, right after a lookup turn, a
 * short reply naming its target (LindaLookup::reply()), then the full-name
 * check (LindaNameCheck), and the model only when none answers. The model is
 * sent the question and the earlier turns' topics (intents) only, never
 * their text.
 *
 * ## The full-name check reads the case, not the database
 * A case may list the deployment's `people` the check knows (each
 * [kind, first name, last name], kind member or staff); the check reads
 * only those, so the run stays synthetic and reads no database. It runs
 * whatever the driver: with the none driver nothing reaches a model anyway.
 *
 * ## A lookup that would reach the model fails the run
 * A case that expects a lookup intent, or names one of its `people`, but
 * would be sent to the model fails the whole run (a non-zero exit), whatever
 * the driver: no member or staff name or record number may ever reach the
 * model. A lookup case may also give the
 * code or name the parser must pull out (`target`), scored as "Lookup
 * target accuracy".
 *
 * ## Languages
 * Each case names its language (en, tl, ceb or mixed), and the run prints
 * the share of cases passed in each.
 *
 * A follow-up keeps the period and branch of the question it follows
 * (LindaFollowUp, the endpoint's rule), a question with no period whose
 * intent defaults to this month gets this month, and overdue and active loans
 * take no period, as the answers do.
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
    {--model-only : Skip the cases the matcher and the lookup parser answer}
    {--set= : Another question set under tests/Fixtures/linda/ (default: the full set)}')]
#[Description('Score Linda\'s question understanding on the synthetic question set. Writes nothing')]
class EvaluateLinda extends Command
{
    public const QUESTION_SET = 'tests/Fixtures/linda/evaluation.php';

    private const DRIVERS = ['none', 'fake', 'anthropic'];

    private const NOTHING = '—';

    private const LOOKUP_INTENTS = ['borrower_lookup', 'loan_details', 'transaction_lookup'];

    private const LANGUAGES = ['en' => 'English', 'tl' => 'Tagalog/Taglish', 'ceb' => 'Bisaya/Cebuano', 'mixed' => 'Mixed'];

    private const SET_FOLDER = 'tests/Fixtures/linda/';

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

        $set = (string) ($this->option('set') ?? self::QUESTION_SET);

        if (! str_starts_with($set, self::SET_FOLDER) || str_contains($set, '..') || ! str_ends_with($set, '.php') || ! is_file(base_path($set))) {
            $this->error('The question set must be a PHP file under '.self::SET_FOLDER.'.');

            return self::FAILURE;
        }

        /** @var list<array{question: string, earlier?: list<array{string, string}>, intent: string, period: string|array{from: string, to: string}|null, branch: ?string, language?: string, target?: array{code?: string, name?: string}}> $cases */
        $cases = require base_path($set);

        $leaks = $this->evaluate($cases, LindaModelFactory::make($driver), $driver, $model, $limit, (bool) $this->option('model-only'));

        return $leaks === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{question: string, earlier?: list<array{string, string}>, intent: string, period: string|array{from: string, to: string}|null, branch: ?string, language?: string, target?: array{code?: string, name?: string}}>  $cases
     * @return int the lookup cases that would have been sent to the model
     */
    private function evaluate(array $cases, LindaModel $model, string $driver, string $modelId, ?int $limit, bool $modelOnly): int
    {
        $this->line("Driver: {$driver}".($driver === 'none' ? '' : ", model: {$modelId}"));

        $totals = ['run' => 0, 'matcher' => 0, 'lookup' => 0, 'name' => 0, 'model' => 0, 'none' => 0, 'intent' => 0, 'period' => 0, 'period_scored' => 0, 'branch' => 0, 'branch_scored' => 0, 'refusals' => 0, 'target' => 0, 'target_scored' => 0];
        $languages = array_fill_keys(array_keys(self::LANGUAGES), ['run' => 0, 'passed' => 0]);
        $leaks = [];
        $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];
        $errors = [];
        $latencies = [];
        $cost = BigDecimal::zero();

        foreach ($cases as $number => $case) {
            if ($limit !== null && $totals['run'] >= $limit) {
                break;
            }

            $earlier = array_map(fn (array $entry) => ['question' => $entry[0], 'intent' => $entry[1]], $case['earlier'] ?? []);
            // As on the endpoint (LindaHistory): a follow-up carries on the
            // latest topic that wasn't a lookup.
            $topics = array_values(array_filter(array_map(fn (array $entry) => LindaIntent::from($entry['intent']), $earlier), fn (LindaIntent $intent) => ! $intent->isLookup()));
            $previous = $topics === [] ? null : $topics[count($topics) - 1];
            $match = LindaMatcher::match($case['question'], $previous);

            if ($modelOnly && $match !== null) {
                continue;
            }

            $lookup = $match === null ? LindaLookup::parse($case['question']) : null;
            $pending = $earlier === [] ? null : LindaIntent::tryFrom($earlier[count($earlier) - 1]['intent'])?->lookupKind();

            if ($match === null && $lookup === null && $pending !== null) {
                $lookup = LindaLookup::reply($case['question'], $pending);
            }

            $named = $match === null && $lookup === null ? LindaNameCheck::find($case['question'], self::people($case['people'] ?? [])) : null;

            if ($modelOnly && ($lookup !== null || $named !== null)) {
                continue;
            }

            $totals['run']++;
            $actual = ['source' => 'matcher', 'intent' => null, 'period' => null, 'period_state' => null, 'branch' => null, 'follow_up' => false, 'error' => null];

            if ($match !== null) {
                $actual = ['intent' => $match->intent, 'period' => $match->period, 'branch' => $match->branchName, 'follow_up' => $match->followUp] + $actual;
            } elseif ($lookup !== null) {
                $actual = ['source' => 'lookup', 'intent' => $lookup->intent] + $actual;
            } elseif ($named !== null) {
                $actual = ['source' => 'name', 'intent' => self::namedIntent($named), 'period' => $named->kind === LindaNameCheck::STAFF && $named->performance ? LindaPeriod::extract(LindaText::clean($case['question']))['period'] : null] + $actual;
            } else {
                $actual['source'] = 'model';
                $started = hrtime(true);
                $understanding = null;

                try {
                    $understanding = $model->understand(new LindaModelRequest($case['question'], array_column($earlier, 'intent'), $modelId));
                } catch (LindaModelUnavailable $e) {
                    $actual['error'] = $e->reason;
                    $errors[$e->reason] = ($errors[$e->reason] ?? 0) + 1;
                } catch (Throwable) {
                    $actual['error'] = 'internal';
                    $errors['internal'] = ($errors['internal'] ?? 0) + 1;
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

            $target = $this->scoreTarget($case, $lookup);
            if ($target !== null) {
                $totals['target_scored']++;
                $totals['target'] += $target ? 1 : 0;
            }

            // A lookup, or a case that names one of its `people`, must stay local.
            if ((in_array($case['intent'], self::LOOKUP_INTENTS, true) || ($case['people'] ?? []) !== []) && in_array($actual['source'], ['model', 'none'], true)) {
                $leaks[] = $number + 1;
            }

            $passed = $score['intent'] && $score['period'] !== false && $score['branch'] !== false && $target !== false;
            $this->line(sprintf('%s %3d %-7s %s', $passed ? 'PASS' : 'FAIL', $number + 1, $actual['source'], $case['question']));

            $language = $case['language'] ?? 'en';
            if (isset($languages[$language])) {
                $languages[$language]['run']++;
                $languages[$language]['passed'] += $passed ? 1 : 0;
            }

            if (! $passed) {
                $this->line('           expected: '.$this->describeExpected($case));
                $this->line('           actual:   '.$this->describeActual($actual));
            }
        }

        $this->newLine();
        $this->line("Cases run: {$totals['run']}");
        $this->line("Matcher-handled: {$totals['matcher']}");
        $this->line("Lookup-handled: {$totals['lookup']}");
        $this->line("Name-check-handled: {$totals['name']}");
        $this->line("Model-handled: {$totals['model']}");
        $this->line("Unanswered (no model): {$totals['none']}");
        $this->line('Intent accuracy: '.$this->ratio($totals['intent'], $totals['run']));
        $this->line('Period accuracy: '.$this->ratio($totals['period'], $totals['period_scored']));
        $this->line('Branch accuracy: '.$this->ratio($totals['branch'], $totals['branch_scored']));
        $this->line('Lookup target accuracy: '.$this->ratio($totals['target'], $totals['target_scored']));
        $this->line('Passed by language:');
        foreach (self::LANGUAGES as $key => $label) {
            $this->line("  {$label}: ".$this->ratio($languages[$key]['passed'], $languages[$key]['run']));
        }
        $this->line("Refusals: {$totals['refusals']}");
        $this->line('Errors: '.array_sum($errors).($errors === [] ? '' : ' ('.implode(', ', array_map(fn (string $reason, int $count) => "{$reason} {$count}", array_keys($errors), $errors)).')'));
        $this->line(sprintf('Tokens: %s input, %s output, %s cache read, %s cache write', ...array_map(fn (int $n) => number_format($n), array_values($tokens))));
        $this->line('Cost: ₱'.$cost->toScale(6, RoundingMode::HalfUp)->toString().' (₱'.$cost->toScale(2, RoundingMode::HalfUp)->toString().')');
        $this->line('Latency p50: '.$this->percentile($latencies, 50).', p95: '.$this->percentile($latencies, 95));
        $this->line('Lookups sent to the model: '.count($leaks));

        if ($leaks !== []) {
            $this->error('Lookup cases that would reach the model: '.implode(', ', $leaks).'. No lookup may ever be sent to the model.');
        }

        return count($leaks);
    }

    /**
     * The full-name check's candidates for a case: only the people it lists.
     *
     * @param  list<array{string, string, string}>  $people  [kind, first name, last name]
     * @return \Closure(list<string>): list<array{kind: string, first_name: string, middle_name: null, last_name: string}>
     */
    private static function people(array $people): \Closure
    {
        return fn (array $lastNames): array => array_values(array_filter(
            array_map(fn (array $person): array => ['kind' => $person[0], 'first_name' => $person[1], 'middle_name' => null, 'last_name' => $person[2]], $people),
            fn (array $person): bool => in_array(mb_strtolower($person['last_name']), $lastNames, true),
        ));
    }

    /** The intent the endpoint answers a full-name hit with. */
    private static function namedIntent(LindaNameCheck $named): LindaIntent
    {
        return match (true) {
            $named->kind === LindaNameCheck::MEMBER => LindaIntent::BorrowerLookup,
            $named->performance => LindaIntent::OfficerPerformance,
            default => LindaIntent::UnsupportedQuestion,
        };
    }

    /**
     * Whether the lookup parser pulled out the code and name a lookup case
     * expects; null when the case gives none or the parser didn't answer it.
     *
     * @param  array{target?: array{code?: string, name?: string}}  $case
     */
    private function scoreTarget(array $case, ?LindaLookupRequest $lookup): ?bool
    {
        if (! array_key_exists('target', $case) || $lookup === null) {
            return null;
        }

        return ($case['target']['code'] ?? null) === $lookup->code && ($case['target']['name'] ?? null) === $lookup->name;
    }

    /**
     * @param  array<string, mixed>  $actual
     * @return array<string, mixed>
     */
    private function fromUnderstanding(LindaUnderstanding $understanding, array $actual): array
    {
        $actual['intent'] = $understanding->intent;
        // Overdue and active loans are as of today: their period words are
        // ignored, never refused or called unclear, as on the endpoint.
        $takesPeriod = $understanding->intent->takesPeriod();
        $actual['period'] = $takesPeriod ? $understanding->period : null;
        $actual['period_state'] = ! $takesPeriod ? null : ($understanding->periodRefused !== null ? 'refused' : ($understanding->periodUnclear ? 'unclear' : null));
        $actual['branch'] = $understanding->branchName;
        $actual['follow_up'] = $understanding->followUp;

        return $actual;
    }

    /**
     * The endpoint's follow-up rule (LindaFollowUp): a follow-up keeps the
     * period and branch of the question it follows, a question with no period
     * whose intent defaults to this month gets this month, and an intent that
     * takes no period has none.
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

        [$actual['period'], $actual['branch']] = LindaFollowUp::carry($intent, $actual['period'], $actual['branch'], (bool) $actual['follow_up'], $earlier);

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
