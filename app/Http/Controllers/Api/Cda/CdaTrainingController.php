<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaTrainingRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaDocument;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\DocumentCategories;
use App\Services\Cda\Registers\DocumentRepository;
use App\Services\Cda\Registers\RegisterStaleness;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Officer trainings (CDA design §6). Reading needs `cda:view`, writing
 * `cda:manage_officers`, both with `branches:view_all`.
 *
 * A certificate is a CDA document (category `certificates`, the training as
 * its subject, in the period of the year it was completed) and each upload a
 * new version of it, never overwriting the last; `certificate.url` is a
 * signed link. Deleting a training withdraws its certificate and keeps the
 * file. A certificate files under the period of the year it was completed,
 * else the nearest one; with no period at all it is refused (422). A write
 * marks the draft reports reading officers as stale, and a collision answers
 * 409, never 500.
 */
class CdaTrainingController extends Controller
{
    private const DELETED_REASON = 'The training was deleted.';

    public const NO_PERIOD = 'Open a CDA reporting period first, so the certificate has a year to be filed under.';

    private const CONFLICT = 'Another change to this register was saved at the same time. Reload and try again.';

    public function __construct(
        private readonly CdaAccess $access,
        private readonly DocumentRepository $documents,
        private readonly RegisterStaleness $staleness,
    ) {}

