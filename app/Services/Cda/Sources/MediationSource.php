<?php

namespace App\Services\Cda\Sources;

use App\Models\CdaMediationCase;
use App\Services\Cda\Registers\RegisterWords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mediation and conciliation cases as report figures (CDA design §2, §6).
 *
 * A case belongs to every half it overlaps: filed on or before the half's
 * end, and not closed before its start. With no `half` param the range is
 * the report's own part (a mediation half, or the whole year for CAPR).
 *
 * The case table carries the number, dates, nature, status and meeting
 * count, never the parties, the account of the dispute, the mediator or the
 * resolution: the least a report needs.
 */
final class MediationSource implements CdaSource
{
    private const LABEL = 'Mediation register';

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        [$from, $to] = $this->range($params['half'] ?? null, $context);

        return match ($key) {
            'mediation.cases' => new SourceResult(
                $this->cases($from, $to)->orderBy('filed_on')->orderBy('id')->get()->map(fn (CdaMediationCase $case) => [
                    'case_number' => $case->case_number,
                    'filed_on' => $case->filed_on->toDateString(),
                    'nature' => $case->nature,
                    'status' => RegisterWords::mediationStatus($case->status),
                    'status_key' => $case->status,
                    'mediation_started_on' => $case->mediation_started_on?->toDateString(),
                    'meetings_count' => (int) $case->meetings_count,
                    'resolved_on' => $case->resolved_on?->toDateString(),
                ])->all(),
                SourceResult::labelFor(self::LABEL, $to),
                'table',
                $to,
            ),
            'mediation.summary' => new SourceResult(
                $this->cases($from, $to)->when($params['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))->count(),
                SourceResult::labelFor(self::LABEL, $to),
                'count',
                $to,
            ),
            default => throw SourceUnavailable::unknownKey($key, 'mediation'),
        };
    }

    /** @return Builder<CdaMediationCase> */
    private function cases(string $from, string $to): Builder
    {
        return CdaMediationCase::query()->overlapping($from, $to);
    }

    /** @return array{0: string, 1: string} */
    private function range(?string $half, SourceContext $context): array
    {
        return match ($half) {
            'h1' => [$context->date('h1_start'), $context->date('h1_end')],
            'h2' => [$context->date('h2_start'), $context->date('h2_end')],
            default => [$context->date('half_start'), $context->date('half_end')],
        };
    }
}
