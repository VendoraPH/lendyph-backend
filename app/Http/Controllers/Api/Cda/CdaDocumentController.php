<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\Registers\CdaDocumentRequest;
use App\Http\Requests\Cda\Registers\CdaVersionRequest;
use App\Http\Resources\Cda\RegisterPresenter;
use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use App\Models\CdaReportingPeriod;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\DocumentCategories;
use App\Services\Cda\Registers\DocumentRepository;
use App\Services\LoanWriteTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * The compliance document repository (CDA design §1, §3). Reading needs
 * `cda:view`, uploading and withdrawing `cda:upload`, both with
 * `branches:view_all`. Audit, tax and mediation documents (by category, by
 * report or by subject) also need their view permission: the list leaves
 * them out, and opening or changing one answers 404, as a missing one does,
 * so ids can't be probed. A report export changes only by exporting again.
 *
 * A register's document (a certificate, a proof, a mediation paper) also
 * needs that register's write permission to upload to or withdraw
 * (DocumentRepository::writePermissionFor()).
 *
 * Every upload is a new version, never overwriting one; `final` makes it the
 * one final version; withdrawing keeps every file. Files are reached only by
 * a signed `url` (30 minutes, bound to the user). Uploads take the member
 * document types plus .xls and .xlsx, up to 10 MB.
 */
