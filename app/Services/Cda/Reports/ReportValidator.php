<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaDocument;
use App\Models\CdaReport;
use App\Services\Cda\Deadlines;
use App\Services\Cda\Registers\DocumentCategories;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\Sources\SourceContext;
use App\Services\Cda\Sources\SourceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OverflowException;
use Throwable;

/**
 * The validation engine (design §4): runs a report's checks over its stored
 * values and returns the issues and the readiness checks.
 *
 * A check is one of: each required field (filled in), each template rule
 * (required, min, max, max_length, one_of, pattern, date_range, compare,
 * sum_equals, a named check, a document), each required document of the
 * template, and one approval check. Every issue carries a severity
 * (critical, warning, info), one of the nine categories, a component
 * (fields, sources, documents, accounting, approvals), a message in words,
 * the field it's about and where to fix it. A document issue also carries
 * the stage it's needed by, so it blocks only that action and later ones.
 *
 * Sources that couldn't be read add a Warning, and a figure from the books
 * of a year not yet closed adds the Critical "year not closed" issue when
 * the template doesn't already check for it.
 *
 * A document issue names the document in words: a rule's by the template's
 * listed document of that category ("The signed CAPR (final copy) hasn't
 * been uploaded."), else by the category's own words; a listed document by
 * its label, with the verb agreeing ("Training certificates haven't been
 * uploaded."). The checks that a template, or the PESOS rules, are still to
 * be verified read under their own category, `template_unverified`, whatever
 * the template says.
 */
final class ReportValidator
{
    /** The workflow actions a document can be needed by, in order. */
    public const STAGES = ['submit_for_review' => 1, 'approve' => 2, 'finalize' => 3, 'submit' => 4];

    public const PATTERNS = [
        'tin' => '/^\d{3}-?\d{3}-?\d{3}(-?\d{3,5})?$/',
        'cda_reg_no' => '/^[0-9A-Z][0-9A-Z\-]{3,29}$/',
        'phone' => '/^(\+63|0)\d{9,10}$/',
    ];

    private const PATTERN_WORDS = ['tin' => 'TIN (000-000-000-000)', 'cda_reg_no' => 'CDA registration number', 'email' => 'email address', 'phone' => 'phone number'];

    /** The category of the "still to be checked against the official form" issues. */
    public const TEMPLATE_UNVERIFIED = 'template_unverified';

    /** The named checks that raise it. */
    private const UNVERIFIED_CHECKS = ['template.verified', 'pesos.rules_verified'];

    private const OP_WORDS = ['eq' => 'equal', 'ne' => 'differ from', 'lt' => 'be less than', 'lte' => 'be at most', 'gt' => 'be more than', 'gte' => 'be at least'];

    public function __construct(
        private readonly NamedChecks $checks,
        private readonly SourceRegistry $sources,
        private readonly CalcEngine $calc,
    ) {}

