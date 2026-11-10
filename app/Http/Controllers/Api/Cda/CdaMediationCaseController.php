<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaMediationCaseRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaMediationCase;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\RegisterStaleness;
use App\Services\GeneratedNumberWrite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Mediation and conciliation cases (CDA design §6), restricted: reading
 * needs `cda:view_mediation`, writing `cda:manage_mediation`, both with
 * `branches:view_all`.
 *
 * - A case is numbered MC-YYYY-NNNN by its filing year, through
 *   GeneratedNumberWrite (two at once retry; still colliding, 409).
 * - It belongs to each half of a year it overlaps (`halves`).
 * - None of its text reaches an audit row: the model leaves every free-text
 *   field out, and nothing here writes one of its own.
 * - A write marks the draft reports reading mediation (CAPR, the halves it
 *   touches) as stale.
 */
class CdaMediationCaseController extends Controller
{
    private const HALVES = ['h1' => ['01-01', '06-30'], 'h2' => ['07-01', '12-31']];

    public function __construct(
        private readonly CdaAccess $access,
        private readonly RegisterStaleness $staleness,
    ) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/mediation-cases',
        summary: 'Mediation cases in a year, or in one half of it (restricted)',
        description: 'Needs `cda:view_mediation` and `branches:view_all`. A case is listed in each half it overlaps: filed on or before the half\'s end and not closed (`resolved_on`) before its start. `halves` names them for the year. Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'half', in: 'query', schema: new OA\Schema(type: 'string', enum: ['h1', 'h2'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: case[], meta}'), new OA\Response(response: 403, description: 'Missing cda:view_mediation or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 422, description: 'A half that isn\'t h1 or h2')],
    )]
    public function index(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW_MEDIATION);
        $period = $this->period($year);
        $half = $request->validate(['half' => ['nullable', 'in:h1,h2']], ['half.in' => 'Choose the first half (h1) or the second half (h2).'])['half'] ?? null;

        [$from, $to] = $half === null
            ? [$period->period_start->toDateString(), $period->period_end->toDateString()]
            : ["{$year}-".self::HALVES[$half][0], "{$year}-".self::HALVES[$half][1]];

        $page = CdaMediationCase::query()->overlapping($from, $to)->orderBy('filed_on')->orderBy('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));

        return RegisterPresenter::page($page, $page->getCollection()->map(fn (CdaMediationCase $case) => RegisterPresenter::mediationCase($case, $year))->all());
    }

    #[OA\Post(
        path: '/api/cda/periods/{year}/mediation-cases',
        summary: 'Record a mediation case (restricted)',
        description: 'Needs `cda:manage_mediation` and `branches:view_all`. The server gives the number (MC-YYYY-NNNN, by filing year). The filing date is never in the future; a resolved, escalated or withdrawn case needs its closing date (`resolved_on`), a pending one has none; the case must fall in the year. Its text never reaches the audit trail.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['filed_on', 'status'], properties: [
            new OA\Property(property: 'filed_on', type: 'string', format: 'date'),
            new OA\Property(property: 'complainant', type: 'string', nullable: true),
            new OA\Property(property: 'respondent', type: 'string', nullable: true),
            new OA\Property(property: 'nature', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'mediator', type: 'string', nullable: true),
            new OA\Property(property: 'mediation_started_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'meetings_count', type: 'integer'),
            new OA\Property(property: 'resolution', type: 'string', nullable: true),
            new OA\Property(property: 'resolved_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: CdaMediationCase::STATUSES),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: '{success, message, data: case}'), new OA\Response(response: 403, description: 'Missing cda:manage_mediation or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 409, description: 'Another case took the number at the same moment'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaMediationCaseRequest $request, int $year): JsonResponse
    {
        $this->period($year);

        $case = GeneratedNumberWrite::run(function () use ($request): CdaMediationCase {
            $case = CdaMediationCase::query()->create($request->validated());
            $this->touch($case);

            return $case;
        }, GeneratedNumberWrite::MEDIATION_CONFLICT);

        return RegisterPresenter::one(RegisterPresenter::mediationCase($case->refresh(), $year), 201, "Case {$case->case_number} recorded.");
    }

    #[OA\Put(
        path: '/api/cda/mediation-cases/{id}',
        summary: 'Change a mediation case (restricted)',
        description: 'Needs `cda:manage_mediation` and `branches:view_all`. Every field is optional; the rules apply to the result. The number never changes. `halves` are for the filing year.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: '{success, message, data: case}'), new OA\Response(response: 403, description: 'Missing cda:manage_mediation or branches:view_all'), new OA\Response(response: 404, description: 'No such case'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function update(CdaMediationCaseRequest $request, int $case): JsonResponse
    {
        $saved = DB::transaction(function () use ($request, $case): CdaMediationCase {
            $locked = CdaMediationCase::query()->whereKey($case)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such mediation case.');

            $this->touch($locked);
            $locked->fill($request->validated());

            if ($locked->isDirty()) {
                $locked->save();
                $this->touch($locked);
            }

            return $locked;
        });

        return RegisterPresenter::one(RegisterPresenter::mediationCase($saved, $saved->filed_on->year), message: "Case {$saved->case_number} saved.");
    }

    #[OA\Delete(
        path: '/api/cda/mediation-cases/{id}',
        summary: 'Delete a mediation case (restricted)',
        description: 'Needs `cda:manage_mediation` and `branches:view_all`. The audit row keeps the number, dates and status only.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, message}'), new OA\Response(response: 403, description: 'Missing cda:manage_mediation or branches:view_all'), new OA\Response(response: 404, description: 'No such case')],
    )]
    public function destroy(Request $request, int $case): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::MANAGE_MEDIATION);

        $number = DB::transaction(function () use ($case): string {
            $locked = CdaMediationCase::query()->whereKey($case)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such mediation case.');

            $locked->delete();
            $this->touch($locked);

            return $locked->case_number;
        });

        return response()->json(['success' => true, 'message' => "Case {$number} deleted."]);
    }

    private function period(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }

    private function touch(CdaMediationCase $case): void
    {
        $this->staleness->touch(RegisterStaleness::MEDIATION, $case->filed_on->toDateString(), $case->resolved_on?->toDateString());
    }
}
