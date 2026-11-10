<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaOfficerRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaOfficer;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\RegisterStaleness;
use App\Services\Cda\Registers\TrainingCompliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The officers register (CDA design §6). Reading needs `cda:view`, writing
 * `cda:manage_officers`, both with `branches:view_all`. A linked officer
 * shows the member's id, name, code and sex, nothing else, and stores none
 * of them. Each row reads its training compliance as of today. A write marks
 * the draft reports reading officers as stale; delete is soft.
 */
class CdaOfficerController extends Controller
{
    public function __construct(
        private readonly CdaAccess $access,
        private readonly TrainingCompliance $compliance,
        private readonly RegisterStaleness $staleness,
    ) {}

    #[OA\Get(
        path: '/api/cda/officers',
        summary: 'The officers register',
        description: 'Needs `cda:view` and `branches:view_all`. Filters: `year` (terms overlapping it), `status`, `position`. `trainings` reads the position\'s required trainings against completed ones as of today (`complete`, `incomplete` or `not_required`). Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaOfficer::STATUSES)),
            new OA\Parameter(name: 'position', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaOfficer::POSITIONS)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: officer[], meta}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 422, description: 'A filter that isn\'t valid')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2999'],
            'status' => ['nullable', Rule::in(CdaOfficer::STATUSES)],
            'position' => ['nullable', Rule::in(CdaOfficer::POSITIONS)],
        ], [
            'year.*' => 'Give the year as four digits.',
            'status.in' => 'Choose active, inactive, resigned or removed.',
            'position.in' => 'Choose a position from the list.',
        ]);

        $order = implode(',', array_map(fn (string $position) => DB::getPdo()->quote($position), CdaOfficer::POSITIONS));
        $page = CdaOfficer::query()->with(['member', 'trainings'])
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->servingBetween("{$year}-01-01", "{$year}-12-31"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['position'] ?? null, fn ($q, $position) => $q->where('position', $position))
            ->orderByRaw("FIELD(position, {$order})")->orderByDesc('term_start')->orderBy('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));

        return RegisterPresenter::page($page, $page->getCollection()->map(fn (CdaOfficer $officer) => $this->present($officer))->all());
    }

    #[OA\Post(
        path: '/api/cda/officers',
        summary: 'Add an officer to the register',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. Link a member with `borrower_id` (the name and sex come from the member; any sent are ignored), or give `name` and `sex`. Dates are YYYY-MM-DD; the term can\'t end before it starts. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['position', 'term_start'], properties: [
            new OA\Property(property: 'borrower_id', type: 'integer', nullable: true),
            new OA\Property(property: 'user_id', type: 'integer', nullable: true),
            new OA\Property(property: 'name', type: 'string', nullable: true),
            new OA\Property(property: 'sex', type: 'string', enum: CdaOfficer::SEXES, nullable: true),
            new OA\Property(property: 'position', type: 'string', enum: CdaOfficer::POSITIONS),
            new OA\Property(property: 'committee', type: 'string', nullable: true),
            new OA\Property(property: 'position_title', type: 'string', nullable: true),
            new OA\Property(property: 'term_start', type: 'string', format: 'date'),
            new OA\Property(property: 'term_end', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'election_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: CdaOfficer::STATUSES),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: '{success, message, data: officer}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaOfficerRequest $request): JsonResponse
    {
        $officer = DB::transaction(function () use ($request): CdaOfficer {
            $officer = CdaOfficer::query()->create($request->officerData());
            $this->touch($officer);

            return $officer;
        });

        return RegisterPresenter::one($this->present($officer->load(['member', 'trainings'])), 201, 'Officer added.');
    }

    #[OA\Put(
        path: '/api/cda/officers/{id}',
        summary: 'Change an officer',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. Every field is optional; the rules apply to the result. Linking a member clears the stored name and sex. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: '{success, message, data: officer}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 404, description: 'No such officer'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function update(CdaOfficerRequest $request, int $officer): JsonResponse
    {
        $saved = DB::transaction(function () use ($request, $officer): CdaOfficer {
            $locked = CdaOfficer::query()->whereKey($officer)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such officer in the register.');

            $this->touch($locked);
            $locked->fill($request->officerData());

            if ($locked->isDirty()) {
                $locked->save();
                $this->touch($locked);
            }

            return $locked;
        });

        return RegisterPresenter::one($this->present($saved->load(['member', 'trainings'])), message: 'Officer saved.');
    }

    #[OA\Delete(
        path: '/api/cda/officers/{id}',
        summary: 'Remove an officer from the register',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. A soft delete: the row stays for the audit trail and finalized reports. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, message}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 404, description: 'No such officer')],
    )]
    public function destroy(Request $request, int $officer): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::MANAGE_OFFICERS);

        DB::transaction(function () use ($officer): void {
            $locked = CdaOfficer::query()->whereKey($officer)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such officer in the register.');

            $locked->delete();
            $this->touch($locked);
        });

        return response()->json(['success' => true, 'message' => 'Officer removed from the register.']);
    }

    /** @return array<string, mixed> */
    private function present(CdaOfficer $officer): array
    {
        $today = now('Asia/Manila')->toDateString();

        return RegisterPresenter::officer($officer, $this->compliance->evaluate($officer, $today, $today));
    }

    private function touch(CdaOfficer $officer): void
    {
        $this->staleness->touch(RegisterStaleness::OFFICERS, $officer->term_start->toDateString(), $officer->term_end?->toDateString());
    }
}
