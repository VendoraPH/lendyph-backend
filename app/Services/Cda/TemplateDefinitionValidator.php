<?php

namespace App\Services\Cda;

use App\Services\Cda\Sources\ProfileSource;
use App\Services\Cda\Sources\SourceCatalog;

/**
 * Checks a CDA template definition (CDA design §2, §4) before it is
 * activated, and on the dry-run. Returns every problem found, each with its
 * path in the definition and a message in words; an empty list means valid.
 *
 * What it checks:
 * - the shape: known top-level keys, `meta.verified`, the parts the report
 *   type has, the deadline basis and days setting;
 * - sections and fields: slug keys, unique; known field types, select
 *   options, number decimals; table sections and their columns;
 * - sources: catalogue keys only, with valid params; a table section reads a
 *   table source; `overridable` is refused for loans, accounting, fs, share
 *   capital and savings figures;
 * - calculations: structured ops only (never parsed), references that
 *   resolve, and no cycles;
 * - rules: known types, severities, categories and components; named
 *   patterns and named checks only (no free regex, no code); references that
 *   resolve; `fix_href` an internal app path;
 * - documents and the export block (cell addresses and what fills them).
 *
 * References: "section.field", or a bare field key that only one section
 * has. A table is referenced by its section key. A `document` rule names
 * the document's category in `document_category` (its `category` is the
 * issue category, like every rule's).
 */
final class TemplateDefinitionValidator
{
    public const SCHEMA_VERSION = 1;

    public const TOP_LEVEL_KEYS = ['schema_version', 'meta', 'applies', 'parts', 'deadline', 'sections', 'rules', 'documents', 'export', 'pesos'];

    /** Enhanced PESOS formula keys (design §8); the formulas are code in C4's engine. */
    public const PESOS_FORMULAS = ['ratio', 'ratio_avg', 'growth', 'coverage'];

    public const PESOS_DIRECTIONS = ['higher_better', 'lower_better'];

    public const FIELD_TYPES = ['text', 'textarea', 'number', 'money', 'percent', 'date', 'select', 'boolean', 'compliance', 'table'];

    public const SECTION_KINDS = ['fields', 'table'];

    public const COLUMN_FORMATS = ['text', 'number', 'money', 'percent', 'date'];

    public const CALC_OPS = ['add', 'sub', 'mul', 'div', 'ratio', 'sum_column', 'coalesce', 'round_half_up'];

    public const RULE_TYPES = ['required', 'min', 'max', 'max_length', 'one_of', 'pattern', 'date_range', 'compare', 'sum_equals', 'check', 'document'];

    /** Named patterns; a template never carries its own regex. */
    public const PATTERNS = ['tin', 'cda_reg_no', 'email', 'phone'];

    /** Named PHP checks (design §4), implemented by C2's validation engine. */
    public const CHECKS = [
        'accounting.year_closed', 'fs.balanced', 'fs.tb_matches_fs', 'members.missing_field', 'officers.training_incomplete',
        'officers.required_positions', 'profile.complete', 'period.cte_valid', 'classification.captured', 'template.verified',
        'pesos.rules_verified',
    ];

    public const SEVERITIES = ['critical', 'warning', 'info'];

    /** The nine validation categories of the CDA Data Validation Engine task. */
    public const CATEGORIES = [
        'missing_required_data', 'invalid_data', 'accounting_mismatch', 'member_data', 'loan_data',
        'officer_data', 'training', 'compliance_document', 'financial_data',
    ];

    public const COMPONENTS = ['fields', 'sources', 'documents', 'accounting', 'approvals'];

    public const COMPARE_OPS = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte'];

    public const DEADLINE_BASES = ['year_end', 'half_end'];

    public const DAYS_SETTINGS = ['annual_deadline_days', 'mediation_deadline_days'];

    public const DOCUMENT_STAGES = ['submit_for_review', 'approve', 'finalize', 'submit'];

    public const EXPORT_MODES = ['generated', 'template'];

    /** Where a rule's "fix it here" link may point: app screens only. */
    public const FIX_HREF_PREFIXES = ['/cda', '/borrowers', '/members', '/loans', '/accounting', '/share-capital', '/savings', '/reports', '/settings', '/users'];

    private const SLUG = '/^[a-z][a-z0-9_]{0,59}$/';

    private const CELL = '/^[A-Za-z0-9 _]{1,31}!\$?[A-Z]{1,3}\$?[1-9]\d{0,6}$/';

