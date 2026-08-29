<?php

namespace App\Services\CsvImport;

use InvalidArgumentException;

/**
 * A CSV row after normalisation: typed values plus everything noticed on the
 * way.
 *
 * In memory, money is INTEGER CENTAVOS and dates are `Y-m-d` strings — the
 * contract LoanScheduleReconstructor depends on. On the way to storage,
 * self::toPayload() converts to a form that survives a MySQL JSON column
 * unchanged; see the two rules documented there.
 */
final class NormalizedRow
{
    /**
     * @param  array<string, mixed>  $values  Keyed by CsvImportSchema field key.
     * @param  list<RowNote>  $warnings
     * @param  list<RowNote>  $errors
     */
    public function __construct(
        public readonly string $shape,
        public readonly int $rowNumber,
        public readonly array $values,
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function value(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * The staged form of this row, safe to persist in a MySQL JSON column.
     *
     * Two properties of MySQL JSON make the obvious representation wrong, and
     * both were proven by probing the server rather than inferred:
     *
     * ONE — MySQL does NOT preserve object key order. It normalises keys by
     * length, then lexicographically: {"LEGACY-SAL":1,"z":2} reads back with
     * "z" first. JSON ARRAYS are preserved verbatim. So `values` is a positional
     * LIST in CsvImportSchema order, never an object keyed by column name. If it
     * were an object, anything that later recovered position by iteration order
     * — an array_values(), a foreach paired against the header — would read
     * every value into the wrong field. That is precisely the column-shift
     * corruption this package rejects whole files to prevent, arriving through
     * the back door and with no error anywhere.
     *
     * TWO — a whole-number float loses its type through a JSON column: 12500.0
     * goes in and comes back as int 12500, while 12500.5 survives as a float.
     * Rather than reason about which values are at risk, EVERY value in the
     * payload is a string or null. A string cannot change type in transit, so
     * the staging pass and the import pass cannot disagree about what a
     * principal is. self::fromPayload() casts back using the schema's declared
     * type, which is the only thing that decides what a value means.
     *
     * `warnings` and `errors` stay keyed objects on purpose. Key order carries
     * no meaning there — they are read by name — and making them positional
     * would trade readability for nothing. Position is load-bearing in exactly
     * one place, and that place is a list.
     *
     * @return array{shape: string, row: int, values: list<string|null>, warnings: list<array{field: string, code: string, message: string}>, errors: list<array{field: string, code: string, message: string}>}
     */
    public function toPayload(): array
    {
        $values = [];

        foreach (CsvImportSchema::keys($this->shape) as $key) {
            $value = $this->values[$key] ?? null;
            $values[] = $value === null ? null : (string) $value;
        }

        return [
            'shape' => $this->shape,
            'row' => $this->rowNumber,
            'values' => $values,
            'warnings' => $this->warningsToArray(),
            'errors' => $this->errorsToArray(),
        ];
    }

    /**
     * Rebuild a row from self::toPayload(), restoring each value's PHP type
     * from the schema rather than from whatever JSON happened to hand back.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $shape = (string) ($payload['shape'] ?? '');
        $keys = CsvImportSchema::keys($shape);
        $raw = $payload['values'] ?? null;

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new InvalidArgumentException(
                'A staged row payload must hold `values` as a JSON list. An object would have had its key order '
                .'rewritten by MySQL, and every value would rebuild into the wrong field.'
            );
        }

        if (count($raw) !== count($keys)) {
            throw new InvalidArgumentException(
                'A staged '.$shape.' payload must hold exactly '.count($keys).' values, got '.count($raw).'.'
            );
        }

        $values = [];

        foreach ($keys as $index => $key) {
            $values[$key] = self::castFromPayload($shape, $key, $raw[$index]);
        }

        return new self(
            shape: $shape,
            rowNumber: (int) ($payload['row'] ?? 0),
            values: $values,
            warnings: self::notesFromPayload($payload['warnings'] ?? []),
            errors: self::notesFromPayload($payload['errors'] ?? []),
        );
    }

    private static function castFromPayload(string $shape, string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Staged value [{$key}] came back as ".get_debug_type($value).' rather than a string. '
                .'Payload values are written as strings precisely so a JSON column cannot retype them.'
            );
        }

        return match (CsvImportSchema::typeOf($shape, $key)) {
            CsvImportSchema::TYPE_MONEY, CsvImportSchema::TYPE_INT => self::toInt($key, $value),
            default => $value,
        };
    }

    private static function toInt(string $key, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("Staged value [{$key}] is not a whole number: \"{$value}\".");
        }

        return (int) $value;
    }

    /**
     * @param  mixed  $notes
     * @return list<RowNote>
     */
    private static function notesFromPayload(mixed $notes): array
    {
        if (! is_array($notes)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $note): RowNote => new RowNote(
                (string) ($note['field'] ?? ''),
                (string) ($note['code'] ?? ''),
                (string) ($note['message'] ?? ''),
            ),
            array_filter($notes, 'is_array'),
        ));
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public function warningsToArray(): array
    {
        return array_map(static fn (RowNote $note): array => $note->toArray(), $this->warnings);
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    public function errorsToArray(): array
    {
        return array_map(static fn (RowNote $note): array => $note->toArray(), $this->errors);
    }
}
