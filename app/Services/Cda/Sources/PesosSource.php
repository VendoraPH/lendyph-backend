<?php

namespace App\Services\Cda\Sources;

use App\Services\Cda\Pesos\PesosAssessment;

/**
 * `pesos.result` {field} (CDA design §8): the year's Enhanced PESOS result,
 * for the PESOS report's own fields and any report that quotes it.
 *
 * Fields: `score` (2-decimal string, null until something is scored),
 * `rating` (null until every indicator is scored), `complete`, `verified`,
 * `indicators` and `components` (tables). Always provisional until a
 * verified template is active; `meta` keeps the template and the "to
 * verify" list.
 */
final class PesosSource implements CdaSource
{
    /** @var array<string, string> field → unit */
    public const FIELDS = [
        'score' => 'number',
        'rating' => 'text',
        'complete' => 'boolean',
        'verified' => 'boolean',
        'indicators' => 'table',
        'components' => 'table',
    ];

    public function __construct(private readonly PesosAssessment $assessment) {}

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        $field = (string) ($params['field'] ?? '');

        if ($key !== 'pesos.result') {
            throw SourceUnavailable::unknownKey($key, 'pesos');
        }

        if (! isset(self::FIELDS[$field])) {
            throw new SourceUnavailable("Enhanced PESOS has no field \"{$field}\"; use one of ".implode(', ', array_keys(self::FIELDS)).'.');
        }

        $result = $this->assessment->for($context);

        $value = match ($field) {
            'indicators' => array_map(fn (array $row): array => [
                'key' => $row['key'],
                'component' => $row['component'],
                'label' => $row['label'],
                'value' => $row['value'],
                'score' => $row['score'],
                'weight' => $row['weight'],
                'status' => $row['status'],
            ], $result['indicators']),
            'components' => $result['components'],
            default => $result[$field],
        };

        return new SourceResult(
            $value,
            SourceResult::labelFor('Enhanced PESOS (to verify)', $result['as_of']),
            self::FIELDS[$field],
            $result['as_of'],
            $result['provisional'],
            ['verified' => $result['verified'], 'template' => $result['template'], 'to_verify' => $result['to_verify']],
        );
    }
}