    /** @var list<array{path: string, message: string}> */
    private array $errors = [];

    /** @var array<string, array{section: string, type: string, kind: string}> full key => field */
    private array $fields = [];

    /** @var array<string, list<string>> bare key => full keys */
    private array $bare = [];

    /** @var array<string, list<string>> full key => full keys it depends on */
    private array $dependencies = [];

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $definition, ?string $reportType = null): array
    {
        $this->errors = [];
        $this->fields = [];
        $this->bare = [];
        $this->dependencies = [];

        foreach (array_keys($definition) as $key) {
            if (! in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $this->error((string) $key, "Unknown key \"{$key}\".");
            }
        }

        if (isset($definition['schema_version']) && $definition['schema_version'] !== self::SCHEMA_VERSION) {
            $this->error('schema_version', 'Only schema version '.self::SCHEMA_VERSION.' is supported.');
        }

        $this->checkMeta($definition['meta'] ?? null);
        $this->checkApplies($definition['applies'] ?? null);
        $this->checkParts($definition['parts'] ?? null, $reportType);
        $this->checkDeadline($definition['deadline'] ?? null);

        $sections = $definition['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $this->error('sections', 'A template needs at least one section.');
            $sections = [];
        }

        $this->collectFields($sections);
        $this->checkSections($sections);
        $this->checkCycles();
        $this->checkRules($definition['rules'] ?? []);
        $this->checkDocuments($definition['documents'] ?? []);
        $this->checkExport($definition['export'] ?? null);
        $this->checkPesos($definition['pesos'] ?? null, $reportType);

        return $this->errors;
    }

    /**
     * Every source key the definition names, in fields, tables and rules.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function sourceKeysIn(array $definition): array
    {
        $keys = [];

        foreach ((array) ($definition['sections'] ?? []) as $section) {
            if (isset($section['source']['key']) && is_string($section['source']['key'])) {
                $keys[] = $section['source']['key'];
            }

            foreach ((array) ($section['fields'] ?? []) as $field) {
                if (isset($field['source']['key']) && is_string($field['source']['key'])) {
                    $keys[] = $field['source']['key'];
                }
            }
        }

        foreach ((array) ($definition['rules'] ?? []) as $rule) {
            foreach (['left', 'right'] as $side) {
                $operand = $rule[$side] ?? null;

                if (is_string($operand) && str_starts_with($operand, 'source:')) {
                    $keys[] = substr($operand, 7);
                } elseif (is_array($operand) && isset($operand['source']['key']) && is_string($operand['source']['key'])) {
                    $keys[] = $operand['source']['key'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function checkMeta(mixed $meta): void
    {
        if (! is_array($meta)) {
            $this->error('meta', 'A template needs a "meta" block with "verified".');

            return;
        }

        if (! is_bool($meta['verified'] ?? null)) {
            $this->error('meta.verified', '"verified" must be true or false: has it been checked against the official CDA form?');
        }

        if (isset($meta['source_doc']) && ! is_string($meta['source_doc'])) {
            $this->error('meta.source_doc', '"source_doc" must be text.');
        }
    }

    private function checkApplies(mixed $applies): void
    {
        if ($applies === null) {
            return;
        }

        if (! is_array($applies) || array_diff(array_keys($applies), ['requires_cte']) !== [] || ! is_bool($applies['requires_cte'] ?? false)) {
            $this->error('applies', '"applies" holds only "requires_cte", true or false.');
        }
    }

    private function checkParts(mixed $parts, ?string $reportType): void
    {
        if (! is_array($parts) || ! array_is_list($parts) || $parts === [] || array_diff($parts, ReportTypes::PARTS) !== []) {
            $this->error('parts', '"parts" must list annual, or h1 and h2.');

            return;
        }

        if ($reportType !== null && ReportTypes::exists($reportType)) {
            $expected = ReportTypes::parts($reportType);
            $given = $parts;
            sort($expected);
            sort($given);

            if ($expected !== $given) {
                $this->error('parts', ReportTypes::title($reportType).' has the parts '.implode(' and ', ReportTypes::parts($reportType)).'.');
            }
        }
    }

    private function checkDeadline(mixed $deadline): void
    {
        if ($deadline === null) {
            return;
        }

        if (! is_array($deadline)) {
            $this->error('deadline', '"deadline" must hold "basis" and "days_setting".');

            return;
        }

        if (! in_array($deadline['basis'] ?? null, self::DEADLINE_BASES, true)) {
            $this->error('deadline.basis', '"basis" must be year_end or half_end.');
        }

        if (! in_array($deadline['days_setting'] ?? null, self::DAYS_SETTINGS, true)) {
            $this->error('deadline.days_setting', '"days_setting" must be annual_deadline_days or mediation_deadline_days.');
        }
    }

    /** @param  list<mixed>  $sections */
    private function collectFields(array $sections): void
    {
        foreach ($sections as $section) {
            if (! is_array($section) || ! is_string($section['key'] ?? null)) {
                continue;
            }

            $sectionKey = $section['key'];

            if (($section['kind'] ?? null) === 'table') {
                $this->addField($sectionKey, $sectionKey, 'table', 'table');

                continue;
            }

            foreach ((array) ($section['fields'] ?? []) as $field) {
                if (is_array($field) && is_string($field['key'] ?? null)) {
                    $this->addField($sectionKey.'.'.$field['key'], $field['key'], (string) ($field['type'] ?? ''), 'field');
                }
            }
        }
    }

    private function addField(string $full, string $bare, string $type, string $kind): void
    {
        if (! isset($this->fields[$full])) {
            $this->fields[$full] = ['section' => explode('.', $full)[0], 'type' => $type, 'kind' => $kind];
            $this->bare[$bare][] = $full;
        }
    }

    /** @param  list<mixed>  $sections */
    private function checkSections(array $sections): void
    {
        $seen = [];

        foreach ($sections as $i => $section) {
            $path = "sections.{$i}";

            if (! is_array($section)) {
                $this->error($path, 'A section must be an object.');

                continue;
            }

            $key = $section['key'] ?? null;

            if (! is_string($key) || preg_match(self::SLUG, $key) !== 1) {
                $this->error("{$path}.key", 'A section key is lower-case letters, digits and underscores.');
            } elseif (isset($seen[$key])) {
                $this->error("{$path}.key", "The section key \"{$key}\" is used twice.");
            } else {
                $seen[$key] = true;
            }

            if (! is_string($section['title'] ?? null) || trim($section['title']) === '') {
                $this->error("{$path}.title", 'A section needs a title.');
            }

            match ($section['kind'] ?? null) {
                'fields' => $this->checkFieldsSection($section, $path),
                'table' => $this->checkTableSection($section, $path),
                default => $this->error("{$path}.kind", 'A section\'s kind is fields or table.'),
            };
        }
    }

    /** @param  array<string, mixed>  $section */
    private function checkFieldsSection(array $section, string $path): void
    {
        $fields = $section['fields'] ?? null;

        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            $this->error("{$path}.fields", 'A fields section needs at least one field.');

            return;
        }

        $seen = [];

        foreach ($fields as $j => $field) {
            $fieldPath = "{$path}.fields.{$j}";

            if (! is_array($field)) {
                $this->error($fieldPath, 'A field must be an object.');

                continue;
            }

            $key = $field['key'] ?? null;

            if (! is_string($key) || preg_match(self::SLUG, $key) !== 1) {
                $this->error("{$fieldPath}.key", 'A field key is lower-case letters, digits and underscores.');

                continue;
            }

            if (isset($seen[$key])) {
                $this->error("{$fieldPath}.key", "The field key \"{$key}\" is used twice in this section.");
            }

            $seen[$key] = true;
            $full = ($section['key'] ?? '').'.'.$key;
            $this->checkField($field, $fieldPath, $full);
        }
    }

    /** @param  array<string, mixed>  $field */
    private function checkField(array $field, string $path, string $full): void
    {
        $type = $field['type'] ?? null;

        if (! in_array($type, self::FIELD_TYPES, true)) {
            $this->error("{$path}.type", 'Unknown field type "'.(is_string($type) ? $type : gettype($type)).'". Use one of '.implode(', ', self::FIELD_TYPES).'.');
        }

        if (array_key_exists('label', $field) && ! is_string($field['label'])) {
            $this->error("{$path}.label", 'A label must be text.');
        }

        foreach (['required', 'overridable', 'editable'] as $flag) {
            if (array_key_exists($flag, $field) && ! is_bool($field[$flag])) {
                $this->error("{$path}.{$flag}", "\"{$flag}\" must be true or false.");
            }
        }

        if ($type === 'select') {
            $options = $field['options'] ?? null;
            $valid = is_array($options) && $options !== [] && array_is_list($options)
                && array_all($options, fn (mixed $option) => is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null));

            if (! $valid) {
                $this->error("{$path}.options", 'A select field needs options, each with a value and a label.');
            }
        }

        if (array_key_exists('decimals', $field) && (! is_int($field['decimals']) || $field['decimals'] < 0 || $field['decimals'] > 6)) {
            $this->error("{$path}.decimals", '"decimals" is a whole number from 0 to 6.');
        }

        if ($type === 'table') {
            $this->checkColumns($field['columns'] ?? null, "{$path}.columns");
        }

        if (isset($field['source'], $field['calc'])) {
            $this->error($path, 'A field takes its value from a source or a calculation, not both.');
        }

        if (isset($field['source'])) {
            $sourceKey = $this->checkSource($field['source'], "{$path}.source");

            if ($sourceKey !== null && ($field['overridable'] ?? false) === true && ! SourceCatalog::overridable($sourceKey)) {
                $this->error("{$path}.overridable", "A figure from \"{$sourceKey}\" can't be overridden: loans, accounting, financial statements, share capital and savings are fixed at their source.");
            }
        }

        if (isset($field['calc'])) {
            $this->dependencies[$full] = [];
            $this->checkCalc($field['calc'], "{$path}.calc", $full);
        }
    }

    /** @param  array<string, mixed>  $section */
    private function checkTableSection(array $section, string $path): void
    {
        $this->checkColumns($section['columns'] ?? null, "{$path}.columns");

        if (array_key_exists('editable', $section) && ! is_bool($section['editable'])) {
            $this->error("{$path}.editable", '"editable" must be true or false.');
        }

        if (isset($section['source'])) {
            $key = $this->checkSource($section['source'], "{$path}.source");

            if ($key !== null && ! in_array(SourceCatalog::unitFor($key, (array) ($section['source']['params'] ?? [])), ['table', 'any'], true)) {
                $this->error("{$path}.source", "A table reads a table source; \"{$key}\" gives a single figure.");
            }
        }
    }

    private function checkColumns(mixed $columns, string $path): void
    {
        if (! is_array($columns) || ! array_is_list($columns) || $columns === []) {
            $this->error($path, 'A table needs at least one column.');

            return;
        }

        $seen = [];

        foreach ($columns as $k => $column) {
            $key = is_array($column) ? ($column['key'] ?? null) : null;

            if (! is_string($key) || preg_match(self::SLUG, $key) !== 1) {
                $this->error("{$path}.{$k}.key", 'A column key is lower-case letters, digits and underscores.');

                continue;
            }

            if (isset($seen[$key])) {
                $this->error("{$path}.{$k}.key", "The column key \"{$key}\" is used twice.");
            }

            $seen[$key] = true;

            if (isset($column['format']) && ! in_array($column['format'], self::COLUMN_FORMATS, true)) {
                $this->error("{$path}.{$k}.format", 'A column format is one of '.implode(', ', self::COLUMN_FORMATS).'.');
            }
        }
    }

    /** The source key when it's valid enough to check further, else null. */
    private function checkSource(mixed $source, string $path): ?string
    {
        if (! is_array($source) || ! is_string($source['key'] ?? null)) {
            $this->error($path, 'A source names a catalogue "key".');

            return null;
        }

        $key = $source['key'];
        $params = $source['params'] ?? [];

        if (! is_array($params) || ($params !== [] && array_is_list($params))) {
            $this->error("{$path}.params", 'Source params are named values.');

            return null;
        }

        if (! SourceCatalog::has($key)) {
            $this->error("{$path}.key", "Unknown source key \"{$key}\".");

            return null;
        }

        foreach (SourceCatalog::errors($key, $params) as $message) {
            $this->error("{$path}.params", $message);
        }

        return $key;
    }

    private function checkCalc(mixed $calc, string $path, ?string $owner): void
    {
        if (! is_array($calc) || ! is_string($calc['op'] ?? null)) {
            $this->error($path, 'A calculation is {"op": …, "args": [...]}.');

            return;
        }

        $op = $calc['op'];

        if (! in_array($op, self::CALC_OPS, true)) {
            $this->error("{$path}.op", "Unknown calculation \"{$op}\". Use one of ".implode(', ', self::CALC_OPS).'.');

            return;
        }

        $args = $calc['args'] ?? null;

        if (! is_array($args) || ! array_is_list($args) || $args === []) {
            $this->error("{$path}.args", "\"{$op}\" needs its args.");

            return;
        }

        $arity = match ($op) {
            'sub', 'div', 'ratio', 'sum_column', 'round_half_up' => 2,
            default => null,
        };

        if ($arity !== null && count($args) !== $arity) {
            $this->error("{$path}.args", "\"{$op}\" takes {$arity} args.");

            return;
        }

        if ($op === 'sum_column') {
            $table = $this->reference($args[0], "{$path}.args.0", $owner);

            if ($table !== null && $this->fields[$table]['type'] !== 'table') {
                $this->error("{$path}.args.0", "\"{$args[0]}\" isn't a table.");
            }

            if (! is_string($args[1]) || preg_match(self::SLUG, $args[1]) !== 1) {
                $this->error("{$path}.args.1", 'sum_column names a column key.');
            }

            return;
        }

        if ($op === 'round_half_up' && (! is_int($args[1]) || $args[1] < 0 || $args[1] > 6)) {
            $this->error("{$path}.args.1", 'round_half_up rounds to 0 to 6 decimals.');
        }

        foreach ($args as $n => $arg) {
            if ($op === 'round_half_up' && $n === 1) {
                continue;
            }

            $this->checkOperand($arg, "{$path}.args.{$n}", $owner);
        }
    }

    /** A number, a field reference or a nested calculation. */
    private function checkOperand(mixed $operand, string $path, ?string $owner): void
    {
        if (is_int($operand) || is_float($operand) || $operand === null) {
            return;
        }

        if (is_string($operand)) {
            $this->reference($operand, $path, $owner);

            return;
        }

        $this->checkCalc($operand, $path, $owner);
    }

    /** The full field key a reference names, recording it as a dependency; null (with an error) when it names none. */
    private function reference(mixed $reference, string $path, ?string $owner): ?string
    {
        if (! is_string($reference)) {
            $this->error($path, 'A reference names a field.');

            return null;
        }

        $full = isset($this->fields[$reference]) ? $reference : null;

        if ($full === null && isset($this->bare[$reference])) {
            if (count($this->bare[$reference]) > 1) {
                $this->error($path, "\"{$reference}\" is in more than one section; write it as section.field.");

                return null;
            }

            $full = $this->bare[$reference][0];
        }

        if ($full === null) {
            $this->error($path, "\"{$reference}\" isn't a field of this template.");

            return null;
        }

        if ($owner !== null) {
            $this->dependencies[$owner][] = $full;
        }

        return $full;
    }

    private function checkCycles(): void
    {
        $state = [];

        foreach (array_keys($this->dependencies) as $start) {
            $cycle = $this->findCycle($start, $state, []);

            if ($cycle !== null) {
                $this->error('sections', 'The calculations form a cycle: '.implode(' → ', $cycle).'.');

                return;
            }
        }
    }

    /**
     * @param  array<string, int>  $state  1 visiting, 2 done
     * @param  list<string>  $trail
     * @return list<string>|null
     */
    private function findCycle(string $node, array &$state, array $trail): ?array
    {
        if (($state[$node] ?? 0) === 2) {
            return null;
        }

        if (($state[$node] ?? 0) === 1) {
            return [...array_slice($trail, (int) array_search($node, $trail, true)), $node];
        }

        $state[$node] = 1;
        $trail[] = $node;

        foreach ($this->dependencies[$node] ?? [] as $next) {
            $cycle = $this->findCycle($next, $state, $trail);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        $state[$node] = 2;

        return null;
    }

    private function checkRules(mixed $rules): void
    {
        if (! is_array($rules) || ($rules !== [] && ! array_is_list($rules))) {
            $this->error('rules', '"rules" is a list.');

            return;
        }

        $seen = [];

        foreach ($rules as $i => $rule) {
            $path = "rules.{$i}";

            if (! is_array($rule)) {
                $this->error($path, 'A rule must be an object.');

                continue;
            }

            $id = $rule['id'] ?? null;

            if (! is_string($id) || preg_match(self::SLUG, $id) !== 1) {
                $this->error("{$path}.id", 'A rule id is lower-case letters, digits and underscores.');
            } elseif (isset($seen[$id])) {
                $this->error("{$path}.id", "The rule id \"{$id}\" is used twice.");
            } else {
                $seen[$id] = true;
            }

            if (! in_array($rule['severity'] ?? null, self::SEVERITIES, true)) {
                $this->error("{$path}.severity", 'A rule\'s severity is critical, warning or info.');
            }

            $category = $rule['category'] ?? null;

            if (! in_array($category, self::CATEGORIES, true)) {
                $this->error("{$path}.category", 'Unknown category "'.(is_string($category) ? $category : '').'". Use one of '.implode(', ', self::CATEGORIES).'.');
            }

            if (isset($rule['component']) && ! in_array($rule['component'], self::COMPONENTS, true)) {
                $this->error("{$path}.component", 'A rule\'s component is one of '.implode(', ', self::COMPONENTS).'.');
            }

            if (isset($rule['fix_href']) && ! self::allowedHref($rule['fix_href'])) {
                $this->error("{$path}.fix_href", '"fix_href" must be a path inside the app, such as /cda/settings or /borrowers.');
            }

            if (isset($rule['message']) && ! is_string($rule['message'])) {
                $this->error("{$path}.message", 'A rule message is text.');
            }

            $this->checkRule($rule, $path);
        }
    }

    /** @param  array<string, mixed>  $rule */
    private function checkRule(array $rule, string $path): void
    {
        $type = $rule['type'] ?? null;

        switch ($type) {
            case 'required':
            case 'date_range':
                $this->reference($rule['field'] ?? null, "{$path}.field", null);
                break;
            case 'min':
            case 'max':
                $this->reference($rule['field'] ?? null, "{$path}.field", null);
                if (! is_int($rule['value'] ?? null) && ! is_float($rule['value'] ?? null)) {
                    $this->error("{$path}.value", "\"{$type}\" needs a number.");
                }
                break;
            case 'max_length':
                $this->reference($rule['field'] ?? null, "{$path}.field", null);
                if (! is_int($rule['value'] ?? null) || $rule['value'] < 1) {
                    $this->error("{$path}.value", '"max_length" needs a whole number above 0.');
                }
                break;
            case 'one_of':
                $this->reference($rule['field'] ?? null, "{$path}.field", null);
                if (! is_array($rule['values'] ?? null) || $rule['values'] === []) {
                    $this->error("{$path}.values", '"one_of" needs its values.');
                }
                break;
            case 'pattern':
                $this->reference($rule['field'] ?? null, "{$path}.field", null);
                if (! in_array($rule['pattern'] ?? null, self::PATTERNS, true)) {
                    $this->error("{$path}.pattern", 'Only named patterns are allowed: '.implode(', ', self::PATTERNS).'.');
                }
                break;
            case 'compare':
                $this->checkCompareOperand($rule['left'] ?? null, "{$path}.left");
                $this->checkCompareOperand($rule['right'] ?? null, "{$path}.right");
                if (! in_array($rule['op'] ?? null, self::COMPARE_OPS, true)) {
                    $this->error("{$path}.op", 'A comparison is one of '.implode(', ', self::COMPARE_OPS).'.');
                }
                if (isset($rule['tolerance']) && ((! is_int($rule['tolerance']) && ! is_float($rule['tolerance'])) || $rule['tolerance'] < 0)) {
                    $this->error("{$path}.tolerance", 'A tolerance is a number of 0 or more.');
                }
                break;
            case 'sum_equals':
                if (! is_array($rule['parts'] ?? null) || $rule['parts'] === []) {
                    $this->error("{$path}.parts", '"sum_equals" needs its parts.');
                } else {
                    foreach ($rule['parts'] as $n => $part) {
                        $this->reference($part, "{$path}.parts.{$n}", null);
                    }
                }
                $this->reference($rule['total'] ?? null, "{$path}.total", null);
                break;
            case 'check':
                $check = $rule['check'] ?? null;
                if (! in_array($check, self::CHECKS, true)) {
                    $this->error("{$path}.check", 'Unknown check "'.(is_string($check) ? $check : '').'". Use one of '.implode(', ', self::CHECKS).'.');
                }
                if (isset($rule['params']) && (! is_array($rule['params']) || ($rule['params'] !== [] && array_is_list($rule['params'])))) {
                    $this->error("{$path}.params", 'Check params are named values.');
                }
                break;
            case 'document':
                if (! is_string($rule['document_category'] ?? null) || preg_match(self::SLUG, $rule['document_category']) !== 1) {
                    $this->error("{$path}.document_category", 'A document rule names the document category ("document_category"); "category" is the issue category.');
                }
                if (isset($rule['final']) && ! is_bool($rule['final'])) {
                    $this->error("{$path}.final", '"final" must be true or false.');
                }
                if (! in_array($rule['stage'] ?? null, self::DOCUMENT_STAGES, true)) {
                    $this->error("{$path}.stage", 'A document stage is one of '.implode(', ', self::DOCUMENT_STAGES).'.');
                }
                break;
            default:
                $this->error("{$path}.type", 'Unknown rule type "'.(is_string($type) ? $type : '').'". Use one of '.implode(', ', self::RULE_TYPES).'.');
        }
    }

    /** A number, a field, a calculation, "report:type.field" or a source. */
    private function checkCompareOperand(mixed $operand, string $path): void
    {
        if (is_int($operand) || is_float($operand)) {
            return;
        }

        if (is_string($operand) && str_starts_with($operand, 'report:')) {
            $target = substr($operand, 7);
            $type = explode('.', $target)[0];

            if (! ReportTypes::exists($type) || ! str_contains($target, '.')) {
                $this->error($path, "\"{$operand}\" must read report:<report type>.<field>.");
            }

            return;
        }

        if (is_string($operand) && str_starts_with($operand, 'source:')) {
            $key = substr($operand, 7);

            if (! SourceCatalog::has($key)) {
                $this->error($path, "Unknown source key \"{$key}\".");
            } else {
                foreach (SourceCatalog::errors($key, []) as $message) {
                    $this->error($path, $message);
                }
            }

            return;
        }

        if (is_array($operand) && isset($operand['source'])) {
            $this->checkSource($operand['source'], "{$path}.source");

            return;
        }

        if (is_array($operand)) {
            $this->checkCalc($operand, $path, null);

            return;
        }

        $this->reference($operand, $path, null);
    }

    private function checkDocuments(mixed $documents): void
    {
        if (! is_array($documents) || ($documents !== [] && ! array_is_list($documents))) {
            $this->error('documents', '"documents" is a list.');

            return;
        }

        $seen = [];

        foreach ($documents as $i => $document) {
            $path = "documents.{$i}";
            $key = is_array($document) ? ($document['key'] ?? null) : null;

            if (! is_string($key) || preg_match(self::SLUG, $key) !== 1) {
                $this->error("{$path}.key", 'A document key is lower-case letters, digits and underscores.');

                continue;
            }

            if (isset($seen[$key])) {
                $this->error("{$path}.key", "The document key \"{$key}\" is used twice.");
            }

            $seen[$key] = true;

            if (! is_string($document['category'] ?? null) || ! is_string($document['label'] ?? null)) {
                $this->error($path, 'A document needs a category and a label.');
            }

            if (! in_array($document['stage'] ?? null, self::DOCUMENT_STAGES, true)) {
                $this->error("{$path}.stage", 'A document stage is one of '.implode(', ', self::DOCUMENT_STAGES).'.');
            }

            if (! in_array($document['severity'] ?? null, self::SEVERITIES, true)) {
                $this->error("{$path}.severity", 'A document\'s severity is critical, warning or info.');
            }
        }
    }

    private function checkExport(mixed $export): void
    {
        if ($export === null) {
            return;
        }

        if (! is_array($export) || array_diff(array_keys($export), ['pdf', 'excel']) !== []) {
            $this->error('export', '"export" holds "pdf" and "excel".');

            return;
        }

        if (isset($export['pdf']['orientation']) && ! in_array($export['pdf']['orientation'], ['portrait', 'landscape'], true)) {
            $this->error('export.pdf.orientation', 'The orientation is portrait or landscape.');
        }

        $excel = $export['excel'] ?? null;

        if ($excel === null) {
            return;
        }

        if (! is_array($excel) || ! in_array($excel['mode'] ?? null, self::EXPORT_MODES, true)) {
            $this->error('export.excel.mode', 'The Excel mode is generated or template.');

            return;
        }

        foreach ((array) ($excel['cells'] ?? []) as $cell => $value) {
            if (preg_match(self::CELL, (string) $cell) !== 1) {
                $this->error('export.excel.cells', "\"{$cell}\" isn't a cell address such as Sheet1!C5.");

                continue;
            }

            if (is_string($value) && str_starts_with($value, 'profile.')) {
                if (! in_array(substr($value, 8), ProfileSource::FIELDS, true)) {
                    $this->error("export.excel.cells.{$cell}", "\"{$value}\" isn't a profile field.");
                }

                continue;
            }

            $this->reference($value, "export.excel.cells.{$cell}", null);
        }

        if (isset($excel['draft_cell']) && preg_match(self::CELL, (string) $excel['draft_cell']) !== 1) {
            $this->error('export.excel.draft_cell', 'The draft mark cell is an address such as Sheet1!A1.');
        }
    }

    /**
     * The Enhanced PESOS tables (design §8), on the pesos template only:
     * components with weights, indicators with a formula key, their
     * numerator and denominator sources, direction, weight and score bands,
     * and the adjectival rating bands.
     */
    private function checkPesos(mixed $pesos, ?string $reportType): void
    {
        if ($pesos === null) {
            return;
        }

        if ($reportType !== null && $reportType !== 'pesos') {
            $this->error('pesos', 'Only the PESOS template holds a "pesos" block.');

            return;
        }

        if (! is_array($pesos) || ! is_array($pesos['components'] ?? null) || ! is_array($pesos['indicators'] ?? null) || ! is_array($pesos['rating_bands'] ?? null)) {
            $this->error('pesos', '"pesos" holds components, indicators and rating_bands.');

            return;
        }

        $components = [];

        foreach ($pesos['components'] as $i => $component) {
            if (! is_array($component) || ! is_string($component['key'] ?? null) || preg_match(self::SLUG, $component['key']) !== 1 || ! is_string($component['label'] ?? null) || ! is_numeric($component['weight'] ?? null)) {
                $this->error("pesos.components.{$i}", 'A component has a key, a label and a weight.');

                continue;
            }

            $components[$component['key']] = true;
        }

        $seen = [];

        foreach ($pesos['indicators'] as $i => $indicator) {
            $path = "pesos.indicators.{$i}";

            if (! is_array($indicator) || ! is_string($indicator['key'] ?? null) || preg_match(self::SLUG, $indicator['key']) !== 1) {
                $this->error($path, 'An indicator needs a key.');

                continue;
            }

            if (isset($seen[$indicator['key']])) {
                $this->error("{$path}.key", "The indicator key \"{$indicator['key']}\" is used twice.");
            }

            $seen[$indicator['key']] = true;

            if (! isset($components[$indicator['component'] ?? ''])) {
                $this->error("{$path}.component", 'An indicator belongs to one of the components.');
            }

            if (! in_array($indicator['formula_key'] ?? null, self::PESOS_FORMULAS, true)) {
                $this->error("{$path}.formula_key", 'A formula key is one of '.implode(', ', self::PESOS_FORMULAS).'.');
            }

            if (! in_array($indicator['direction'] ?? null, self::PESOS_DIRECTIONS, true)) {
                $this->error("{$path}.direction", 'The direction is higher_better or lower_better.');
            }

            if (! is_numeric($indicator['weight'] ?? null)) {
                $this->error("{$path}.weight", 'An indicator needs a weight.');
            }

            $this->checkSource($indicator['numerator'] ?? null, "{$path}.numerator");
            $this->checkSource($indicator['denominator'] ?? null, "{$path}.denominator");

            $bands = $indicator['bands'] ?? null;
            $validBands = is_array($bands) && $bands !== [] && array_all($bands, fn (mixed $band) => is_array($band)
                && array_key_exists('min', $band) && array_key_exists('max', $band) && is_numeric($band['points'] ?? null)
                && ($band['min'] === null || is_numeric($band['min'])) && ($band['max'] === null || is_numeric($band['max'])));

            if (! $validBands) {
                $this->error("{$path}.bands", 'Score bands each have a min, a max (either may be null) and points.');
            }
        }

        foreach ($pesos['rating_bands'] as $i => $band) {
            if (! is_array($band) || ! is_numeric($band['min'] ?? null) || ! is_string($band['label'] ?? null)) {
                $this->error("pesos.rating_bands.{$i}", 'A rating band has a minimum score and a label.');
            }
        }
    }

    private static function allowedHref(mixed $href): bool
    {
        if (! is_string($href) || preg_match('#^/[a-z0-9\-/]*(\?[a-z0-9_=&\-]*)?$#', $href) !== 1) {
            return false;
        }

        foreach (self::FIX_HREF_PREFIXES as $prefix) {
            if ($href === $prefix || str_starts_with($href, $prefix.'/') || str_starts_with($href, $prefix.'?')) {
                return true;
            }
        }

        return false;
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = ['path' => $path, 'message' => $message];
    }
}
