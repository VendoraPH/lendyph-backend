<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\StoreCdaTemplateRequest;
use App\Http\Requests\Cda\UpdateCdaTemplateRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaReportTemplate;
use App\Models\CdaSetting;
use App\Models\Document;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\ReportTypes;
use App\Services\Cda\TemplateDefinitionValidator;
use App\Services\LoanWriteTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The CDA template manager (CDA design §2, §3). Reading needs `cda:view` and
 * `branches:view_all`; every write is super_admin's alone (a role check, so
 * admin's full permission set doesn't grant it).
 *
 * - A draft can be edited; activating it validates the definition and
 *   refuses (422) a range overlapping another active template of the type
 *   for the same cooperative types and asset classes.
 * - An active template is immutable (409); retire it, with a reason, and
 *   activate a new version. Reports keep the template they were created with.
 * - Writes take the CDA settings row's lock first, one at a time; a
 *   collision answers 409 (or 422 for a version taken), never 500.
 */
class CdaTemplateController extends Controller
{
    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/templates',
        summary: 'CDA report templates',
        description: 'Needs `cda:view` and `branches:view_all`. Filter with `report_type` and `status`. `verified` is false until a template has been checked against the official CDA form.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'report_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ReportTypes::ALL)),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: CdaReportTemplate::STATUSES)),
        ],
        responses: [new OA\Response(response: 200, description: 'The templates'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);
        $filters = $request->validate([
            'report_type' => ['nullable', Rule::in(ReportTypes::ALL)],
            'status' => ['nullable', Rule::in(CdaReportTemplate::STATUSES)],
        ]);

        $templates = CdaReportTemplate::query()
            ->with(['creator', 'excelTemplate'])
            ->when($filters['report_type'] ?? null, fn (Builder $q, string $type) => $q->where('report_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy('report_type')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $templates->map(fn (CdaReportTemplate $t) => CdaPresenter::template($t))->all()]);
    }

    #[OA\Get(
        path: '/api/cda/templates/{id}',
        summary: 'One CDA report template',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The template'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'), new OA\Response(response: 404, description: 'No such template')],
    )]
    public function show(Request $request, int $template): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        return response()->json(['success' => true, 'data' => CdaPresenter::template($this->find($template))]);
    }

    #[OA\Post(
        path: '/api/cda/templates',
        summary: 'Create a draft template (super_admin)',
        description: 'A new version for a report type, as a draft. The definition is checked in full on activation or with POST /cda/templates/validate.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['report_type', 'version', 'name', 'effective_from', 'definition'], properties: [
            new OA\Property(property: 'report_type', type: 'string', enum: ReportTypes::ALL),
            new OA\Property(property: 'version', type: 'string', example: '2027.1'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'effective_from', type: 'string', format: 'date'),
            new OA\Property(property: 'effective_until', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'cooperative_types', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'asset_classes', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'definition', type: 'object'),
        ])),
        responses: [new OA\Response(response: 201, description: 'The draft'), new OA\Response(response: 403, description: 'Not a super admin'), new OA\Response(response: 422, description: 'A version already used, or a bad field')],
    )]
    public function store(StoreCdaTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->write(function () use ($request): CdaReportTemplate {
                return CdaReportTemplate::query()->create([
                    ...$request->validated(),
                    'cooperative_types' => $request->validated('cooperative_types') ?? [],
                    'asset_classes' => $request->validated('asset_classes') ?? [],
                    'status' => CdaReportTemplate::STATUS_DRAFT,
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['version' => 'This report type already has a template with that version.']);
        }

        return response()->json(['success' => true, 'data' => CdaPresenter::template($template->refresh())], 201);
    }

    #[OA\Put(
        path: '/api/cda/templates/{id}',
        summary: 'Edit a draft template (super_admin)',
        description: 'Drafts only: an active or retired template answers 409.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The draft'), new OA\Response(response: 403, description: 'Not a super admin'), new OA\Response(response: 409, description: 'Not a draft')],
    )]
    public function update(UpdateCdaTemplateRequest $request, int $template): JsonResponse
    {
        $template = $this->find($template);

        try {
            $template = $this->write(function () use ($request, $template): CdaReportTemplate {
                $locked = $this->lockDraft($template);
                $locked->fill($request->validated());

                if ($locked->isDirty()) {
                    $locked->save();
                }

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['version' => 'This report type already has a template with that version.']);
        }

        return response()->json(['success' => true, 'data' => CdaPresenter::template($template->refresh())]);
    }

    #[OA\Post(
        path: '/api/cda/templates/{id}/activate',
        summary: 'Activate a draft template (super_admin)',
        description: 'Validates the definition (422 with `errors.definition`, in words) and refuses (422) a range that overlaps another active template of the same type for the same cooperative types and asset classes. Not a draft: 409.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The active template'), new OA\Response(response: 403, description: 'Not a super admin'), new OA\Response(response: 409, description: 'Not a draft'), new OA\Response(response: 422, description: 'Invalid definition or an overlap')],
    )]
    public function activate(Request $request, int $template, TemplateDefinitionValidator $validator): JsonResponse
    {
        $this->access->authorizeTemplateManager($request->user());
        $template = $this->find($template);

        $template = $this->write(function () use ($template, $validator): CdaReportTemplate {
            $locked = $this->lockDraft($template);

            $errors = $validator->validate($locked->definition ?? [], $locked->report_type);

            if ($errors !== []) {
                throw ValidationException::withMessages([
                    'definition' => array_map(fn (array $error) => "{$error['path']}: {$error['message']}", $errors),
                ]);
            }

            $overlap = $this->overlapping($locked);

            if ($overlap !== null) {
                throw ValidationException::withMessages([
                    'effective_from' => "It overlaps the active template {$overlap->version} ({$overlap->effective_from->toDateString()} to ".($overlap->effective_until?->toDateString() ?? 'open').'). Retire that one first, or change the dates.',
                ]);
            }

            $locked->forceFill(['status' => CdaReportTemplate::STATUS_ACTIVE, 'activated_at' => now()])->save();

            return $locked;
        });

        return response()->json(['success' => true, 'message' => 'Template activated.', 'data' => CdaPresenter::template($template->refresh())]);
    }

    #[OA\Post(
        path: '/api/cda/templates/{id}/retire',
        summary: 'Retire an active template (super_admin)',
        description: 'Needs a `reason`. It is never selected again; reports created with it keep it. Not active: 409.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'The retired template'), new OA\Response(response: 403, description: 'Not a super admin'), new OA\Response(response: 409, description: 'Not active'), new OA\Response(response: 422, description: 'No reason')],
    )]
    public function retire(Request $request, int $template): JsonResponse
    {
        $this->access->authorizeTemplateManager($request->user());
        $template = $this->find($template);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Give the reason the template is retired.'])['reason'];

        $template = $this->write(function () use ($template, $reason): CdaReportTemplate {
            $locked = CdaReportTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CdaReportTemplate::STATUS_ACTIVE) {
                throw LoanWriteTransaction::conflict("Only an active template can be retired; this one is {$locked->status}.");
            }

            $locked->forceFill(['status' => CdaReportTemplate::STATUS_RETIRED, 'reason' => $reason])->save();

            return $locked;
        });

        return response()->json(['success' => true, 'message' => 'Template retired.', 'data' => CdaPresenter::template($template->refresh())]);
    }

    #[OA\Post(
        path: '/api/cda/templates/{id}/excel-file',
        summary: 'Attach the official Excel workbook to a draft template (super_admin)',
        description: 'Multipart `file`, an .xlsx up to 10 MB, checked by its name and its contents. Stored on the private disk; the export fills its mapped cells. Not a draft: 409.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The template with `excel_template`'), new OA\Response(response: 403, description: 'Not a super admin'), new OA\Response(response: 409, description: 'Not a draft'), new OA\Response(response: 422, description: 'Not an .xlsx workbook')],
    )]
    public function excelFile(Request $request, int $template): JsonResponse
    {
        $this->access->authorizeTemplateManager($request->user());
        $template = $this->find($template);
        $request->validate(
            ['file' => ['required', 'file', 'max:10240', 'mimes:xlsx', 'extensions:xlsx']],
            ['file.mimes' => 'Upload the workbook as an .xlsx file.', 'file.extensions' => 'Upload the workbook as an .xlsx file.'],
        );

        if ($template->status !== CdaReportTemplate::STATUS_DRAFT) {
            throw LoanWriteTransaction::conflict('A workbook can be attached to a draft template only.');
        }

        $upload = $request->file('file');
        $path = $upload->storeAs('cda/templates/'.$template->id, Str::uuid()->toString().'.xlsx', 'private');

        try {
            $template = $this->write(function () use ($template, $upload, $path, $request): CdaReportTemplate {
                $locked = $this->lockDraft($template);
                $document = Document::query()->create([
                    'documentable_type' => CdaReportTemplate::class,
                    'documentable_id' => $locked->id,
                    'type' => 'cda_template_workbook',
                    'label' => 'CDA template workbook',
                    'file_path' => $path,
                    'original_filename' => Str::of((string) $upload->getClientOriginalName())->basename()->limit(200, '')->toString(),
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'file_size' => $upload->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);

                $locked->forceFill(['excel_template_document_id' => $document->id])->save();

                return $locked;
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);

            throw $e;
        }

        return response()->json(['success' => true, 'message' => 'Workbook attached.', 'data' => CdaPresenter::template($template->refresh())]);
    }

    #[OA\Post(
        path: '/api/cda/templates/validate',
        summary: 'Check a template definition without saving it (super_admin)',
        description: 'The dry-run: `{valid, errors: [{path, message}]}`, messages in words. Writes nothing.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['report_type', 'definition'], properties: [
            new OA\Property(property: 'report_type', type: 'string', enum: ReportTypes::ALL),
            new OA\Property(property: 'definition', type: 'object'),
        ])),
        responses: [new OA\Response(response: 200, description: 'The result'), new OA\Response(response: 403, description: 'Not a super admin')],
    )]
    public function validateDefinition(Request $request, TemplateDefinitionValidator $validator): JsonResponse
    {
        $this->access->authorizeTemplateManager($request->user());
        $input = $request->validate([
            'report_type' => ['required', Rule::in(ReportTypes::ALL)],
            'definition' => ['required', 'array'],
        ]);

        $errors = $validator->validate($input['definition'], $input['report_type']);

        return response()->json(['success' => true, 'data' => ['valid' => $errors === [], 'errors' => $errors]]);
    }

    /** The template, looked up only once the caller's permission is checked (C1 review, L4). */
    private function find(int $id): CdaReportTemplate
    {
        $template = CdaReportTemplate::query()->find($id);
        abort_if($template === null, 404, 'There is no such CDA template.');

        return $template;
    }

    /**
     * A template write, one at a time: it first locks the CDA settings row
     * (the parent of every template), and a deadlock or lock timeout answers
     * 409, never 500 (C1 review, L1).
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    private function write(\Closure $write): mixed
    {
        return LoanWriteTransaction::run(function () use ($write): mixed {
            CdaSetting::query()->whereKey(CdaSetting::current()->id)->lockForUpdate()->first();

            return $write();
        }, null, 'Another change to CDA templates was saved at the same moment. Reload and try again.');
    }

    private function lockDraft(CdaReportTemplate $template): CdaReportTemplate
    {
        $locked = CdaReportTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== CdaReportTemplate::STATUS_DRAFT) {
            throw LoanWriteTransaction::conflict("This template is {$locked->status} and can't be changed. Create a new version instead.");
        }

        return $locked;
    }

    /**
     * Another active template of the same type whose dates overlap and whose
     * cooperative types and asset classes overlap (an empty list is every one).
     */
    private function overlapping(CdaReportTemplate $template): ?CdaReportTemplate
    {
        $from = $template->effective_from->toDateString();
        $until = $template->effective_until?->toDateString();

        return CdaReportTemplate::query()
            ->where('report_type', $template->report_type)
            ->where('status', CdaReportTemplate::STATUS_ACTIVE)
            ->whereKeyNot($template->id)
            ->get()
            ->first(function (CdaReportTemplate $other) use ($from, $until, $template): bool {
                $otherFrom = $other->effective_from->toDateString();
                $otherUntil = $other->effective_until?->toDateString();
                $datesOverlap = ($until === null || $otherFrom <= $until) && ($otherUntil === null || $from <= $otherUntil);

                return $datesOverlap
                    && self::listsOverlap($template->cooperative_types, $other->cooperative_types)
                    && self::listsOverlap($template->asset_classes, $other->asset_classes);
            });
    }

    /** @param  list<string>|null  $a  @param  list<string>|null  $b */
    private static function listsOverlap(?array $a, ?array $b): bool
    {
        return $a === null || $a === [] || $b === null || $b === [] || array_intersect($a, $b) !== [];
    }
}
