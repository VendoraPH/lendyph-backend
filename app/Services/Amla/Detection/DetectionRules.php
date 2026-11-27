<?php

namespace App\Services\Amla\Detection;

use App\Models\AmlaRuleVersion;
use App\Services\Amla\Detection\Rules\CrossBranch;
use App\Services\Amla\Detection\Rules\GCashAnomalies;
use App\Services\Amla\Detection\Rules\IncomeMismatch;
use App\Services\Amla\Detection\Rules\LoanAnomalies;
use App\Services\Amla\Detection\Rules\MissingIdentity;
use App\Services\Amla\Detection\Rules\RapidMovement;
use App\Services\Amla\Detection\Rules\RepeatedDepositsWithdrawals;
use App\Services\Amla\Detection\Rules\Structuring;
use App\Services\Amla\Detection\Rules\ThirdParty;
use App\Services\Amla\Detection\Rules\UnusualFrequency;
use App\Services\Amla\Detection\Rules\UnusualVolume;
use App\Services\Amla\Detection\Rules\WatchlistMatch;
use App\Services\Amla\Foundation\AmlaSettings;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The AMLA detection rules A–L and their versions (AMLA design §4.7). The
 * rules themselves are code; a version sets which are active, each one's
 * priority and its parameters. Version 1 holds the documented starting
 * points, "to verify". A change goes through an approved `detection_rules`
 * change request (a second `amla:settings` holder) and adds a version; old
 * versions never change, and each alert cites its own.
 */
final class DetectionRules
{
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const NOTE = 'Each parameter is a starting point, not a legal threshold, and is still to be verified.';

    /** @var array<string, string> each parameter in words, for the rules screen */
    public const PARAMETER_LABELS = [
        'min_transactions' => 'Minimum transactions',
        'multiplier' => 'Times the subject\'s own average',
        'baseline_days' => 'Days of earlier activity compared',
        'min_amount' => 'Minimum amount',
        'min_baseline_transactions' => 'Minimum earlier transactions',
        'min_in_amount' => 'Minimum money in',
        'out_share_pct' => 'Share that leaves again (%)',
        'within_days' => 'Within days',
        'min_deposits' => 'Minimum deposits',
        'min_withdrawals' => 'Minimum withdrawals',
        'min_total' => 'Minimum total',
        'min_share_pct' => 'Minimum share of the release (%)',
        'channels' => 'Channels',
        'min_branches' => 'Minimum branches',
        'review_amount' => 'Review amount (above)',
    ];

    /** @var list<class-string<DetectionRule>> */
    private const RULES = [
        Structuring::class, UnusualFrequency::class, UnusualVolume::class, IncomeMismatch::class, RapidMovement::class,
        RepeatedDepositsWithdrawals::class, ThirdParty::class, GCashAnomalies::class, LoanAnomalies::class, CrossBranch::class,
        MissingIdentity::class, WatchlistMatch::class,
    ];

    /** @var array<string, array{0: int, 1: int}> each whole-number kind's limits */
    private const LIMITS = ['count' => [1, 10000], 'days' => [0, 730], 'multiplier' => [1, 100], 'percent' => [1, 100]];

    private const MAX_PESO_CENTAVOS = 999_999_999_999;

    /** @return array<string, DetectionRule> by code, A to L */
    public function all(): array
    {
        $rules = [];

        foreach (self::RULES as $class) {
            $rule = app($class);
            $rules[$rule->code()] = $rule;
        }

        return $rules;
    }

    public function rule(string $code): DetectionRule
    {
        return $this->all()[$code] ?? throw new RuntimeException("\"{$code}\" isn't an AMLA detection rule.");
    }

    public function current(): AmlaRuleVersion
    {
        return AmlaRuleVersion::query()->orderByDesc('version')->first()
            ?? throw new RuntimeException('The AMLA detection rules have no version. The AMLA detection migration writes version 1.');
    }

    /**
     * The version in force at a moment: the newest one effective by then, else
     * version 1 (the starting points were in force from the start). An event
     * is judged by the rules in force when it was recorded.
     */
    public function at(CarbonInterface $moment): AmlaRuleVersion
    {
        return AmlaRuleVersion::query()->where('effective_at', '<=', $moment)->orderByDesc('version')->first()
            ?? AmlaRuleVersion::query()->orderBy('version')->first()
            ?? throw new RuntimeException('The AMLA detection rules have no version. The AMLA detection migration writes version 1.');
    }