    /**
     * @param  array<string, mixed>  $values  full key => stored value
     * @param  array<string, array<string, mixed>>  $sourceInfo  full key => the stored source result (or `error`)
     * @return array{issues: list<array<string, mixed>>, checks: list<array{id: string, component: string, section: string|null, passed: bool}>}
     */
    public function evaluate(CdaReport $report, ReportDefinition $definition, array $values, array $sourceInfo, SourceContext $context): array
    {
        $issues = [];
        $checks = [];

        foreach ($definition->fields() as $key => $field) {
            $info = $sourceInfo[$key] ?? null;

            if (is_array($info) && isset($info['error'])) {
                $issues[] = $this->issue("source:{$key}", null, 'warning', 'missing_required_data', 'sources', $info['error'], $field);
            }

            if (! ($field['required'] ?? false)) {
                continue;
            }

            $passed = FieldValue::filled($values[$key] ?? null);

            if (! $passed) {
                $message = isset($field['source']) && is_array($info) && ! isset($info['error'])
                    ? self::label($field).' has no figure from '.($info['label'] ?? 'its source').'.'
                    : self::label($field).' is required.';
                $issues[] = $this->issue("required:{$key}", null, 'critical', 'missing_required_data', isset($field['source']) ? 'sources' : 'fields', $message, $field);
            }

            $checks[] = ['id' => "required:{$key}", 'component' => isset($field['source']) ? 'sources' : 'fields', 'section' => $field['section'], 'passed' => $passed];
        }

        foreach ($definition->rules() as $rule) {
            $field = $this->ruleField($rule, $definition);
            $severity = (string) $rule['severity'];
            $component = (string) ($rule['component'] ?? 'fields');

            if (($rule['type'] ?? null) === 'document') {
                $missing = $this->missingRuleDocument($rule, $report, $definition);
                $problem = $missing['issue']['message'] ?? null;

                if ($missing !== null) {
                    $issues[] = $missing['issue'];
                }
            } else {
                $problem = $this->runRule($rule, $report, $definition, $values, $context);

                if ($problem !== null) {
                    $issues[] = $this->issue('rule:'.$rule['id'], (string) $rule['id'], $severity, self::category($rule), $component, $rule['message'] ?? $problem, $field, $rule['fix_href'] ?? null);
                }
            }

            $checks[] = ['id' => 'rule:'.$rule['id'], 'component' => $component, 'section' => $field['section'] ?? null, 'passed' => $problem === null || $severity === 'info'];
        }

        foreach ($definition->documents() as $document) {
            $missing = $this->missingListedDocument($document, $report);

            if ($missing !== null) {
                $issues[] = $missing['issue'];
            }

            $checks[] = ['id' => 'document:'.$document['key'], 'component' => 'documents', 'section' => null, 'passed' => $missing === null || $document['severity'] === 'info'];
        }

        if (! $definition->hasCheck('accounting.year_closed') && collect($sourceInfo)->contains(fn ($info) => is_array($info) && ($info['meta']['year_closed'] ?? null) === false)) {
            $message = $this->checks->run('accounting.year_closed', [], $context, $definition);
            $issues[] = $this->issue('sources:year_not_closed', null, 'critical', 'financial_data', 'accounting', (string) $message, null, '/accounting');
            $checks[] = ['id' => 'sources:year_not_closed', 'component' => 'accounting', 'section' => null, 'passed' => false];
        }

        $approved = in_array($report->status, [CdaReport::STATUS_APPROVED, ...CdaReport::LOCKED_STATUSES], true);
        $checks[] = ['id' => 'approval', 'component' => 'approvals', 'section' => null, 'passed' => $approved];

        return ['issues' => $issues, 'checks' => $checks];
    }

    /**
     * Whether an issue blocks the action: Critical, and needed by this stage
     * or earlier. Recording a filing (submit) is blocked only by a document
     * needed at filing: the filing has already happened.
     */
    public static function blocks(array $issue, string $action): bool
    {
        if ($issue['severity'] !== 'critical') {
            return false;
        }

        $stage = $issue['stage'] ?? null;

        if ($action === 'submit') {
            return $stage === 'submit';
        }

        return $stage === null || self::STAGES[$stage] <= self::STAGES[$action];
    }

    /**
     * The documents the report still lacks, from its document rules and its
     * listed documents, optionally only those needed at one stage: each with
     * its issue (as evaluate() gives it), its name in words for a sentence
     * ("the signed CAPR (final copy)", "training certificates"), its category
     * and whether a final copy is needed. Reads the documents live.
     *
     * @return list<array{issue: array<string, mixed>, name: string, category: string, final: bool}>
     */
    public function missingDocuments(CdaReport $report, ReportDefinition $definition, ?string $stage = null): array
    {
        $missing = [];

        foreach ($definition->rules() as $rule) {
            if (($rule['type'] ?? null) === 'document' && ($stage === null || $rule['stage'] === $stage)) {
                $missing[] = $this->missingRuleDocument($rule, $report, $definition);
            }
        }

        foreach ($definition->documents() as $document) {
            if ($stage === null || $document['stage'] === $stage) {
                $missing[] = $this->missingListedDocument($document, $report);
            }
        }

        return array_values(array_filter($missing));
    }

    /** @return array{issue: array<string, mixed>, name: string, category: string, final: bool}|null */
    private function missingRuleDocument(array $rule, CdaReport $report, ReportDefinition $definition): ?array
    {
        $category = (string) $rule['document_category'];
        $final = (bool) ($rule['final'] ?? false);

        if ($this->documentPresent($report, $category, $final)) {
            return null;
        }

        $label = self::documentLabel($category, $definition);
        $name = 'the '.self::lowerFirst($label).($final ? ' (final copy)' : '');
        $message = $rule['message'] ?? ucfirst($name).' '.self::uploadedVerb($label);

        return [
            'issue' => $this->issue('rule:'.$rule['id'], (string) $rule['id'], (string) $rule['severity'], (string) $rule['category'], (string) ($rule['component'] ?? 'fields'), $message, $this->ruleField($rule, $definition), $rule['fix_href'] ?? null, (string) $rule['stage']),
            'name' => $name,
            'category' => $category,
            'final' => $final,
        ];
    }

