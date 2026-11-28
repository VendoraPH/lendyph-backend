<?php

/*
 * Writes layouts.json and domains.json, beside this file, from the frontend's
 * generated TypeScript (lendyph-frontend PR #699: src/lib/cic/layouts.generated.ts
 * and domains.generated.ts), which encodes CIC's 2016 CSDF workbooks
 * (`data format.xlsx` and `Fields in Excel.xlsx`). Run from the repo root:
 *
 *     php resources/cic/2016/generate.php path/to/layouts.generated.ts path/to/domains.generated.ts
 *
 * It reads only the JSON object lines inside each record or domain block, so
 * nothing in the TypeScript is ever run, and it stops on anything it can't
 * read. Every field gets the same keys (missing ones as null).
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php resources/cic/2016/generate.php <layouts.generated.ts> <domains.generated.ts>\n");
    exit(1);
}

/**
 * The JSON objects on one line ("{…}," or "{…}, {…},"), or null when the
 * line holds none.
 *
 * @return list<array<string, mixed>>|null
 */
function cicObjectsOn(string $line, int $number): ?array
{
    $trimmed = trim($line);

    if (! str_starts_with($trimmed, '{')) {
        return null;
    }

    $objects = json_decode('['.rtrim($trimmed, ',').']', true);

    if (! is_array($objects)) {
        fwrite(STDERR, "Line {$number} isn't readable JSON.\n");
        exit(1);
    }

    return $objects;
}

/**
 * Each block ("  HD: [" or "  \"GenderDomain\": [") and its objects, in order.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function cicBlocks(string $path, string $blockPattern): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        fwrite(STDERR, "Can't read {$path}.\n");
        exit(1);
    }

    $blocks = [];
    $current = null;

    foreach ($lines as $index => $line) {
        if (preg_match($blockPattern, $line, $match) === 1) {
            $current = $match[1];
            $blocks[$current] = [];

            continue;
        }

        if (preg_match('/^\s*\],?\s*$/', $line) === 1) {
            $current = null;

            continue;
        }

        $objects = cicObjectsOn($line, $index + 1);

        if ($objects === null) {
            continue;
        }

        if ($current === null) {
            fwrite(STDERR, 'Line '.($index + 1)." is outside any block.\n");
            exit(1);
        }

        array_push($blocks[$current], ...$objects);
    }

    return $blocks;
}

$layouts = [];
$total = 0;

foreach (cicBlocks($argv[1], '/^\s{2}([A-Z]{2}): \[$/') as $record => $fields) {
    foreach ($fields as $position => $field) {
        $expected = $record.($position + 1);

        if (($field['id'] ?? null) !== $expected) {
            fwrite(STDERR, "{$record} field ".($position + 1)." should be {$expected}.\n");
            exit(1);
        }

        $layouts[$record][] = [
            'id' => $field['id'],
            'name' => (string) $field['name'],
            'type' => (string) $field['type'],
            'classification' => (string) $field['classification'],
            'maxLength' => (int) $field['maxLength'],
            'domain' => $field['domain'] ?? null,
            'fixed' => $field['fixed'] ?? null,
            'rule' => $field['rule'] ?? null,
        ];
    }

    $total += count($fields);
}

$domains = [];

foreach (cicBlocks($argv[2], '/^\s{2}"([^"]+)": \[$/') as $domain => $codes) {
    $domains[$domain] = array_map(fn (array $code) => [
        'code' => (string) $code['code'],
        'label' => isset($code['label']) ? (string) $code['label'] : null,
    ], $codes);
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
file_put_contents(__DIR__.'/layouts.json', json_encode($layouts, $flags)."\n");
file_put_contents(__DIR__.'/domains.json', json_encode($domains, $flags)."\n");

$codes = array_sum(array_map('count', $domains));
fwrite(STDOUT, count($layouts)." records, {$total} fields; ".count($domains)." domains, {$codes} codes.\n");
