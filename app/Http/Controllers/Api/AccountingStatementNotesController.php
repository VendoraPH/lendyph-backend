<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CdaReport;
use App\Models\CdaReportingPeriod;
use App\Models\CdaReportSnapshot;
use App\Models\CdaReportTemplate;
use App\Services\BranchAccess;
use App\Services\Cda\Exports\SnapshotReader;
use App\Services\Cda\Pesos\PesosAssessment;
use App\Services\Cda\Pesos\PesosEngine;
use App\Services\Cda\Sources\PesosSource;
use App\Services\Cda\Sources\SourceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * What the notes to the financial statements quote from elsewhere (gap round
 * B4): the year's Enhanced PESOS rating, which CDA requires in the notes.
 *
 * - The PESOS report finalized (or later) for the year, with a current
 *   snapshot that isn't superseded: its frozen score and rating, and when it
 *   was finalized.
 * - Else the live result (PesosAssessment, the CDA PESOS screen's figures),
 *   marked `provisional`.
 * - With no CDA reporting period for the year (CDA isn't in use), every
 *   figure is null.
 *
 * `verified` is false, with the disclosure text, while the PESOS rules are
 * placeholders (PesosSource::verified(), the export note's rule). The figures
 * are the whole cooperative's, so this needs `accounting:view` and
 * `branches:view_all`. Read only.
 */
class AccountingStatementNotesController extends Controller
{
    #[OA\Get(
        path: '/api/accounting/statements/notes',
        summary: 'Notes to the financial statements: the Enhanced PESOS rating',
        description: 'Needs `accounting:view` and `branches:view_all` (cooperative-wide figures). `data.pesos`: `source` is `finalized` (the finalized PESOS report\'s snapshot, with `finalized_at`) or `live` (the current result, `provisional: true`), or null with every figure null when the year has no CDA reporting period. `score` is a 2-decimal string; `rating` stays null until every indicator is scored. `verified` is false and `disclosure` says so while the PESOS rules are still to verify against the official MC 2025-12 tool.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: false, description: 'The calendar year; this year (Manila) when absent.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The notes\' PESOS figures'),
            new OA\Response(response: 403, description: 'Missing accounting:view or branches:view_all'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function show(Request $request, PesosAssessment $assessment): JsonResponse
    {
        $this->authorize('accounting:view');
        abort_unless($request->user()->can(BranchAccess::VIEW_ALL), 403, 'The notes cover the whole cooperative, so they need access to every branch.');

        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2999']])['year'] ?? now()->year);
        $period = CdaReportingPeriod::query()->where('year', $year)->first();

        $pesos = $period === null ? $this->none($year) : ($this->finalized($period, $assessment) ?? $this->live($period, $assessment));

        return response()->json(['data' => ['pesos' => $pesos]]);
    }

    /** @return array<string, mixed> */
    private function none(int $year): array
    {
        return ['year' => $year, 'score' => null, 'rating' => null, 'source' => null, 'provisional' => null, 'finalized_at' => null, 'verified' => false, 'disclosure' => null];
    }

    /** @return array<string, mixed>|null */
    private function finalized(CdaReportingPeriod $period, PesosAssessment $assessment): ?array
    {
        $report = $assessment->reportFor($period);

        if ($report === null || ! in_array($report->status, CdaReport::LOCKED_STATUSES, true) || $report->current_snapshot_id === null) {
            return null;
        }

        $snapshot = CdaReportSnapshot::query()->whereKey($report->current_snapshot_id)->whereNull('superseded_at')->first();

        if ($snapshot === null) {
            return null;
        }

        $payload = SnapshotReader::payload($snapshot);
        $values = SnapshotReader::values($payload);
        $definition = SnapshotReader::definition($payload);
        $templateVerified = $definition !== null
            ? ($definition['meta']['verified'] ?? false) === true
            : (bool) CdaReportTemplate::query()->find($snapshot->template_id)?->isVerified();
        $sourceValues = array_map(fn ($row) => is_array($row) ? ($row['source_value'] ?? null) : null, array_values((array) ($payload['values'] ?? [])));
        $verified = PesosSource::verified($templateVerified, $sourceValues);

        return [
            'year' => (int) $period->year,
            'score' => self::text($values['result.score'] ?? null),
            'rating' => self::text($values['result.rating'] ?? null),
            'source' => 'finalized',
            'provisional' => false,
            'finalized_at' => $snapshot->finalized_at?->toJSON(),
            'verified' => $verified,
            'disclosure' => $verified ? null : PesosEngine::DISCLOSURE,
        ];
    }

    /** @return array<string, mixed> */
    private function live(CdaReportingPeriod $period, PesosAssessment $assessment): array
    {
        $result = $assessment->for(new SourceContext($period, $assessment->reportFor($period)));
        $verified = $result['verified'] && $result['to_verify'] === [];

        return [
            'year' => (int) $period->year,
            'score' => $result['score'],
            'rating' => $result['rating'],
            'source' => 'live',
            'provisional' => true,
            'finalized_at' => null,
            'verified' => $verified,
            'disclosure' => $verified ? null : $result['disclosure'],
        ];
    }

    private static function text(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
