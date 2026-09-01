<?php

namespace App\Services\CsvImport;

/**
 * Accumulates the warnings and errors raised while normalising one row.
 *
 * Passed by reference through the normalisation primitives so a single value
 * can be repaired, downgraded or rejected without every method having to return
 * a tuple.
 */
final class RowNoteBag
{
    /** @var list<RowNote> */
    private array $warnings = [];

    /** @var list<RowNote> */
    private array $errors = [];

    public function warn(string $field, string $code, string $message): void
    {
        $this->warnings[] = new RowNote($field, $code, $message);
    }

    public function fail(string $field, string $code, string $message): void
    {
        $this->errors[] = new RowNote($field, $code, $message);
    }

    /**
     * @return list<RowNote>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return list<RowNote>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return array_map(static fn (RowNote $note): string => $note->code, $this->warnings);
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return array_map(static fn (RowNote $note): string => $note->code, $this->errors);
    }
}
