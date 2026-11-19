<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UploadFileTypes;
use App\Http\Resources\Amla\AmlaCasePresenter;
use App\Http\Resources\Amla\AmlaDetectionPresenter;
use App\Models\AmlaCase;
use App\Models\AmlaCaseEvidence;
use App\Services\Amla\Cases\CaseRecords;
use App\Services\Amla\Cases\EvidenceStore;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Cases\Filings;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\DownloadFilename;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMLA cases (AMLA-B6; task §12–13). Every route needs `amla:cases` and
 * `branches:view_all`, checked before the case is looked up (preparing an
 * STR needs `amla:str` too). Every view and download is in the AMLA log,
 * which names ids only. Nothing about a case leaves AMLA.
 */
class AmlaCaseController extends Controller
{
    private const EVIDENCE_KILOBYTES = 10240;

    public function __construct(
        private readonly AmlaAccess $access,
        private readonly CaseRecords $cases,
        private readonly AmlaLog $log,
        private readonly AmlaDetectionPresenter $alerts,
    ) {}

    #[OA\Get(path: '/api/amla/cases', summary: 'AMLA cases', description: 'Needs `amla:cases` and `branches:view_all`. Newest first; filters `status`, `search` (reference). 25 a page. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The cases')])]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCases($request);
        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_keys(AmlaCase::STATUS_WORDS))],
            'search' => ['sometimes', 'nullable', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $this->log->record('cases_viewed', $request->user(), details: ['status' => $filters['status'] ?? null, 'searched' => filled($filters['search'] ?? null)]);

        $page = AmlaCase::query()->with(['borrower', 'walkIn', 'branch', 'assignee', 'opener'])
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('reference', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaCase $case) => AmlaCasePresenter::caseRow($case))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Post(path: '/api/amla/cases', summary: 'Open an AMLA case', description: 'Needs `amla:cases` and `branches:view_all`. `title`, and `borrower_id` (a member) or `gcash_non_member_id` (a walk-in). Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The case')])]
    public function store(Request $request): JsonResponse
    {
        $this->authorizeCases($request);
        $input = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'borrower_id' => ['nullable', 'integer', 'min:1', 'required_without:gcash_non_member_id'],
            'gcash_non_member_id' => ['nullable', 'integer', 'min:1', 'required_without:borrower_id'],
        ], ['borrower_id.required_without' => 'Pick the member or walk-in the case is about.']);

        if (! CaseRecords::subjectExists($input['borrower_id'] ?? null, isset($input['borrower_id']) ? null : ($input['gcash_non_member_id'] ?? null))) {
            throw ValidationException::withMessages(['borrower_id' => 'Pick a member or walk-in on record.']);
        }

        return $this->detail($this->cases->open($request->user(), $input), 201);
    }

    #[OA\Get(path: '/api/amla/cases/{id}', summary: 'One AMLA case', description: 'Needs `amla:cases` and `branches:view_all`. Its alerts, notes, evidence list, decision, history and reports. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The case'), new OA\Response(response: 404, description: 'No such case')])]
    public function show(Request $request, int $id): JsonResponse
    {
        $case = $this->case($request, $id);
        $this->log->record('case_viewed', $request->user(), 'case', $case->id, ['reference' => $case->reference]);

        return $this->detail($case);
    }

    #[OA\Post(path: '/api/amla/cases/{id}/assign', summary: 'Assign an AMLA case', description: 'Needs `amla:cases` and `branches:view_all`. `assignee_id` holds both too. Told in AMLA\'s inbox.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The case')])]
    public function assign(Request $request, int $id): JsonResponse
    {
        $case = $this->case($request, $id);
        $input = $request->validate(['assignee_id' => ['required', 'integer', 'min:1']]);

        return $this->detail($this->cases->assign($request->user(), $case, (int) $input['assignee_id']));
    }

    #[OA\Post(path: '/api/amla/cases/{id}/notes', summary: 'Add an investigation note', description: 'Needs `amla:cases` and `branches:view_all`. `body`, kept encrypted. 409 once decided.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The case')])]
    public function note(Request $request, int $id): JsonResponse
    {
        $case = $this->case($request, $id);
        $input = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $this->cases->addNote($request->user(), $case, $input['body']);

        return $this->detail($case, 201);
    }

    #[OA\Post(path: '/api/amla/cases/{id}/evidence', summary: 'Upload case evidence', description: 'Needs `amla:cases` and `branches:view_all`. `file`: a picture, PDF or Word document up to 10 MB, judged by its contents and its name\'s extension; optional `description`. Stored privately; its name never reaches the AMLA log.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The evidence')])]
    public function evidence(Request $request, int $id): JsonResponse
    {
        $case = $this->case($request, $id);
        $input = $request->validate([
            'file' => ['required', 'file', 'max:'.self::EVIDENCE_KILOBYTES, 'mimes:'.UploadFileTypes::DOCUMENT_MIMES, 'extensions:'.UploadFileTypes::DOCUMENT_EXTENSIONS],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'file.max' => 'The file can be at most 10 MB.',
            'file.mimes' => 'The file must be a picture (JPG or PNG), a PDF or a Word document.',
            'file.extensions' => 'The file\'s name must end in '.UploadFileTypes::inWords(UploadFileTypes::DOCUMENT_EXTENSIONS).'.',
        ]);

        $evidence = $this->cases->addEvidence($request->user(), $case, $request->file('file'), $input['description'] ?? null);

        return response()->json(['success' => true, 'data' => ['id' => (int) $evidence->id, 'name' => $evidence->original_name, 'size' => (int) $evidence->file_size]], 201);
    }

    #[OA\Get(path: '/api/amla/cases/{id}/evidence/{evidenceId}', summary: 'Download case evidence', description: 'Needs `amla:cases` and `branches:view_all`. Logged by id only.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function download(Request $request, int $id, int $evidenceId): StreamedResponse
    {
        $case = $this->case($request, $id);
        $evidence = AmlaCaseEvidence::query()->where('case_id', $case->id)->whereKey($evidenceId)->first() ?? abort(404);
        abort_unless(Storage::disk(EvidenceStore::DISK)->exists($evidence->file_path), 404);
        $this->log->record('case_evidence_downloaded', $request->user(), 'case', $case->id, ['evidence_id' => $evidence->id]);

        return Storage::disk(EvidenceStore::DISK)->download($evidence->file_path, null, [
            'Content-Disposition' => DownloadFilename::attachmentDisposition($evidence->original_name, $evidence->file_path),
        ]);
    }

    #[OA\Post(path: '/api/amla/cases/{id}/decision', summary: 'Record the case\'s authorized decision', description: 'Needs `amla:cases`, `amla:approve` and `branches:view_all`. `decision`: no_str (closes the case; never by its opener or assignee, 403) or str (suspicion established); `reason` (encrypted); `determination_date` (str only, not after today): the STR is due the next working day after it ("to verify"). Nothing is filed by itself.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The case')])]
    public function decide(Request $request, int $id): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::APPROVE);
        $case = $this->case($request, $id);
        $input = $request->validate([
            'decision' => ['required', 'in:no_str,str'],
            'reason' => ['required', 'string', 'max:5000'],
            'determination_date' => ['exclude_unless:decision,str', 'required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now()->toDateString()],
        ], [
            'decision.in' => 'Pick no STR or suspicion established.',
            'determination_date.required' => 'Give the date suspicion was established.',
            'determination_date.before_or_equal' => 'The determination date can\'t be after today.',
        ]);

        return $this->detail($this->cases->decide($request->user(), $case, $input));
    }

    #[OA\Post(path: '/api/amla/cases/{id}/str', summary: 'Prepare the case\'s STR', description: 'Needs `amla:str`, `amla:cases` and `branches:view_all`. Once the decision establishes suspicion; `narrative` (encrypted). Waits for approval by someone else holding `amla:approve`.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The report')])]
    public function str(Request $request, int $id, Filings $filings, FilingDeadlines $deadlines): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::STR);
        $case = $this->case($request, $id);
        $input = $request->validate(['narrative' => ['required', 'string', 'max:20000']]);
        $report = $filings->prepareStr($request->user(), $case, $input['narrative']);

        return response()->json(['success' => true, 'data' => AmlaCasePresenter::reportDetail($report->load(['format', 'preparer', 'approver']), $deadlines)], 201);
    }

    private function authorizeCases(Request $request): void
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::CASES);
    }

    private function case(Request $request, int $id): AmlaCase
    {
        $this->authorizeCases($request);

        return AmlaCase::query()->whereKey($id)->first() ?? abort(404);
    }

    private function detail(AmlaCase $case, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AmlaCasePresenter::caseDetail($case->fresh(['borrower', 'walkIn', 'branch', 'assignee', 'opener', 'decider']), $this->alerts, request()->user())], $status);
    }
}