    #[OA\Get(
        path: '/api/cda/trainings',
        summary: 'Officer trainings',
        description: 'Needs `cda:view` and `branches:view_all`. Filters: `officer_id`, `year` (completed in it, else started in it), `status`. Hours are two-decimal strings; `certificate` holds the latest version with a signed `url` (30 minutes). Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'officer_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'year', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaOfficerTraining::STATUSES)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: training[], meta}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 422, description: 'A filter that isn\'t valid')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $filters = $request->validate([
            'officer_id' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2999'],
            'status' => ['nullable', Rule::in(CdaOfficerTraining::STATUSES)],
        ], [
            'officer_id.integer' => 'Choose an officer from the register.',
            'year.*' => 'Give the year as four digits.',
            'status.in' => 'Choose completed, incomplete, scheduled, expired or not required.',
        ]);

        $page = CdaOfficerTraining::query()->with('officer.member')
            ->when($filters['officer_id'] ?? null, fn ($q, $id) => $q->where('officer_id', $id))
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->whereRaw('YEAR(COALESCE(completed_on, started_on)) = ?', [$year]))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw('COALESCE(completed_on, started_on) IS NULL')->orderByDesc(DB::raw('COALESCE(completed_on, started_on)'))->orderByDesc('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));

        $certificates = $this->certificates($page->getCollection()->pluck('id')->all());
        $users = RegisterPresenter::usersFor($certificates);

        return RegisterPresenter::page($page, $page->getCollection()
            ->map(fn (CdaOfficerTraining $training) => RegisterPresenter::training($training, $certificates->get($training->id), $users))->all());
    }

    #[OA\Post(
        path: '/api/cda/trainings',
        summary: 'Record an officer training, with its certificate',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. Multipart (or JSON without a certificate). A completed training needs `completed_on`; hours have at most two decimals. `certificate`: up to 10 MB, a picture, PDF, Word document or Excel workbook, judged by its contents and its name. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['officer_id', 'title'], properties: [
            new OA\Property(property: 'officer_id', type: 'integer'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'requirement_key', type: 'string', nullable: true),
            new OA\Property(property: 'training_type', type: 'string', nullable: true),
            new OA\Property(property: 'is_mandatory', type: 'boolean'),
            new OA\Property(property: 'provider', type: 'string', nullable: true),
            new OA\Property(property: 'provider_accreditation_no', type: 'string', nullable: true),
            new OA\Property(property: 'started_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'completed_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'hours', type: 'string', example: '8.00'),
            new OA\Property(property: 'certificate_number', type: 'string', nullable: true),
            new OA\Property(property: 'status', type: 'string', enum: CdaOfficerTraining::STATUSES),
            new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'remarks', type: 'string', nullable: true),
            new OA\Property(property: 'certificate', type: 'string', format: 'binary'),
        ]))),
        responses: [new OA\Response(response: 201, description: '{success, message, data: training}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaTrainingRequest $request): JsonResponse
    {
        $training = LoanWriteTransaction::run(function () use ($request): CdaOfficerTraining {
            $training = CdaOfficerTraining::query()->create($request->trainingData());
            $this->attach($training, $request->file('certificate'), $request->user());
            $this->touch($training);

            return $training;
        }, conflictMessage: self::CONFLICT);

        return RegisterPresenter::one($this->present($training), 201, 'Training recorded.');
    }

    #[OA\Put(
        path: '/api/cda/trainings/{id}',
        summary: 'Change a training, or add a new certificate version',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. Every field is optional; the rules apply to the result. To send a new `certificate`, use multipart POST with `_method=PUT`: it is added as a new version, never replacing the last. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [new OA\Response(response: 200, description: '{success, message, data: training}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 404, description: 'No such training'), new OA\Response(response: 409, description: 'Another change was saved at the same time (deadlock or lock wait)'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function update(CdaTrainingRequest $request, int $training): JsonResponse
    {
        $saved = LoanWriteTransaction::run(function () use ($request, $training): CdaOfficerTraining {
            $locked = CdaOfficerTraining::query()->whereKey($training)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such training.');

            $this->touch($locked);
            $locked->fill($request->trainingData());

            if ($locked->isDirty()) {
                $locked->save();
                $this->touch($locked);
            }

            $this->attach($locked, $request->file('certificate'), $request->user());

            return $locked;
        }, conflictMessage: self::CONFLICT);

        return RegisterPresenter::one($this->present($saved), message: 'Training saved.');
    }

    #[OA\Delete(
        path: '/api/cda/trainings/{id}',
        summary: 'Delete a training',
        description: 'Needs `cda:manage_officers` and `branches:view_all`. Its certificate is withdrawn ("The training was deleted."), never deleted: every file stays. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, message}'), new OA\Response(response: 403, description: 'Missing cda:manage_officers or branches:view_all'), new OA\Response(response: 404, description: 'No such training'), new OA\Response(response: 409, description: 'Another change was saved at the same time (deadlock or lock wait)')],
    )]
    public function destroy(Request $request, int $training): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::MANAGE_OFFICERS);

        LoanWriteTransaction::run(function () use ($training): void {
            $locked = CdaOfficerTraining::query()->whereKey($training)->lockForUpdate()->first();
            abort_if($locked === null, 404, 'There is no such training.');

            $this->certificateQuery([$locked->id])->where('status', 'active')->get()
                ->each(fn (CdaDocument $document) => $this->documents->withdraw($document, self::DELETED_REASON));
            $this->touch($locked);
            $locked->delete();
        }, conflictMessage: self::CONFLICT);

        return response()->json(['success' => true, 'message' => 'Training deleted. Its certificate was withdrawn and kept.']);
    }

    /** A new certificate version: on the training's active certificate, else a new one. */
    private function attach(CdaOfficerTraining $training, ?UploadedFile $file, User $user): void
    {
        if ($file === null) {
            return;
        }

        $existing = $this->certificateQuery([$training->id])->where('status', 'active')->orderByDesc('id')->first();

        if ($existing !== null) {
            $this->documents->addVersion($existing, $file, $user);

            return;
        }

        $year = ($training->completed_on ?? $training->started_on ?? now('Asia/Manila'))->year;
        $period = CdaReportingPeriod::query()->orderByRaw('ABS(CAST(`year` AS SIGNED) - ?)', [$year])->orderBy('year')->first();

        if ($period === null) {
            throw ValidationException::withMessages(['certificate' => self::NO_PERIOD]);
        }

        $this->documents->create([
            'period_id' => $period->id,
            'category' => DocumentCategories::CERTIFICATES,
            'subject_type' => 'training',
            'subject_id' => $training->id,
            'title' => Str::limit('Training certificate: '.$training->title, 255, ''),
        ], $file, $user);
    }

    /** @return array<string, mixed> */
    private function present(CdaOfficerTraining $training): array
    {
        $training->load('officer.member');

        return RegisterPresenter::training($training, $this->certificates([$training->id])->get($training->id));
    }

    /**
     * Each training's latest certificate document, with its versions.
     *
     * @param  list<int>  $ids
     * @return Collection<int, CdaDocument> keyed by training id
     */
    private function certificates(array $ids): Collection
    {
        return $this->certificateQuery($ids)->with('versions.file')->orderByRaw("status = 'active'")->orderBy('id')->get()->keyBy('subject_id');
    }

    /** @param  list<int>  $ids */
    private function certificateQuery(array $ids): Builder
    {
        return CdaDocument::query()->where('subject_type', 'training')->whereIn('subject_id', $ids)->where('category', DocumentCategories::CERTIFICATES);
    }

    private function touch(CdaOfficerTraining $training): void
    {
        $officer = CdaOfficer::withTrashed()->find($training->officer_id);

        if ($officer !== null) {
            $this->staleness->touch(RegisterStaleness::OFFICERS, $officer->term_start->toDateString(), $officer->term_end?->toDateString());
        }
    }
}
