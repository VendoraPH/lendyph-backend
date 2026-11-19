<?php

namespace App\Services\Amla\Screening;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

/**
 * Reads an uploaded watchlist (AMLA design §4.11): the UN Security Council
 * consolidated list in its own XML, or the CSV template used for AMLC
 * designations and internal lists. Nothing is fetched: the file is the only
 * source. A file that can't be read is refused in words, with each problem
 * row named, and nothing is kept.
 *
 * The XML is read with network access off and no document type allowed, so
 * a file can't pull in outside entities or expand itself.
 */
final class WatchlistParser
{
    /** @var list<string> the CSV template's columns, in order */
    public const CSV_COLUMNS = ['reference', 'type', 'full_name', 'aliases', 'birth_year', 'nationality'];

    public const MAX_ENTRIES = 100_000;

    /** How many row problems are listed before the rest are counted. */
    private const MAX_ERRORS = 20;

    /**
     * @return array{label: string|null, entries: list<array{reference: string, kind: string, full_name: string, aliases: list<string>, birth_years: list<int>, nationalities: list<string>}>}
     *
     * @throws ValidationException
     */
    public function un(string $xml): array
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            self::refuse(['The file declares its own document type, which a UN list never does, so it isn\'t read.']);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($root === false) {
            self::refuse(['The file isn\'t a readable UN consolidated list (its XML is broken).']);
        }

        if ($root->getName() !== 'CONSOLIDATED_LIST') {
            self::refuse(['The file isn\'t a UN consolidated list: it has no CONSOLIDATED_LIST.']);
        }

        $entries = [];

        foreach ($root->INDIVIDUALS->INDIVIDUAL ?? [] as $person) {
            $name = self::joined([$person->FIRST_NAME, $person->SECOND_NAME, $person->THIRD_NAME, $person->FOURTH_NAME]);

            if ($name === '') {
                continue;
            }

            $years = [];

            foreach ($person->INDIVIDUAL_DATE_OF_BIRTH ?? [] as $birth) {
                foreach ([(string) $birth->DATE, (string) $birth->YEAR, (string) $birth->FROM_YEAR, (string) $birth->TO_YEAR] as $value) {
                    if (preg_match('/^(\d{4})/', trim($value), $year) === 1) {
                        $years[] = (int) $year[1];
                    }
                }
            }

            $entries[] = [
                'reference' => self::reference($person),
                'kind' => 'individual',
                'full_name' => $name,
                'aliases' => self::aliases($person->INDIVIDUAL_ALIAS ?? []),
                'birth_years' => self::years($years),
                'nationalities' => self::texts($person->NATIONALITY->VALUE ?? []),
            ];
        }

        foreach ($root->ENTITIES->ENTITY ?? [] as $entity) {
            $name = self::joined([$entity->FIRST_NAME]);

            if ($name === '') {
                continue;
            }

            $entries[] = [
                'reference' => self::reference($entity),
                'kind' => 'entity',
                'full_name' => $name,
                'aliases' => self::aliases($entity->ENTITY_ALIAS ?? []),
                'birth_years' => [],
                'nationalities' => [],
            ];
        }

        $label = trim((string) ($root['dateGenerated'] ?? ''));

        return ['label' => $label === '' ? null : mb_substr($label, 0, 150), 'entries' => self::checked($entries)];
    }

    /**
     * @return array{label: null, entries: list<array{reference: string, kind: string, full_name: string, aliases: list<string>, birth_years: list<int>, nationalities: list<string>}>}
     *
     * @throws ValidationException
     */
    public function csv(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            self::refuse(['The file must be UTF-8 text.']);
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream, escape: '');

        if ($header === false || array_map(fn ($column) => mb_strtolower(trim((string) $column)), $header) !== self::CSV_COLUMNS) {
            fclose($stream);
            self::refuse(['The first line must be the template\'s columns: '.implode(', ', self::CSV_COLUMNS).'.']);
        }

        $entries = [];
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $line++;

            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }

            $row = array_pad(array_map(fn ($value) => trim((string) $value), $row), count(self::CSV_COLUMNS), '');
            [$reference, $type, $name, $aliases, $birthYear, $nationality] = $row;
            $rowErrors = [];

            if ($reference === '') {
                $rowErrors[] = "Row {$line}: the reference is missing.";
            }

            if ($name === '') {
                $rowErrors[] = "Row {$line}: the full name is missing.";
            }

            if (! in_array(mb_strtolower($type), ['individual', 'entity'], true)) {
                $rowErrors[] = "Row {$line}: the type must be individual or entity.";
            }

            if ($birthYear !== '' && preg_match('/^\d{4}$/', $birthYear) !== 1) {
                $rowErrors[] = "Row {$line}: the birth year must be four digits.";
            }

            if ($rowErrors !== []) {
                array_push($errors, ...$rowErrors);

                continue;
            }

            $entries[] = [
                'reference' => mb_substr($reference, 0, 100),
                'kind' => mb_strtolower($type),
                'full_name' => mb_substr($name, 0, 255),
                'aliases' => array_values(array_filter(array_map('trim', explode('|', $aliases)), fn (string $alias) => $alias !== '')),
                'birth_years' => $birthYear === '' ? [] : [(int) $birthYear],
                'nationalities' => $nationality === '' ? [] : [$nationality],
            ];
        }

        fclose($stream);

        if ($errors !== []) {
            $shown = array_slice($errors, 0, self::MAX_ERRORS);

            if (count($errors) > self::MAX_ERRORS) {
                $shown[] = 'And '.(count($errors) - self::MAX_ERRORS).' more problems.';
            }

            self::refuse($shown);
        }

        return ['label' => null, 'entries' => self::checked($entries)];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function checked(array $entries): array
    {
        if ($entries === []) {
            self::refuse(['The list has no entries.']);
        }

        if (count($entries) > self::MAX_ENTRIES) {
            self::refuse(['The list has more than '.number_format(self::MAX_ENTRIES).' entries.']);
        }

        return $entries;
    }

    /** @param  list<string>  $messages */
    private static function refuse(array $messages): never
    {
        throw ValidationException::withMessages(['file' => $messages]);
    }

    private static function reference(SimpleXMLElement $node): string
    {
        $reference = trim((string) ($node->REFERENCE_NUMBER ?? ''));

        return mb_substr($reference !== '' ? $reference : trim((string) ($node->DATAID ?? '')), 0, 100);
    }

    /** @param  iterable<SimpleXMLElement|null>  $parts */
    private static function joined(iterable $parts): string
    {
        $words = [];

        foreach ($parts as $part) {
            $text = trim((string) $part);

            if ($text !== '') {
                $words[] = $text;
            }
        }

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', implode(' ', $words))), 0, 255);
    }

    /**
     * @param  iterable<SimpleXMLElement>  $aliases
     * @return list<string>
     */
    private static function aliases(iterable $aliases): array
    {
        $names = [];

        foreach ($aliases as $alias) {
            $name = trim((string) $alias->ALIAS_NAME);

            if ($name !== '') {
                $names[] = mb_substr($name, 0, 255);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  iterable<SimpleXMLElement>  $values
     * @return list<string>
     */
    private static function texts(iterable $values): array
    {
        $texts = [];

        foreach ($values as $value) {
            $text = trim((string) $value);

            if ($text !== '') {
                $texts[] = mb_substr($text, 0, 100);
            }
        }

        return array_values(array_unique($texts));
    }

    /**
     * @param  list<int>  $years
     * @return list<int>
     */
    private static function years(array $years): array
    {
        $years = array_values(array_unique($years));
        sort($years);

        return $years;
    }
}