    /** @return array{issue: array<string, mixed>, name: string, category: string, final: bool}|null */
    private function missingListedDocument(array $document, CdaReport $report): ?array
    {
        $category = (string) $document['category'];

        if ($this->documentPresent($report, $category, false)) {
            return null;
        }

        $label = (string) ($document['label'] ?? $document['key']);

        return [
            'issue' => $this->issue('document:'.$document['key'], null, (string) $document['severity'], 'compliance_document', 'documents', $label.' '.self::uploadedVerb($label), null, '/cda/documents', (string) $document['stage']),
            'name' => (self::plural($label) ? '' : 'the ').self::lowerFirst($label),
            'category' => $category,
            'final' => false,
        ];
    }

    /** A category's document in words: the template's listed document of that category, else the category's own words. */
    private static function documentLabel(string $category, ReportDefinition $definition): string
    {
        foreach ($definition->documents() as $document) {
            if (($document['category'] ?? null) === $category && is_string($document['label'] ?? null)) {
                return $document['label'];
            }
        }

        return DocumentCategories::label($category);
    }

    /** "hasn't been uploaded." or, for a plural, "haven't been uploaded." */
    private static function uploadedVerb(string $label): string
    {
        return (self::plural($label) ? 'haven\'t' : 'hasn\'t').' been uploaded.';
    }

    /** Whether a label names more than one thing: its last word, outside brackets, is a plural ("certificates", not "CAPR" or "process"). */
    private static function plural(string $label): bool
    {
        $words = preg_split('/\s+/', trim((string) preg_replace('/\([^)]*\)/', '', $label))) ?: [];
        $last = (string) end($words);

        return preg_match('/[a-z]s$/', $last) === 1 && preg_match('/(ss|us|is)$/', $last) !== 1;
    }

    /**
     * A label inside a sentence: its first letter in lower case, unless the
     * first word is an acronym or the label is a title ("Signed CAPR" →
     * "signed CAPR"; "Annual Benefits Report (ABR)" stays as it is).
     */
    private static function lowerFirst(string $label): string
    {
        $words = preg_split('/\s+/', $label) ?: [];
        $title = collect(array_slice($words, 1))->contains(fn (string $word) => preg_match('/^[A-Z][a-z]/', $word) === 1);

        return ! $title && preg_match('/^[A-Z][a-z]/', $label) === 1 ? lcfirst($label) : $label;
    }

    /** A rule's issue category; the "still to be verified" checks always read under their own. */
    private static function category(array $rule): string
    {
        return ($rule['type'] ?? null) === 'check' && in_array($rule['check'] ?? null, self::UNVERIFIED_CHECKS, true)
            ? self::TEMPLATE_UNVERIFIED
            : (string) $rule['category'];
    }

