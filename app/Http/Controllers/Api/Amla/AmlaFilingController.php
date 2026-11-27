<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\UploadFileTypes;
use App\Http\Resources\Amla\AmlaCasePresenter;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaReport;
use App\Models\AmlaReportFormat;
use App\Models\AmlaReportSubmission;
use App\Models\User;
use App\Services\Amla\Cases\EvidenceStore;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Cases\Filings;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\WorkingDayCalendar;
use App\Services\BranchAccess;
use App\Services\DownloadFilename;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AMLA CTR and STR records (AMLA-B6; task §13–15). Keys are checked before
 * any record is looked up:
 * - preparing a CTR or setting a candidate aside: `amla:ctr`, the candidate
 *   in the caller's scope;
 * - reading reports, formats and deadlines: any of `amla:ctr`, `amla:str`,
 *   `amla:approve`, `amla:submit` or `amla:export`; an STR also needs a
 *   non-CTR key and head-office access, and a CTR follows its branch;
 * - approving or returning: `amla:approve`, never the preparer; recording
 *   a filing step: `amla:submit`; exporting: `amla:export`.
 * A report outside the caller's scope answers 404. Every view and export is
 * logged; an export is an internal review copy and never a submission.
 */
class AmlaFilingController extends Controller
{
    private const READ = [AmlaAccess::CTR, AmlaAccess::STR, AmlaAccess::APPROVE, AmlaAccess::SUBMIT, AmlaAccess::EXPORT];

    public function __construct(
        private readonly AmlaAccess $access,
        private readonly Filings $filings,
        private readonly FilingDeadlines $deadlines,
        private readonly AmlaLog $log,
        private readonly DetectionScope $scope,
    ) {}

    #[OA\Post(path: '/api/amla/ctr-candidates/{id}/report', summary: 'Prepare a CTR', description: 'Needs `amla:ctr`. From a detected candidate in the caller\'s scope: a snapshot of its fields ("Data not available" where Lendy holds none), due the settings\' working days after the transaction ("to verify"). Waits for approval by someone else. 409 once prepared or set aside.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The report')])]
    public function prepareCtr(Request $request, int $id): JsonResponse
    {
        $candidate = $this->candidate($request, $id, AmlaAccess::CTR);

        return $this->respondWith($this->filings->prepareCtr($request->user(), $candidate), 201);
    }