class CdaDocumentController extends Controller
{
    public function __construct(
        private readonly CdaAccess $access,
        private readonly DocumentRepository $documents,
    ) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/documents',
        summary: 'A period\'s compliance documents',
        description: 'Needs `cda:view` and `branches:view_all`. Documents the caller can\'t open (audit, tax, mediation) are left out. Filters: `category`, `status` (active, withdrawn), `report_id`, `subject_type`, and `subject_id` with `subject_type` (one training, program, case or submission\'s documents; 422 without the type). Each row has `current_version` and `final_version` with a signed `url`. Paged (`per_page` up to 100).',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'category', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaDocument::STATUSES)),
            new OA\Parameter(name: 'report_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subject_type', in: 'query', schema: new OA\Schema(type: 'string', enum: CdaDocument::SUBJECT_TYPES)),
            new OA\Parameter(name: 'subject_id', in: 'query', description: 'With `subject_type` only.', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', maximum: 100)),
        ],
        responses: [new OA\Response(response: 200, description: '{success, data: document[], meta}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No period for that year')],
    )]
    public function index(Request $request, int $year): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $period = $this->period($year);
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(DocumentCategories::keys())],
            'status' => ['nullable', Rule::in(CdaDocument::STATUSES)],
            'report_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in(CdaDocument::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'category.in' => 'Choose a category from the list.',
            'status.in' => 'Choose active or withdrawn.',
            'report_id.integer' => 'Choose a report from the list.',
            'subject_type.in' => 'Choose training, program, case or submission.',
            'subject_type.required_with' => 'Say which register the record belongs to (training, program, case or submission).',
            'subject_id.integer' => 'Choose a record from the register.',
            'subject_id.min' => 'Choose a record from the register.',
        ]);

        $query = CdaDocument::query()->where('period_id', $period->id)
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['report_id'] ?? null, fn ($q, $id) => $q->where('report_id', $id))
            ->when($filters['subject_type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id));
        $this->documents->scopeVisible($query, $request->user());

        $page = $query->with(['period', 'report', 'versions.file'])->orderBy('category')->orderByDesc('id')
            ->paginate(RegisterPresenter::perPage($request->query('per_page')));

        $users = RegisterPresenter::usersFor($page->getCollection());

        return RegisterPresenter::page($page, $page->getCollection()->map(fn (CdaDocument $document) => RegisterPresenter::document($document, $users))->all());
    }

    #[OA\Post(
        path: '/api/cda/periods/{year}/documents',
        summary: 'Upload a new compliance document',
        description: 'Needs `cda:upload` and `branches:view_all`, plus the view permission of a restricted category, report or case, and a register\'s write permission for its documents (`cda:manage_officers` for certificates and trainings, `cda:manage_mediation` for mediation papers and cases), checked before anything is looked up (403). A training\'s certificate is added from the training (422). Multipart: `file` (up to 10 MB; a picture, PDF, Word document or Excel workbook, judged by its contents and its name), `title`, `category`, optional `report_id` (one of the period\'s), `subject_type` + `subject_id` (training, program or case), `description`, `expires_on`, `version_label`, `notes`, `final`. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file', 'title', 'category'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'category', type: 'string', description: 'capr, financial_statements, audit_report, performance_audit, social_audit, pesos, officers_trainings, mediation, sworn_statement, atir, abr, tax, minutes, certificates or other'),
            new OA\Property(property: 'report_id', type: 'integer', nullable: true),
            new OA\Property(property: 'subject_type', type: 'string', enum: CdaDocumentRequest::SUBJECT_TYPES, nullable: true),
            new OA\Property(property: 'subject_id', type: 'integer', nullable: true),
            new OA\Property(property: 'description', type: 'string', nullable: true),
            new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'version_label', type: 'string', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'final', type: 'boolean'),
        ]))),
        responses: [new OA\Response(response: 201, description: '{success, message, data: document}'), new OA\Response(response: 403, description: 'Missing cda:upload, branches:view_all or the restricted view permission'), new OA\Response(response: 404, description: 'No period for that year'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function store(CdaDocumentRequest $request, int $year): JsonResponse
    {
        $period = $this->period($year);
        $data = $request->validated();
        $draft = new CdaDocument([
            'period_id' => $period->id,
            'category' => $data['category'],
            'report_id' => $data['report_id'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
        ]);
        $this->documents->authorizeSee($request->user(), $draft);
        $this->documents->authorizeWrite($request->user(), $draft->category, $draft->subject_type);

        $document = $this->documents->create(
            $draft->only(['period_id', 'category', 'report_id', 'subject_type', 'subject_id', 'title', 'description', 'expires_on']),
            $request->file('file'),
            $request->user(),
            $data['version_label'] ?? null,
            $data['notes'] ?? null,
            (bool) ($data['final'] ?? false),
        );

        return RegisterPresenter::one($this->present($document), 201, 'Document uploaded.');
    }

    #[OA\Get(
        path: '/api/cda/documents/{id}/versions',
        summary: 'Every version of a document, newest first',
        description: 'Needs `cda:view` and `branches:view_all`, plus the view permission of a restricted document (403). Each version has a signed `url` (30 minutes) and `is_final`; `meta.document` is the document.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{success, data: version[], meta: {document}}'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No such document, or one the caller may not see (mediation, audit, tax)')],
    )]
    public function versions(Request $request, int $cdaDocument): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $found = $this->find($request->user(), $cdaDocument);
        $users = RegisterPresenter::usersFor([$found]);

        return response()->json([
            'success' => true,
            'data' => $found->versions->sortByDesc('version')->values()->map(fn (CdaDocumentVersion $version) => RegisterPresenter::version($version, $users))->all(),
            'meta' => ['document' => RegisterPresenter::document($found, $users)],
        ]);
    }

    #[OA\Post(
        path: '/api/cda/documents/{id}/versions',
        summary: 'Upload a new version of a document',
        description: 'Needs `cda:upload` and `branches:view_all`, plus the view permission of a restricted document and, for a register\'s document, its write permission (`cda:submit` for a proof, `cda:manage_officers` for a certificate, `cda:manage_mediation` for mediation) (403). Multipart `file`, optional `version_label`, `notes`, `final` (makes it the one final version). Never replaces an older version. A withdrawn document answers 409. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(required: ['file'], properties: [
            new OA\Property(property: 'file', type: 'string', format: 'binary'),
            new OA\Property(property: 'version_label', type: 'string', nullable: true),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
            new OA\Property(property: 'final', type: 'boolean'),
        ]))),
        responses: [new OA\Response(response: 201, description: '{success, message, data: document}'), new OA\Response(response: 403, description: 'Missing cda:upload, branches:view_all or the register\'s write permission'), new OA\Response(response: 404, description: 'No such document, or one the caller may not see (mediation, audit, tax)'), new OA\Response(response: 409, description: 'The document is withdrawn, or is a report export (export the report again)'), new OA\Response(response: 422, description: 'Refused, in words')],
    )]
    public function addVersion(CdaVersionRequest $request, int $cdaDocument): JsonResponse
    {
        $found = $this->find($request->user(), $cdaDocument);
        $this->refuseExport($found);
        $this->documents->authorizeWrite($request->user(), $found->category, $found->subject_type);
        $data = $request->validated();

        $this->documents->addVersion($found, $request->file('file'), $request->user(), $data['version_label'] ?? null, $data['notes'] ?? null, (bool) ($data['final'] ?? false));

        return RegisterPresenter::one($this->present($found), 201, 'New version uploaded.');
    }

    #[OA\Post(
        path: '/api/cda/documents/{id}/withdraw',
        summary: 'Withdraw a document, keeping every file',
        description: 'Needs `cda:upload` and `branches:view_all`, plus the view permission of a restricted document and, for a register\'s document, its write permission (403). `reason` is required (up to 500). Already withdrawn: 409. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: '{success, message, data: document}'), new OA\Response(response: 403, description: 'Missing cda:upload, branches:view_all or the register\'s write permission'), new OA\Response(response: 404, description: 'No such document, or one the caller may not see (mediation, audit, tax)'), new OA\Response(response: 409, description: 'Already withdrawn, or a report export (export the report again)'), new OA\Response(response: 422, description: 'No reason')],
    )]
    public function withdraw(Request $request, int $cdaDocument): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::UPLOAD);
        $found = $this->find($request->user(), $cdaDocument);
        $this->refuseExport($found);
        $this->documents->authorizeWrite($request->user(), $found->category, $found->subject_type);
        $reason = (string) $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Give the reason the document is withdrawn.', 'reason.string' => 'Give the reason the document is withdrawn.', 'reason.max' => 'The reason can be at most 500 characters.'],
        )['reason'];

        $this->documents->withdraw($found, $reason);

        return RegisterPresenter::one($this->present($found), message: 'Document withdrawn. Its files are kept.');
    }

    /** A server export changes only by exporting the report again (409). */
    private function refuseExport(CdaDocument $document): void
    {
        if ($document->category === DocumentCategories::REPORT_EXPORT) {
            throw LoanWriteTransaction::conflict('Export the report again.');
        }
    }

    /** The document, or 404 when it is missing or the user may not see it, so ids can't be probed. */
    private function find(User $user, int $id): CdaDocument
    {
        $document = CdaDocument::query()->with(['period', 'report', 'versions.file'])->find($id);
        abort_if($document === null || ! $this->documents->canSee($user, $document), 404, 'There is no such document.');

        return $document;
    }

    /** @return array<string, mixed> */
    private function present(CdaDocument $document): array
    {
        return RegisterPresenter::document($document->fresh(['period', 'report', 'versions.file']));
    }

    private function period(int $year): CdaReportingPeriod
    {
        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        return $period;
    }
}
