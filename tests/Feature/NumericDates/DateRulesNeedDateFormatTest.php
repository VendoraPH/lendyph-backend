<?php

namespace Tests\Feature\NumericDates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Laravel's `date` rule accepts a number (20261124) and bare digits
 * ("20261124"), which a model's date cast then stores as a Unix timestamp
 * (1970-08-23). The comparison rules (`after:`, `before:`, `after_or_equal:`,
 * `before_or_equal:`, `date_equals:`) accept them too, since Carbon reads a
 * number as a timestamp. Every validation rule list under app/Http
 * (FormRequests and inline `validate()` calls in controllers) that uses one of
 * them must also carry a `date_format`, so a date is only ever accepted in a
 * written form.
 *
 * The check reads the source with PHP's tokenizer. A field's rules are:
 * - the strings in its array (`'x' => [...]`), each one rule or a
 *   pipe-separated list, including an array in either branch of a ternary
 *   and one passed as a named argument (`validate(rules: [...])`);
 * - `Rule::date()`, a date rule unless its chain calls `->format(`;
 * - the rule arguments of `Rule::when($condition, $rules, $default)` and of a
 *   validator's `->sometimes($field, $rules, $callback)`;
 * - an assignment to one key (`$rules['x'] = 'required|date'`), up to the end
 *   of its statement.
 * The field passes when its rules hold any `date_format:`. Lists passed to
 * `Rule::in()`, `in_array()` and similar calls, and the strings in OpenAPI
 * attributes, are not rule lists and are skipped.
 */
class DateRulesNeedDateFormatTest extends TestCase
{
    /** Calls whose array arguments are values, never rules. */
    private const VALUE_CALLS = ['in', 'notin', 'in_array', 'only', 'except', 'has', 'filled', 'input'];

    /** Rules that read a number or bare digits as a date. */
    private const DATE_RULE = '/^(date|(after|before|after_or_equal|before_or_equal|date_equals):.*)$/';

    public function test_every_date_rule_under_app_http_carries_a_date_format(): void
    {
        $root = dirname(__DIR__, 3).'/app/Http';
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = 'app/Http/'.substr($file->getPathname(), strlen($root) + 1);

            foreach (self::datesWithoutFormat((string) file_get_contents($file->getPathname())) as ['line' => $line, 'field' => $field]) {
                $offenders[] = "{$relative}:{$line} {$field}";
            }
        }

        sort($offenders, SORT_NATURAL);

