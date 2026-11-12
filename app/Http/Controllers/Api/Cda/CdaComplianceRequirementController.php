<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaComplianceRequirementRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Http\Resources\Cda\RequirementPresenter;
use App\Models\CdaComplianceRequirement;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\LoanWriteTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Custom compliance calendar items (gap round B6): deadlines the cooperative
 * tracks beyond the ten CDA report types, per reporting year. Reading needs
 * `cda:view`, writing `cda:prepare`, both with `branches:view_all` (CDA
 * covers the whole cooperative). Every write is audited. The calendar lists
 * them next to the reports, and pending ones get the deadline reminders.
 */
class CdaComplianceRequirementController extends Controller
{
    private const CONFLICT = 'Another change to this requirement was saved at the same time. Reload and try again.';

    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/requirements',
        summary: 'A year\'s custom compliance requirements',
        description: 'Needs `cda:view` and `branches:view_all`. By effective due date (the extension, else the default). Each row: {kind: requirement, id, year, title, description, reference, status (pending, filed, not_applicable), display_status, applicable, filed, filed_on, default_due_on, extended_due_on, effective_deadline, days_remaining, created_by, updated_by, created_at, updated_at}. Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: requirement[], meta}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function index(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $this->period($year);

        $page = CdaComplianceRequirement::query()->with(['creator', 'updater'])->where('year', $year)
            ->orderByRaw('COALESCE(extended_due_on, default_due_on)')->orderBy('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));

        return RegisterPresenter::page($page, $page->getCollection()->map(fn (CdaComplianceRequirement $row) => RequirementPresenter::row($row))->all());
    }

    #[OA\Post(
        path: '/api/cda/periods/{year}/requirements',
        summary: 'Add a custom compliance requirement',
        description: 'Needs `cda:prepare` and `branches:view_all`. The extension can\'t fall before the due date; a filed item needs its filing date (not in the future), and only a filed item has one. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title', 'default_due_on'], properties: [
            new OA\Property(property: 'title', type: 'string', maxLength: 120),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'default_due_on', type: 'string', format: 'date'),
            new OA\Property(property: 'extended_due_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'reference', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: CdaComplianceRequirement::STATUSES),
            new OA\Property(property: 'filed_on', type: 'string', format: 'date', nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: '{success, message, data: requirement}'), new OA\Response(response: 403, description: 'Missing cda:prepare or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaComplianceRequirementRequest $request, int $year): JsonResponse
    {
        $this->period($year);
        $data = $this->data($request->validated());

        $requirement = CdaComplianceRequirement::query()->create([
            'status' => CdaComplianceRequirement::STATUS_PENDING,
            ...$data,
            'year' => $year,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return RegisterPresenter::one(RequirementPresenter::row($requirement->load(['creator', 'updater'])), 201, 'Requirement added.');
    }

    #[OA\Put(
        path: '/api/cda/requirements/{id}',
        summary: 'Change a custom compliance requirement',
        description: 'Needs `cda:prepare` and `branches:view_all`. Every field is optional; the rules apply to the result. Moving an item away from filed clears its filing date. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: '{success, message, data: requirement}'), new OA\Response(response: 403, description: 'Missing cda:prepare or branches:view_all'), new OA\Response(response: 404, description: 'No such requirement'), new OA\Response(response: 409, description: 'Another change was saved at the same time'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function update(CdaComplianceRequirementRequest $request, int $requirement): JsonResponse
    {
        $saved = LoanWriteTransaction::run(function () use ($request, $requirement): CdaComplianceRequirement {
            $locked = CdaComplianceRequirement::query()->whereKey($requirement)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such compliance requirement.');

            $locked->fill($this->data($request->validated()));

            if ($locked->status !== CdaComplianceRequirement::STATUS_FILED) {
                $locked->filed_on = null;
            }

            if ($locked->isDirty()) {
                $locked->updated_by = $request->user()->id;
                $locked->save();
            }

            return $locked;
        }, conflictMessage: self::CONFLICT);

        return RegisterPresenter::one(RequirementPresenter::row($saved->load(['creator', 'updater'])), message: 'Requirement saved.');
    }

    #[OA\Delete(
        path: '/api/cda/requirements/{id}',
        summary: 'Delete a custom compliance requirement',
        description: 'Needs `cda:prepare` and `branches:view_all`. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, message}'), new OA\Response(response: 403, description: 'Missing cda:prepare or branches:view_all'), new OA\Response(response: 404, description: 'No such requirement'), new OA\Response(response: 409, description: 'Another change was saved at the same time')],
    )]
    public function destroy(Request $request, int $requirement): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::PREPARE);

        LoanWriteTransaction::run(function () use ($requirement): void {
            $locked = CdaComplianceRequirement::query()->whereKey($requirement)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such compliance requirement.');

            $locked->delete();
        }, conflictMessage: self::CONFLICT);

        return response()->json(['success' => true, 'message' => 'Requirement deleted.']);
    }

    /**
     * The validated fields, trimmed; a blank optional text is null.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function data(array $validated): array
    {
        foreach (['title', 'description', 'reference'] as $field) {
            if (array_key_exists($field, $validated) && is_string($validated[$field])) {
                $validated[$field] = trim($validated[$field]) === '' && $field !== 'title' ? null : trim($validated[$field]);
            }
        }

        return $validated;
    }

    private function period(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }
}
