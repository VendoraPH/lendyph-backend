<?php

namespace App\Services\Cda\Reports;

/**
 * A template definition read for the engine (design §2): every field by its
 * full key ("section.field"; a table section is one field keyed by its
 * section), bare keys resolved the way TemplateDefinitionValidator resolves
 * them, the calculations in dependency order, the rules and the required
 * documents. The definition was checked when the template was activated, so
 * this only reads it.
 */
final class ReportDefinition
{
    /** @var array<string, array<string, mixed>> full key => field spec, with `section`, `section_title`, `full_key` and `kind` */
    private array $fields = [];

    /** @var array<string, list<string>> bare key => full keys */
    private array $bare = [];

    /** @param  array<string, mixed>  $definition */
    public function __construct(public readonly array $definition)
    {
        foreach ($this->sections() as $section) {
            $sectionKey = (string) $section['key'];

            if (($section['kind'] ?? 'fields') === 'table') {
                $this->add($sectionKey, $sectionKey, [
                    'key' => $sectionKey,
                    'label' => $section['title'] ?? $sectionKey,
                    'type' => 'table',
                    'source' => $section['source'] ?? null,
                    'columns' => $section['columns'] ?? [],
                    'editable' => (bool) ($section['editable'] ?? false),
                ], $section, 'table');

                continue;
            }

            foreach ((array) ($section['fields'] ?? []) as $field) {
                $this->add($sectionKey.'.'.$field['key'], (string) $field['key'], $field, $section, 'field');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        return array_values((array) ($this->definition['sections'] ?? []));
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $fullKey): ?array
    {
        return $this->fields[$fullKey] ?? null;
    }

    /** The full key a reference names ("section.field", or a bare key only one section has), else null. */
    public function resolve(string $reference): ?string
    {
        if (isset($this->fields[$reference])) {
            return $reference;
        }

        $matches = $this->bare[$reference] ?? [];

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string, array<string, mixed>> the fields filled from a source */
    public function sourced(): array
    {
        return array_filter($this->fields, fn (array $field) => is_array($field['source'] ?? null));
    }

    /**
     * The calculated fields, each after the fields it reads (the validator
     * already refused cycles).
     *
     * @return array<string, array<string, mixed>>
     */
    public function calculated(): array
    {
        $calculated = array_filter($this->fields, fn (array $field) => is_array($field['calc'] ?? null));
        $ordered = [];
        $visit = function (string $key) use (&$visit, &$ordered, $calculated): void {
            if (isset($ordered[$key]) || ! isset($calculated[$key])) {
                return;
            }

            $ordered[$key] = false;

            foreach ($this->referencesIn($calculated[$key]['calc']) as $dependency) {
                $visit($dependency);
            }

            $ordered[$key] = true;
        };

        foreach (array_keys($calculated) as $key) {
            $visit($key);
        }

        return array_map(fn (string $key) => $calculated[$key], array_combine(array_keys($ordered), array_keys($ordered)));
    }

    /** Whether the field takes a value typed by staff (no source, no calculation; a table only when marked editable). */
    public function isManual(array $field): bool
    {
        if (isset($field['calc'])) {
            return false;
        }

        if ($field['type'] === 'table') {
            return ! isset($field['source']) && (bool) ($field['editable'] ?? false);
        }

        return ! isset($field['source']);
    }

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return array_values((array) ($this->definition['rules'] ?? []));
    }

    /** @return list<array<string, mixed>> the required documents of the template */
    public function documents(): array
    {
        return array_values((array) ($this->definition['documents'] ?? []));
    }

    public function isVerified(): bool
    {
        return (bool) ($this->definition['meta']['verified'] ?? false);
    }

    public function hasCheck(string $check): bool
    {
        foreach ($this->rules() as $rule) {
            if (($rule['type'] ?? null) === 'check' && ($rule['check'] ?? null) === $check) {
                return true;
            }
        }

        return false;
    }

    /** SHA-256 of the definition's canonical JSON, kept in snapshots. */
    public function hash(): string
    {
        return CanonicalJson::checksum(CanonicalJson::encode($this->definition));
    }

    /**
     * The full keys a calculation reads.
     *
     * @return list<string>
     */
    public function referencesIn(mixed $calc): array
    {
        if (! is_array($calc) || ! is_string($calc['op'] ?? null)) {
            return [];
        }

        $refs = [];

        foreach ((array) ($calc['args'] ?? []) as $n => $arg) {
            if ($calc['op'] === 'sum_column' && $n === 1) {
                continue;
            }

            if (is_string($arg)) {
                $resolved = $this->resolve($arg);

                if ($resolved !== null) {
                    $refs[] = $resolved;
                }
            } elseif (is_array($arg)) {
                $refs = [...$refs, ...$this->referencesIn($arg)];
            }
        }

        return $refs;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $section
     */
    private function add(string $full, string $bare, array $field, array $section, string $kind): void
    {
        $this->fields[$full] = [
            ...$field,
            'full_key' => $full,
            'section' => (string) $section['key'],
            'section_title' => (string) ($section['title'] ?? $section['key']),
            'kind' => $kind,
        ];
        $this->bare[$bare][] = $full;
    }
}