    public function documentPresent(CdaReport $report, string $category, bool $final): bool
    {
        return CdaDocument::query()
            ->where('period_id', $report->period_id)
            ->where('category', $category)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('report_id')->orWhere('report_id', $report->id))
            ->whereHas('versions', fn ($q) => $final ? $q->whereNotNull('final_key') : $q)
            ->exists();
    }

    /** @param  array<string, mixed>  $values */
    private function runRule(array $rule, CdaReport $report, ReportDefinition $definition, array $values, SourceContext $context): ?string
    {
        $field = $this->ruleField($rule, $definition);
        $value = $field === null ? null : ($values[$field['full_key']] ?? null);
        $label = $field === null ? 'This field' : self::label($field);

        try {
            return match ($rule['type']) {
                'required' => FieldValue::filled($value) ? null : "{$label} is required.",
                'min' => $this->bound($value, $rule['value'], fn (int $c) => $c >= 0) ? null : "{$label} must be at least {$rule['value']}.",
                'max' => $this->bound($value, $rule['value'], fn (int $c) => $c <= 0) ? null : "{$label} must be at most {$rule['value']}.",
                'max_length' => ! FieldValue::filled($value) || mb_strlen((string) $value) <= (int) $rule['value'] ? null : "{$label} takes up to {$rule['value']} characters.",
                'one_of' => ! FieldValue::filled($value) || in_array($value, (array) $rule['values'], true) ? null : "{$label} must be one of ".implode(', ', (array) $rule['values']).'.',
                'pattern' => $this->matchesPattern($value, (string) $rule['pattern']) ? null : "{$label} isn't a valid ".self::PATTERN_WORDS[$rule['pattern']].'.',
                'date_range' => $this->dateRange($rule, $value, $report, $context, $label),
                'compare' => $this->compare($rule, $definition, $values, $context),
                'sum_equals' => $this->sumEquals($rule, $definition, $values),
                'check' => $this->checks->run((string) $rule['check'], (array) ($rule['params'] ?? []), $context, $definition),
                default => null,
            };
        } catch (OverflowException) {
            return "{$label}: the figures are too large to check exactly.";
        } catch (Throwable $e) {
            report($e);

            return 'This check couldn\'t be run: '.$e->getMessage();
        }
    }

    private function bound(mixed $value, int|float $limit, \Closure $ok): bool
    {
        $number = Rational::isNumeric($value) ? Rational::of($value) : null;

        return $number === null || $ok($number->compare(Rational::of($limit)));
    }

    private function matchesPattern(mixed $value, string $pattern): bool
    {
        if (! FieldValue::filled($value)) {
            return true;
        }

        $text = (string) $value;

        return match ($pattern) {
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => preg_match(self::PATTERNS['phone'], preg_replace('/[\s\-()]/', '', $text)) === 1,
            default => preg_match(self::PATTERNS[$pattern], strtoupper(trim($text))) === 1,
        };
    }

    /**
     * A date between the rule's bounds and never in the future. `from` and
     * `to` are each period_start, period_end, effective_deadline or today
     * (default: the period), so a sworn statement signed in the filing year
     * can run up to the report's effective deadline.
     */
    private function dateRange(array $rule, mixed $value, CdaReport $report, SourceContext $context, string $label): ?string
    {
        if (! FieldValue::filled($value)) {
            return null;
        }

        $date = (string) $value;
        $today = Carbon::today()->toDateString();
        $from = $this->dateBound((string) ($rule['from'] ?? 'period_start'), $report, $context);
        $to = $this->dateBound((string) ($rule['to'] ?? 'period_end'), $report, $context);

        if ($date > $today) {
            return "{$label} can't be in the future.";
        }

        if ($date < $from || $date > $to) {
            return sprintf('%s must be a date from %s to %s.', $label, Carbon::parse($from)->format('M j, Y'), Carbon::parse($to)->format('M j, Y'));
        }

        return null;
    }

    private function dateBound(string $token, CdaReport $report, SourceContext $context): string
    {
        return match ($token) {
            'effective_deadline' => Deadlines::effective($report, $context->period),
            'today' => Carbon::today()->toDateString(),
            'period_end' => $context->date('period_end'),
            default => $context->date('period_start'),
        };
    }

    private function compare(array $rule, ReportDefinition $definition, array $values, SourceContext $context): ?string
    {
        [$left, $leftLabel, $leftHidden] = $this->operand($rule['left'], $definition, $values, $context);
        [$right, $rightLabel, $rightHidden] = $this->operand($rule['right'], $definition, $values, $context);

        if ($left === null || $right === null) {
            return null;
        }

        $op = (string) $rule['op'];
        $tolerance = Rational::of($rule['tolerance'] ?? 0);
        $difference = $left->subtract($right)->abs();
        $holds = match ($op) {
            'eq' => $difference->compare($tolerance) <= 0,
            'ne' => $difference->compare($tolerance) > 0,
            'lt' => $left->compare($right) < 0,
            'lte' => $left->compare($right) <= 0,
            'gt' => $left->compare($right) > 0,
            'gte' => $left->compare($right) >= 0,
        };

        if ($holds) {
            return null;
        }

        if ($leftHidden !== null || $rightHidden !== null) {
            $leftWords = $leftHidden === null ? sprintf('%s (%s)', $leftLabel, $left->toFixed(2)) : "The figure in the {$leftHidden}";
            $rightWords = $rightHidden === null ? sprintf('%s (%s)', $rightLabel, $right->toFixed(2)) : "the {$rightHidden}";

            return $op === 'eq'
                ? "{$leftWords} doesn't match {$rightWords}."
                : sprintf('%s should %s %s.', $leftWords, self::OP_WORDS[$op], $rightHidden === null ? $rightWords : "the figure in the {$rightHidden}");
        }

        return sprintf('%s (%s) should %s %s (%s).', $leftLabel, $left->toFixed(2), self::OP_WORDS[$op], $rightLabel, $right->toFixed(2));
    }

    /** @return array{0: Rational|null, 1: string} */
    /**
     * An operand's figure, its label, and, when it is another report's figure
     * of a restricted type (mediation, audit, tax), that report's title: the
     * message then names the report but never shows its figure, since the
     * issue is read by people without that report's view permission.
     *
     * @return array{0: Rational|null, 1: string, 2: string|null}
     */
    private function operand(mixed $operand, ReportDefinition $definition, array $values, SourceContext $context): array
    {
        if (is_int($operand) || is_float($operand)) {
            return [Rational::of($operand), (string) $operand, null];
        }

        if (is_string($operand) && str_starts_with($operand, 'report:')) {
            $target = substr($operand, 7);
            $type = Str::before($target, '.');
            $result = $this->sources->resolve('report.field', ['type' => $type, 'field' => Str::after($target, '.')], $context);

            return [CalcEngine::number(is_array($result->value) ? null : $result->value), $result->label, self::restrictedTitle($type)];
        }

        if (is_string($operand) && str_starts_with($operand, 'source:')) {
            $result = $this->sources->resolve(substr($operand, 7), [], $context);

            return [CalcEngine::number(is_array($result->value) ? null : $result->value), $result->label, null];
        }

        if (is_array($operand) && isset($operand['source'])) {
            $key = (string) $operand['source']['key'];
            $params = (array) ($operand['source']['params'] ?? []);
            $result = $this->sources->resolve($key, $params, $context);
            $hidden = $key === 'report.field' && is_string($params['type'] ?? null) ? self::restrictedTitle($params['type']) : null;

            return [CalcEngine::number(is_array($result->value) ? null : $result->value), $result->label, $hidden];
        }

        if (is_array($operand)) {
            return [$this->calc->evaluate($operand, $this->valueOf($definition, $values)), 'the calculated figure', null];
        }

        $key = is_string($operand) ? $definition->resolve($operand) : null;

        return $key === null ? [null, '', null] : [CalcEngine::number($values[$key] ?? null), self::label($definition->field($key)), null];
    }

    /** A restricted report type's title, else null. */
    private static function restrictedTitle(string $type): ?string
    {
        return ReportTypes::exists($type) && ReportTypes::restriction($type) !== null ? ReportTypes::title($type) : null;
    }

    private function sumEquals(array $rule, ReportDefinition $definition, array $values): ?string
    {
        $totalKey = $definition->resolve((string) $rule['total']);
        $total = $totalKey === null ? null : CalcEngine::number($values[$totalKey] ?? null);

        if ($total === null) {
            return null;
        }

        $sum = Rational::of(0);
        $labels = [];

        foreach ((array) $rule['parts'] as $part) {
            $key = $definition->resolve((string) $part);
            $sum = $sum->add(CalcEngine::number($key === null ? null : ($values[$key] ?? null)) ?? Rational::of(0));
            $labels[] = $key === null ? (string) $part : self::label($definition->field($key));
        }

        if ($sum->equals($total)) {
            return null;
        }

        return sprintf('%s add up to %s, not %s (%s).', implode(' and ', $labels), self::plain($sum), self::label($definition->field($totalKey)), self::plain($total));
    }

    private function valueOf(ReportDefinition $definition, array $values): \Closure
    {
        return function (string $reference) use ($definition, $values): mixed {
            $key = $definition->resolve($reference);

            return $key === null ? null : ($values[$key] ?? null);
        };
    }

    private function ruleField(array $rule, ReportDefinition $definition): ?array
    {
        $reference = $rule['field'] ?? ($rule['type'] === 'sum_equals' ? ($rule['total'] ?? null) : null)
            ?? ($rule['type'] === 'compare' && is_string($rule['left'] ?? null) && ! str_contains((string) $rule['left'], ':') ? $rule['left'] : null);
        $key = is_string($reference) ? $definition->resolve($reference) : null;

        return $key === null ? null : $definition->field($key);
    }

    /** @return array<string, mixed> */
    private function issue(string $id, ?string $ruleId, string $severity, string $category, string $component, string $message, ?array $field, ?string $fixHref = null, ?string $stage = null): array
    {
        return [
            'id' => $id,
            'rule_id' => $ruleId,
            'severity' => $severity,
            'category' => $category,
            'component' => $component,
            'message' => $message,
            'section_key' => $field['section'] ?? null,
            'field_key' => $field['full_key'] ?? null,
            'fix_href' => $fixHref,
            'stage' => $stage,
        ];
    }

    public static function label(?array $field): string
    {
        return $field === null ? '' : (string) ($field['label'] ?? Str::headline((string) $field['key']));
    }

    private static function plain(Rational $value): string
    {
        return $value->round(0)->equals($value) ? (string) $value->toInt() : $value->toFixed(2);
    }

    /** A report's type and part in words, for messages. */
    public static function reportName(CdaReport $report): string
    {
        return ReportTypes::title($report->report_type).($report->part === ReportTypes::ANNUAL ? '' : ' ('.ReportTypes::PART_TITLES[$report->part].')');
    }
}