    public function version(int $version): AmlaRuleVersion
    {
        return AmlaRuleVersion::query()->where('version', $version)->first()
            ?? throw new RuntimeException("AMLA detection rule version {$version} doesn't exist.");
    }

    /**
     * What is wrong with a proposed definition, by field, in words: every rule
     * A–L and only those, each with `active` (G can never be), a priority and
     * exactly its parameters, each within its limits.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public function definitionErrors(array $definition): array
    {
        $rules = $this->all();
        $given = array_map('strval', array_keys($definition));
        sort($given);

        if ($given !== array_keys($rules)) {
            return ['definition' => 'List every detection rule, and only those: '.implode(', ', array_keys($rules)).'.'];
        }

        $errors = [];

        foreach ($rules as $code => $rule) {
            $entry = $definition[$code];
            $field = "definition.{$code}";

            if (! is_array($entry) || ! is_bool($entry['active'] ?? null) || ! in_array($entry['priority'] ?? null, self::PRIORITIES, true) || ! is_array($entry['params'] ?? null)) {
                $errors[$field] = "Rule {$code} needs active (yes or no), a priority (low, medium, high or critical) and its parameters.";

                continue;
            }

            if ($entry['active'] && ! $rule->canBeActive()) {
                $errors[$field] = "Rule {$code} ({$rule->words()}) can't be switched on: ".lcfirst((string) $rule->unavailable());

                continue;
            }

            $params = $entry['params'];
            $names = array_map('strval', array_keys($params));
            $expected = array_keys($rule->parameters());
            sort($names);
            sort($expected);

            if ($names !== $expected) {
                $errors[$field] = $expected === []
                    ? "Rule {$code} takes no parameters."
                    : "Rule {$code} takes exactly these parameters: ".implode(', ', array_keys($rule->parameters())).'.';

                continue;
            }

            foreach ($rule->parameters() as $name => $kind) {
                $problem = self::parameterProblem($kind, $params[$name]);

                if ($problem !== null) {
                    $errors["{$field}.params.{$name}"] = "Rule {$code}, {$name}: {$problem}";
                }
            }
        }

        return $errors;
    }

    /**
     * A valid definition in the canonical order: rules A–L, each active,
     * priority and params in the rule's own order, money as two-decimal
     * strings and channels in the settings' order.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, array{active: bool, priority: string, params: array<string, mixed>}>
     */
    public function normalise(array $definition): array
    {
        $normal = [];

        foreach ($this->all() as $code => $rule) {
            $params = [];

            foreach ($rule->parameters() as $name => $kind) {
                $value = $definition[$code]['params'][$name];
                $params[$name] = match ($kind) {
                    'peso' => sprintf('%d.%02d', intdiv(self::centavos($value), 100), self::centavos($value) % 100),
                    'channels' => array_values(array_intersect(AmlaSettings::CHANNELS, (array) $value)),
                    default => (int) $value,
                };
            }

            $normal[$code] = ['active' => (bool) $definition[$code]['active'], 'priority' => (string) $definition[$code]['priority'], 'params' => $params];
        }

        return $normal;
    }

    /**
     * The rules whose settings differ between two definitions.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<string>
     */
    public function changedRules(array $from, array $to): array
    {
        return array_values(array_filter(array_keys($this->all()), fn (string $code) => ($from[$code] ?? null) != ($to[$code] ?? null)));
    }

    private static function parameterProblem(string $kind, mixed $value): ?string
    {
        if ($kind === 'channels') {
            return is_array($value) && array_is_list($value) && $value !== [] && count($value) === count(array_unique($value))
                && array_diff($value, AmlaSettings::CHANNELS) === []
                ? null
                : 'list one or more of cash, GCash, Maya, bank transfer or online, each once.';
        }

        if ($kind === 'peso') {
            $centavos = self::centavos($value);

            return $centavos === null || $centavos <= 0 || $centavos > self::MAX_PESO_CENTAVOS
                ? 'give an amount above ₱0.00, with at most two decimals.'
                : null;
        }

        [$min, $max] = self::LIMITS[$kind];

        return is_int($value) && $value >= $min && $value <= $max ? null : "give a whole number from {$min} to {$max}.";
    }

    private static function centavos(mixed $value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = is_float($value) ? rtrim(rtrim(sprintf('%.2f', $value), '0'), '.') : (string) $value;

        if (preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $text, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }
}
