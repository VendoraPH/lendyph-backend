<?php

namespace Tests\Feature\NumericDates;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Laravel's `date` rule accepts a number (20261124) and bare digits
 * ("20261124"), which a model's date cast then stores as a Unix timestamp
 * (1970-08-23). Every validation rule list under app/Http (FormRequests and
 * inline `validate()` calls in controllers) that uses `date` must also carry a
 * `date_format`, so a date is only ever accepted in a written form.
 *
 * The check reads the source with PHP's tokenizer: a string `date` (alone or
 * as one part of a pipe-separated rule string) inside a rule list counts, and
 * the list passes when the same field's rules hold any `date_format:`. Lists
 * passed to `Rule::in()`, `in_array()` and similar calls, and the strings in
 * OpenAPI attributes, are not rule lists and are skipped.
 */
class DateRulesNeedDateFormatTest extends TestCase
{
    /** Calls whose array arguments are values, never rules. */
    private const VALUE_CALLS = ['in', 'notin', 'in_array', 'only', 'except', 'has', 'filled', 'input'];

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

            foreach ($this->datesWithoutFormat($file->getPathname()) as ['line' => $line, 'field' => $field]) {
                $offenders[] = "{$relative}:{$line} {$field}";
            }
        }

        sort($offenders, SORT_NATURAL);

        $this->assertSame([], $offenders, sprintf(
            "%d rule lists use `date` without `date_format` (a number or bare digits pass `date`):\n%s",
            count($offenders),
            implode("\n", $offenders),
        ));
    }

    /**
     * The fields in one file whose rules use `date` without a `date_format`.
     *
     * @return list<array{line: int, field: string}>
     */
    private function datesWithoutFormat(string $path): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            fn ($token) => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)),
        ));
        $kind = fn ($token) => is_array($token) ? $token[0] : $token;

        /** @var list<array{array: bool, anchor: ?string, keyAnchor: ?string, skip: bool}> $stack */
        $stack = [['array' => false, 'anchor' => null, 'keyAnchor' => null, 'skip' => false]];
        /** @var array<string, array{line: int, field: string, date: bool, format: bool}> $fields */
        $fields = [];
        $line = 1;

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                $line = $token[2];
            }

            $current = $kind($token);
            $previous = $i > 0 ? $kind($tokens[$i - 1]) : null;
            $top = count($stack) - 1;
            $anchor = $stack[$top]['keyAnchor'] ?? $stack[$top]['anchor'];

            if (in_array($current, ['[', '(', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $isArray = $current === '[' && in_array($previous, ['(', ',', '[', '=', '+', T_DOUBLE_ARROW, T_RETURN, T_ELLIPSIS, '?', T_COALESCE], true);
                $call = $current === '(' && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_STRING
                    ? strtolower($tokens[$i - 1][1])
                    : null;

                if ($isArray && $anchor === null && in_array($previous, ['=', T_RETURN], true)) {
                    $anchor = "?@{$line}";
                    $fields[$anchor] = ['line' => $line, 'field' => '(rule list)', 'date' => false, 'format' => false];
                }

                $stack[] = [
                    'array' => $isArray,
                    'anchor' => in_array($current, ['{', T_CURLY_OPEN], true) ? null : $anchor,
                    'keyAnchor' => null,
                    'skip' => $stack[$top]['skip'] || in_array($call, self::VALUE_CALLS, true),
                ];

                continue;
            }

            if (in_array($current, [']', ')', '}'], true)) {
                if (count($stack) > 1) {
                    array_pop($stack);
                }

                continue;
            }

            if ($current === ',' && $stack[$top]['array']) {
                $stack[$top]['keyAnchor'] = null;

                continue;
            }

            if ($current !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = substr($token[1], 1, -1);

            if ($kind($tokens[$i + 1] ?? null) === T_DOUBLE_ARROW && $stack[$top]['array']) {
                $key = "{$value}@{$line}";
                $fields[$key] = ['line' => $line, 'field' => $value, 'date' => false, 'format' => false];
                $stack[$top]['keyAnchor'] = $key;

                continue;
            }

            if ($stack[$top]['skip'] || $anchor === null) {
                continue;
            }

            if (! $stack[$top]['array'] && ! str_contains($value, '|')) {
                continue;
            }

            $parts = explode('|', $value);

            if (in_array('date', $parts, true)) {
                $fields[$anchor]['date'] = true;
            }

            foreach ($parts as $part) {
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