    #[OA\Post(path: '/api/amla/ctr-candidates/{id}/not-reportable', summary: 'Ask to set a CTR candidate aside as not reportable', description: 'Needs `amla:ctr`. `reason` is required (kept encrypted). Answers 202: it waits for a different `amla:approve` holder.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 202, description: 'The candidate, waiting')])]
    public function notReportable(Request $request, int $id): JsonResponse
    {
        $candidate = $this->candidate($request, $id, AmlaAccess::CTR);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Say why it isn\'t reportable.']);

        return $this->candidateResponse($this->filings->requestNotReportable($request->user(), $candidate, $input['reason']), 202);
    }

    #[OA\Post(path: '/api/amla/ctr-candidates/{id}/not-reportable/approve', summary: 'Approve setting a CTR candidate aside', description: 'Needs `amla:approve`; never the person who asked (403, logged).', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The candidate')])]
    public function approveNotReportable(Request $request, int $id): JsonResponse
    {
        $candidate = $this->candidate($request, $id, AmlaAccess::APPROVE);

        return $this->candidateResponse($this->filings->decideNotReportable($request->user(), $candidate, true));
    }

    #[OA\Post(path: '/api/amla/ctr-candidates/{id}/not-reportable/reject', summary: 'Refuse setting a CTR candidate aside', description: 'Needs `amla:approve`; never the person who asked. `reason` required; the candidate goes back to Detected.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The candidate')])]
    public function rejectNotReportable(Request $request, int $id): JsonResponse
    {
        $candidate = $this->candidate($request, $id, AmlaAccess::APPROVE);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->candidateResponse($this->filings->decideNotReportable($request->user(), $candidate, false, $input['reason']));
    }

    #[OA\Get(path: '/api/amla/reports/{id}/submissions/{submissionId}/evidence', summary: 'Download a filing step\'s evidence', description: 'Needs a reporting key and the report in scope. Logged by id only.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function submissionEvidence(Request $request, int $id, int $submissionId): StreamedResponse
    {
        $report = $this->find($request, $id, self::READ);
        $step = AmlaReportSubmission::query()->where('report_id', $report->id)->whereKey($submissionId)->first() ?? abort(404);
        abort_unless(Storage::disk(EvidenceStore::DISK)->exists($step->evidence_path), 404);
        $this->log->record('report_evidence_downloaded', $request->user(), 'report', $report->id, ['submission_id' => $step->id]);

        return Storage::disk(EvidenceStore::DISK)->download($step->evidence_path, null, [
            'Content-Disposition' => DownloadFilename::attachmentDisposition($step->evidence_name, $step->evidence_path),
        ]);
    }

    #[OA\Get(path: '/api/amla/reports', summary: 'AMLA CTR and STR records', description: 'Needs a reporting key. CTRs in the caller\'s branches; STRs with head-office access. Filters `type`, `status`. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The reports')])]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), self::READ);
        $filters = $request->validate([
            'type' => ['sometimes', 'in:ctr,str'],
            'status' => ['sometimes', 'in:'.implode(',', array_keys(AmlaReport::STATUS_WORDS))],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $this->log->record('reports_viewed', $request->user(), details: array_intersect_key($filters, array_flip(['type', 'status', 'page'])));

        $page = $this->visibleReports($request->user())->with(['format', 'preparer', 'approver'])
            ->when(isset($filters['type']), fn ($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AmlaReport $report) => AmlaCasePresenter::reportRow($report, $this->deadlines))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    #[OA\Get(path: '/api/amla/reports/{id}', summary: 'One AMLA CTR or STR record', description: 'Needs a reporting key; out of scope is 404. Its fields, narrative, deadline and submission history. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function show(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id, self::READ);
        $this->log->record('report_viewed', $request->user(), 'report', $report->id, ['reference' => $report->reference, 'type' => $report->type]);

        return $this->respondWith($report);
    }

    #[OA\Post(path: '/api/amla/reports/{id}/approve', summary: 'Approve an AMLA report', description: 'Needs `amla:approve` (and head-office access for an STR). Never its preparer, super_admin included (403, logged).', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function approve(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id, [AmlaAccess::APPROVE]);

        return $this->respondWith($this->filings->approve($request->user(), $report));
    }

    #[OA\Post(path: '/api/amla/reports/{id}/return', summary: 'Return an AMLA report to its preparer', description: 'Needs `amla:approve`. `reason` required. A CTR\'s candidate can be prepared again.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The report')])]
    public function return(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id, [AmlaAccess::APPROVE]);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->respondWith($this->filings->return($request->user(), $report, $input['reason']));
    }

    #[OA\Post(path: '/api/amla/reports/{id}/submissions', summary: 'Record a manual filing step', description: 'Needs `amla:submit`. `status` (submitted, accepted, rejected, correction_required), the facility\'s `reference`, its `date` and `evidence` (a picture, PDF or Word document). Submitted only once approved (or after a rejection or correction); accepted, rejected or correction required only once submitted (409 otherwise).', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The report')])]
    public function submission(Request $request, int $id): JsonResponse
    {
        $report = $this->find($request, $id, [AmlaAccess::SUBMIT]);
        $input = $request->validate([
            'status' => ['required', 'in:submitted,accepted,rejected,correction_required'],
            'reference' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now()->toDateString()],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'evidence' => ['required', 'file', 'max:10240', 'mimes:'.UploadFileTypes::DOCUMENT_MIMES, 'extensions:'.UploadFileTypes::DOCUMENT_EXTENSIONS],
        ], [
            'reference.required' => 'Give the reference the AMLC portal gave.',
            'evidence.required' => 'Upload the acknowledgement or response as evidence.',
            'date.before_or_equal' => 'The date can\'t be after today.',
        ]);

        $this->filings->recordSubmission($request->user(), $report, $input, $request->file('evidence'));

        return $this->respondWith($report->fresh(), 201);
    }

    #[OA\Get(path: '/api/amla/reports/{id}/export', summary: 'Export an internal review copy', description: 'Needs `amla:export` (and head-office access for an STR). JSON, marked as an internal review copy: the GoTRACS file isn\'t built, so this never counts as filed. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function export(Request $request, int $id): StreamedResponse
    {
        $report = $this->find($request, $id, [AmlaAccess::EXPORT]);
        $this->log->record('report_exported', $request->user(), 'report', $report->id, ['reference' => $report->reference, 'format' => $report->format->code]);
        $body = [
            'notice' => 'Internal review copy. This is not the GoTRACS file: file the report by hand on the AMLC portal and record the submission in Lendy.',
            'reference' => $report->reference,
            'type' => AmlaReport::TYPE_WORDS[$report->type],
            'status' => AmlaReport::STATUS_WORDS[$report->status] ?? $report->status,
            'due_on' => $this->deadlines->due($report)['due_on'],
            'fields' => $report->fields,
            'narrative' => $report->narrative,
        ];

        return response()->streamDownload(
            fn () => print (json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            "{$report->reference}-internal-review.json",
            ['Content-Type' => 'application/json'],
        );
    }

    #[OA\Get(path: '/api/amla/report-formats', summary: 'AMLA reporting format versions', description: 'Needs a reporting key. The GoTRACS file adapter is not built.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The formats')])]
    public function formats(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), self::READ);

        return response()->json(['success' => true, 'data' => AmlaReportFormat::query()->orderBy('id')->get()->map(fn (AmlaReportFormat $format) => [
            'code' => $format->code, 'name' => $format->name, 'file_available' => (bool) $format->file_available, 'to_verify' => (bool) $format->to_verify, 'notes' => $format->notes,
        ])->values()->all()]);
    }

    #[OA\Get(path: '/api/amla/filing-deadlines', summary: 'AMLA filings still to make, with their deadlines', description: 'Needs a reporting key. Unfiled reports, detected CTR candidates with no CTR yet, and (head office) cases whose STR isn\'t prepared; each with its report type (`type`: ctr or str, and `type_words`), its due date on the working-day calendar, whether it is provisional, and whether it is upcoming, due today or overdue. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The filings')])]
    public function deadlines(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, self::READ);
        $this->log->record('filing_deadlines_viewed', $user);
        $today = CarbonImmutable::now(WorkingDayCalendar::TIMEZONE)->toDateString();
        $rows = array_map(fn (array $filing) => AmlaCasePresenter::deadline($filing, $today, $this->deadlines), $this->deadlines->visibleTo($user));

        usort($rows, fn (array $a, array $b) => [$a['due_on'], $a['reference']] <=> [$b['due_on'], $b['reference']]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /** @return Builder<AmlaReport> */
    private function visibleReports(User $user): Builder
    {
        return $this->filings->visibleQuery($user);
    }

    /** @param  list<string>  $keys */
    private function find(Request $request, int $id, array $keys): AmlaReport
    {
        $this->access->authorize($request->user(), $keys);
        $report = $this->visibleReports($request->user())->whereKey($id)->first() ?? abort(404);

        if ($report->type === AmlaReport::STR && $keys !== self::READ) {
            abort_unless($request->user()->can(BranchAccess::VIEW_ALL), 404);
        }

        abort_unless($this->filings->visible($request->user(), $report), 404);

        return $report;
    }

    private function candidate(Request $request, int $id, string $key): AmlaCtrCandidate
    {
        $this->access->authorize($request->user(), $key);

        return $this->scope->constrain(AmlaCtrCandidate::query(), $request->user())->whereKey($id)->first() ?? abort(404);
    }

    private function candidateResponse(AmlaCtrCandidate $candidate, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'id' => (int) $candidate->id, 'reference' => $candidate->reference, 'status' => $candidate->status,
            'status_words' => AmlaCtrCandidate::STATUS_WORDS[$candidate->status],
            'reason' => $candidate->status === AmlaCtrCandidate::SET_ASIDE_PENDING ? $this->filings->pendingSetAside($candidate)?->reason : $candidate->not_reportable_reason,
        ]], $status);
    }

    private function respondWith(AmlaReport $report, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => AmlaCasePresenter::reportDetail($report->load(['format', 'preparer', 'approver']), $this->deadlines)], $status);
    }
}
