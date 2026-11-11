<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaSocialProgramRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaReportingPeriod;
use App\Models\CdaSocialProgram;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\RegisterStaleness;
use App\Services\Cda\Sources\SocialSource;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Social programs, per reporting period (CDA design §6). Reading needs
 * `cda:view`, writing `cda:manage_social`, both with `branches:view_all`.
 * Money is sent and returned as two-decimal strings and computed in
 * centavos; the list's `meta.totals` are the server's (held programs only,
 * as the reports count them), so the screen never adds money. A write marks
 * the period's draft reports reading social programs as stale.
 */
class CdaSocialProgramController extends Controller
{
    private const CONFLICT = 'Another change to this register was saved at the same time. Reload and try again.';

    public function __construct(
        private readonly CdaAccess $access,
        private readonly RegisterStaleness $staleness,
    ) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/social-programs',
        summary: 'A period\'s social programs, with the server\'s totals',
        description: 'Needs `cda:view` and `branches:view_all`. Filters: `category`, `status`. `meta.totals` {count, beneficiaries, budget, actual_expense, cdf_amount} cover the filtered programs that were held (ongoing or completed), as the social audit and ABR count them. Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaSocialProgram::CATEGORIES)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaSocialProgram::STATUSES)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: program[], meta: {…, totals}}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function index(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $period = $this->period($year);
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(CdaSocialProgram::CATEGORIES)],
            'status' => ['nullable', Rule::in(CdaSocialProgram::STATUSES)],
        ], ['category.in' => 'Choose a category from the list.', 'status.in' => 'Choose planned, ongoing, completed or cancelled.']);

        $query = fn (): Builder => CdaSocialProgram::query()->where('period_id', $period->id)
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        $page = $query()->with(['period', 'responsibleOfficer.member'])->orderByRaw('held_on IS NULL')->orderBy('held_on')->orderBy('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));
        $held = $query()->whereIn('status', SocialSource::HELD_STATUSES)->get(['actual_beneficiaries', 'budget', 'actual_expense', 'cdf_amount']);

        return RegisterPresenter::page($page, $page->getCollection()->map(fn (CdaSocialProgram $program) => RegisterPresenter::socialProgram($program))->all(), [
            'totals' => [
                'count' => $held->count(),
                'beneficiaries' => (int) $held->sum('actual_beneficiaries'),
                'budget' => CdaPresenter::money(SocialSource::centavos($held->pluck('budget')->all())),
                'actual_expense' => CdaPresenter::money(SocialSource::centavos($held->pluck('actual_expense')->all())),
                'cdf_amount' => CdaPresenter::money(SocialSource::centavos($held->pluck('cdf_amount')->all())),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/cda/periods/{year}/social-programs',
        summary: 'Record a social program',
        description: 'Needs `cda:manage_social` and `branches:view_all`. Money as pesos with at most two decimals, never below ₱0.00; the part from the CDF can\'t exceed the amount spent. Dates fall within the year. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'category'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'category', type: 'string', enum: CdaSocialProgram::CATEGORIES),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'objective', type: 'string', nullable: true),
            new OA\Property(property: 'held_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'ended_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'location', type: 'string', nullable: true),
            new OA\Property(property: 'target_beneficiaries', type: 'integer', nullable: true),
            new OA\Property(property: 'actual_beneficiaries', type: 'integer', nullable: true),
            new OA\Property(property: 'budget', type: 'string', example: '15000.00', nullable: true),
            new OA\Property(property: 'actual_expense', type: 'string', example: '12500.50'),
            new OA\Property(property: 'funding_source', type: 'string', enum: CdaSocialProgram::FUNDING_SOURCES, nullable: true),
            new OA\Property(property: 'cdf_amount', type: 'string', example: '10000.00'),
            new OA\Property(property: 'status', type: 'string', enum: CdaSocialProgram::STATUSES),
            new OA\Property(property: 'responsible_officer_id', type: 'integer', nullable: true),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 201, description: '{success, message, data: program}'), new OA\Response(response: 403, description: 'Missing cda:manage_social or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaSocialProgramRequest $request, int $year): JsonResponse
    {
        $period = $this->period($year);

        $program = LoanWriteTransaction::run(function () use ($request, $period): CdaSocialProgram {
            $program = CdaSocialProgram::query()->create([...$request->programData(), 'period_id' => $period->id]);
            $this->staleness->touchPeriod(RegisterStaleness::SOCIAL, $period->id);

            return $program;
        }, conflictMessage: self::CONFLICT);

        return RegisterPresenter::one(RegisterPresenter::socialProgram($program->load(['period', 'responsibleOfficer.member'])), 201, 'Program recorded.');
    }

    #[OA\Put(
        path: '/api/cda/social-programs/{id}',
        summary: 'Change a social program',
        description: 'Needs `cda:manage_social` and `branches:view_all`. Every field is optional; the rules apply to the result. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: '{success, message, data: program}'), new OA\Response(response: 403, description: 'Missing cda:manage_social or branches:view_all'), new OA\Response(response: 404, description: 'No such program'), new OA\Response(response: 409, description: 'Another change was saved at the same time (deadlock or lock wait)'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function update(CdaSocialProgramRequest $request, int $program): JsonResponse
    {
        $saved = LoanWriteTransaction::run(function () use ($request, $program): CdaSocialProgram {
            $locked = CdaSocialProgram::query()->whereKey($program)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such social program.');

            $locked->fill($request->programData());

            if ($locked->isDirty()) {
                $locked->save();
                $this->staleness->touchPeriod(RegisterStaleness::SOCIAL, $locked->period_id);
            }

            return $locked;
        }, conflictMessage: self::CONFLICT);

        return RegisterPresenter::one(RegisterPresenter::socialProgram($saved->load(['period', 'responsibleOfficer.member'])), message: 'Program saved.');
    }

    #[OA\Delete(
        path: '/api/cda/social-programs/{id}',
        summary: 'Delete a social program',
        description: 'Needs `cda:manage_social` and `branches:view_all`. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, message}'), new OA\Response(response: 403, description: 'Missing cda:manage_social or branches:view_all'), new OA\Response(response: 404, description: 'No such program'), new OA\Response(response: 409, description: 'Another change was saved at the same time (deadlock or lock wait)')],
    )]
    public function destroy(Request $request, int $program): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::MANAGE_SOCIAL);

        LoanWriteTransaction::run(function () use ($program): void {
            $locked = CdaSocialProgram::query()->whereKey($program)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such social program.');

            $locked->delete();
            $this->staleness->touchPeriod(RegisterStaleness::SOCIAL, $locked->period_id);
        }, conflictMessage: self::CONFLICT);

        return response()->json(['success' => true, 'message' => 'Program deleted.']);
    }

    private function period(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }
}