        $this->assertSame([], $offenders, sprintf(
            "%d rule lists use a date rule without `date_format` (a number or bare digits pass it):\n%s",
            count($offenders),
            implode("\n", $offenders),
        ));
    }

    /**
     * Each form the guard must catch, as a field it flags.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function formsThatAcceptDigits(): array
    {
        return [
            'a plain date rule' => ["return ['x' => ['nullable', 'date']];", ['x']],
            'a pipe-separated date rule' => ["return ['x' => 'nullable|date'];", ['x']],
            'Rule::date() without a format' => ["return ['x' => ['required', Rule::date()]];", ['x']],
            'Rule::date() with a comparison but no format' => ["return ['x' => ['required', Rule::date()->afterToday()]];", ['x']],
            'a fully qualified Rule::date()' => ["return ['x' => [\\Illuminate\\Validation\\Rule::date()]];", ['x']],
            'an array in a ternary\'s else branch' => ["return ['x' => \$c ? ['prohibited'] : ['nullable', 'date']];", ['x']],
            'an array in a ternary\'s then branch' => ["return ['x' => \$c ? ['nullable', 'date'] : ['prohibited']];", ['x']],
            'a named rules argument' => ["\$request->validate(rules: ['x' => ['date']]);", ['x']],
            'a bare string in Rule::when' => ["return ['x' => ['nullable', Rule::when(\$c, 'date')]];", ['x']],
            'the default branch of Rule::when' => ["return ['x' => ['nullable', Rule::when(\$c, ['integer'], 'date')]];", ['x']],
            'a validator\'s sometimes()' => ["\$validator->sometimes('x', 'date', fn (\$input) => true);", ['x']],
            'an assignment to one key' => ["\$rules['x'] = 'required|date';", ['x']],
            'an assignment of a bare date rule' => ["\$rules['x'] = 'date';", ['x']],
            'an assignment of a ternary' => ["\$rules['x'] = \$c ? 'date' : 'nullable';", ['x']],
            'after: alone' => ["return ['x' => ['nullable', 'after:now']];", ['x']],
            'before: alone' => ["return ['x' => ['before:2027-01-01']];", ['x']],
            'after_or_equal: alone' => ["return ['x' => 'nullable|after_or_equal:start'];", ['x']],
            'before_or_equal: alone' => ["return ['x' => ['before_or_equal:today']];", ['x']],
            'date_equals: alone' => ["return ['x' => ['date_equals:today']];", ['x']],
            'only the field without a format' => ["return ['a' => ['date', 'date_format:Y-m-d'], 'b' => ['date']];", ['b']],
        ];
    }

    /** @param  list<string>  $flagged */
    #[DataProvider('formsThatAcceptDigits')]
    public function test_the_guard_flags_a_form_that_accepts_digits(string $body, array $flagged): void
    {
        $this->assertSame($flagged, array_column(self::datesWithoutFormat(self::source($body)), 'field'));
    }

    /**
     * The same forms written with a format, and code that only looks like a
     * rule, which the guard must leave alone.
     *
     * @return array<string, array{string}>
     */
    public static function formsThatPass(): array
    {
        return [
            'date with a format' => ["return ['x' => ['nullable', 'date', 'date_format:Y-m-d', 'after:now']];"],
            'a pipe-separated rule with a format' => ["return ['x' => 'nullable|date|date_format:Y-m-d'];"],
            'Rule::date() with a format' => ["return ['x' => ['required', Rule::date()->format('Y-m-d')->afterToday()]];"],
            'a ternary with a format' => ["return ['x' => \$c ? ['prohibited'] : ['nullable', 'date', 'date_format:Y-m-d']];"],
            'a named argument with a format' => ["\$request->validate(rules: ['x' => ['date', 'date_format:Y-m-d']]);"],
            'Rule::when with a format beside it' => ["return ['x' => ['nullable', 'date_format:Y-m-d', Rule::when(\$c, 'after:now')]];"],
            'sometimes() with a format' => ["\$validator->sometimes('x', 'date|date_format:Y-m-d', fn (\$input) => \$input->kind === 'date');"],
            'an assignment with a format' => ["\$rules['x'] = 'required|date|date_format:Y-m-d';"],
            'a query builder when() on a date column' => ["\$q->when(filled(\$d), fn (\$q) => \$q->where('date', '>=', \$d));"],
            'data written to a date key' => ["\$data['date'] = \$value;\n\$other = 'date';"],
            'a value list' => ["return ['kind' => ['required', Rule::in(['date', 'after:now'])]];"],
            'an in_array check' => ["if (in_array(\$type, ['date', 'datetime'], true)) { return []; }"],
        ];
    }

    #[DataProvider('formsThatPass')]
    public function test_the_guard_leaves_a_formatted_or_non_rule_form_alone(string $body): void
    {
        $this->assertSame([], self::datesWithoutFormat(self::source($body)));
    }

    private static function source(string $body): string
    {
        return "<?php\n\nclass Sample\n{\n    public function rules(\$request, \$validator, \$q, \$c, \$d, \$value, \$type): array\n    {\n        {$body}\n    }\n}\n";
    }

    /**
     * The fields in one source file whose rules use a date rule without a
     * `date_format`.
     *
     * @return list<array{line: int, field: string}>
     */
    private static function datesWithoutFormat(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token) => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));
        $kind = fn ($token) => is_array($token) ? $token[0] : $token;
        $name = fn ($token) => is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            ? strtolower($token[1])
            : null;
        $isRuleClass = fn ($token) => in_array($name($token), ['rule', 'illuminate\\validation\\rule', '\\illuminate\\validation\\rule'], true);
        $objectOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

        $frame = fn (bool $array, ?string $anchor, bool $skip, ?string $ruleCall = null) => [
            'array' => $array,
            'anchor' => $anchor,
            'keyAnchor' => null,
            'statementAnchor' => null,
            'skip' => $skip,
            'ruleCall' => $ruleCall,
            'arg' => 0,
            'ownsAnchor' => false,
            'dateChain' => null,
        ];

        $stack = [$frame(false, null, false)];
        /** @var array<string, array{line: int, field: string, date: bool, format: bool}> $fields */
        $fields = [];
        $line = 1;

        $newField = function (string $field, int $line) use (&$fields): string {
            $key = "{$field}@{$line}#".count($fields);
            $fields[$key] = ['line' => $line, 'field' => $field, 'date' => false, 'format' => false];

            return $key;
        };

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                $line = $token[2];
            }

            $current = $kind($token);
            $previous = $i > 0 ? $kind($tokens[$i - 1]) : null;
            $top = count($stack) - 1;
            $anchor = $stack[$top]['keyAnchor'] ?? $stack[$top]['statementAnchor'] ?? $stack[$top]['anchor'];

            // Rule::date(): a date rule, until its chain calls ->format(.
            if ($name($token) === 'date' && $previous === T_DOUBLE_COLON && $isRuleClass($tokens[$i - 2] ?? null)) {
                if ($anchor !== null && ! $stack[$top]['skip']) {
                    $fields[$anchor]['date'] = true;
                    $stack[$top]['dateChain'] = $anchor;
                }

                continue;
            }

            if ($name($token) === 'format' && in_array($previous, $objectOperators, true) && $stack[$top]['dateChain'] !== null) {
                $fields[$stack[$top]['dateChain']]['format'] = true;

                continue;
            }

            if (in_array($current, ['[', '(', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $isArray = $current === '[' && in_array($previous, ['(', ',', '[', '=', '+', T_DOUBLE_ARROW, T_RETURN, T_ELLIPSIS, '?', ':', T_COALESCE], true);
                $call = $current === '(' ? $name($tokens[$i - 1] ?? null) : null;
                $ruleCall = match (true) {
                    $call === 'when' && $kind($tokens[$i - 2] ?? null) === T_DOUBLE_COLON && $isRuleClass($tokens[$i - 3] ?? null) => 'when',
                    $call === 'sometimes' && in_array($kind($tokens[$i - 2] ?? null), $objectOperators, true) => 'sometimes',
                    default => null,
                };

                if ($isArray && $anchor === null && in_array($previous, ['=', T_RETURN], true)) {
                    $anchor = $newField('(rule list)', $line);
                }

                $owns = false;

                if ($ruleCall !== null && $anchor === null) {
                    $anchor = $newField("{$ruleCall}()", $line);
                    $owns = true;
                }

                $child = $frame(
                    $isArray,
                    in_array($current, ['{', T_CURLY_OPEN], true) ? null : $anchor,
                    $stack[$top]['skip'] || in_array($call, self::VALUE_CALLS, true),
                    $ruleCall,
                );
                $child['ownsAnchor'] = $owns;
                $stack[] = $child;

                continue;
            }

            if (in_array($current, [']', ')', '}'], true)) {
                if (count($stack) > 1) {
                    array_pop($stack);
                }

                continue;
            }

            if ($current === ',') {
                $stack[$top]['dateChain'] = null;

                if ($stack[$top]['array']) {
                    $stack[$top]['keyAnchor'] = null;
                }

                if ($stack[$top]['ruleCall'] !== null) {
                    $stack[$top]['arg']++;
                }

                continue;
            }

            if ($current === ';') {
                $stack[$top]['statementAnchor'] = null;
                $stack[$top]['dateChain'] = null;

                continue;
            }

            // $rules['x'] = ...: the statement's strings are the rules of x.
            if ($current === '='
                && $kind($tokens[$i - 1] ?? null) === ']'
                && $kind($tokens[$i - 2] ?? null) === T_CONSTANT_ENCAPSED_STRING
                && $kind($tokens[$i - 3] ?? null) === '['
                && in_array($kind($tokens[$i - 4] ?? null), [T_VARIABLE, T_STRING], true)) {
                $stack[$top]['statementAnchor'] = $newField(substr($tokens[$i - 2][1], 1, -1), $line);

                continue;
            }

            if ($current !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = substr($token[1], 1, -1);
            $frameNow = $stack[$top];

            if ($kind($tokens[$i + 1] ?? null) === T_DOUBLE_ARROW && $frameNow['array']) {
                $stack[$top]['keyAnchor'] = $newField($value, $line);

                continue;
            }

            if ($frameNow['skip'] || $anchor === null) {
                continue;
            }

            if ($frameNow['ruleCall'] === 'sometimes' && $frameNow['arg'] === 0) {
                if ($frameNow['ownsAnchor']) {
                    $fields[$anchor]['field'] = $value;
                }

                continue;
            }

            $readAsRules = $frameNow['array']
                || str_contains($value, '|')
                || ($frameNow['ruleCall'] === 'when' && $frameNow['arg'] >= 1)
                || ($frameNow['ruleCall'] === 'sometimes' && $frameNow['arg'] === 1)
                || $frameNow['statementAnchor'] !== null;

            if (! $readAsRules) {
                continue;
            }

            foreach (explode('|', $value) as $part) {
                if (preg_match(self::DATE_RULE, $part) === 1) {
                    $fields[$anchor]['date'] = true;
                }

                if (str_starts_with($part, 'date_format')) {
                    $fields[$anchor]['format'] = true;
                }
            }
        }

        return array_values(array_map(
            fn (array $field) => ['line' => $field['line'], 'field' => $field['field']],
            array_filter($fields, fn (array $field) => $field['date'] && ! $field['format']),
        ));
    }
}
