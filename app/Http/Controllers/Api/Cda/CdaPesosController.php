<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Pesos\PesosAssessment;
use App\Services\Cda\Sources\SourceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The year's Enhanced PESOS (CDA design §8): read only, computed by the
 * table-driven engine from the year's PESOS template, every result marked
 * "to verify" until the template is checked against the official tool.
 */
class CdaPesosController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/pesos',
        summary: 'Enhanced PESOS for a CDA year (to verify)',
        description: 'Needs `cda:view` and `branches:view_all`. `metrics`: each indicator with its formula, sources, numerator and denominator, value (a percent string, rounded half up to two decimals), score, weight, `status` (scored, missing_data or not_computable) and recommendation. `score` is the sum of the points scored; `rating` stays null until every indicator is scored. `components` add up their indicators. `trend`: the score of each earlier year\'s finalized PESOS snapshot, then this year\'s. `verified` is false and `to_verify` lists what to check against the official MC 2025-12 tool. Scores and weights are 2-decimal strings.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The assessment', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'year', type: 'integer', example: 2026),
                    new OA\Property(property: 'verified', type: 'boolean', example: false),
                    new OA\Property(property: 'provisional', type: 'boolean'),
                    new OA\Property(property: 'complete', type: 'boolean'),
                    new OA\Property(property: 'score', type: 'string', nullable: true, example: '78.00'),
                    new OA\Property(property: 'rating', type: 'string', nullable: true, example: 'Satisfactory'),
                    new OA\Property(property: 'components', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'),
                        new OA\Property(property: 'score', type: 'string', nullable: true), new OA\Property(property: 'weight', type: 'string'),
                    ])),
                    new OA\Property(property: 'metrics', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'component', type: 'string'),
                        new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'formula', type: 'string'),
                        new OA\Property(property: 'source', type: 'string'), new OA\Property(property: 'value', type: 'string', nullable: true),
                        new OA\Property(property: 'score', type: 'string', nullable: true), new OA\Property(property: 'weight', type: 'string'),
                        new OA\Property(property: 'status', type: 'string', enum: ['scored', 'missing_data', 'not_computable']),
                        new OA\Property(property: 'to_verify', type: 'boolean', example: true),
                    ])),
                    new OA\Property(property: 'trend', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'year', type: 'integer'), new OA\Property(property: 'score', type: 'string', nullable: true),
                    ])),
                    new OA\Property(property: 'to_verify', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'disclosure', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'),
            new OA\Response(response: 404, description: 'No period for that year'),
        ],
    )]
    public function show(Request $request, int $year, PesosAssessment $assessment): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        $result = $assessment->for(new SourceContext($period, $assessment->reportFor($period)));

        return response()->json(['success' => true, 'data' => [
            'year' => $result['year'],
            'as_of' => $result['as_of'],
            'template' => $result['template'],
            'verified' => $result['verified'],
            'provisional' => $result['provisional'],
            'complete' => $result['complete'],
            'score' => $result['score'],
            'rating' => $result['rating'],
            'components' => $result['components'],
            'metrics' => array_map(fn (array $row): array => [
                ...$row,
                'source' => implode(' ÷ ', array_values($row['sources'])),
            ], $result['indicators']),
            'trend' => $assessment->trend($period, $result['score']),
            'to_verify' => $result['to_verify'],
            'disclosure' => $result['disclosure'],
        ]]);
    }
}
