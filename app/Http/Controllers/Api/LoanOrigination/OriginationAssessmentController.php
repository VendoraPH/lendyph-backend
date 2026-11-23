<?php

namespace App\Http\Controllers\Api\LoanOrigination;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\RunAssessmentRequest;
use App\Http\Requests\LoanOrigination\SaveAssessmentNotesRequest;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Services\AuditLogService;
use App\Services\LoanOrigination\Assessment\AssessmentPdf;
use App\Services\LoanOrigination\Assessment\AssessmentPresenter;
use App\Services\LoanOrigination\Assessment\AssessmentService;
use App\Services\LoanOrigination\Assessment\RequiredDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Online applications' capacity assessments (origination decisions 14 and
 * 15; slice LO-B4). Reading needs `loan_origination:view`; running one and
 * saving the officer's notes `loan_origination:assess`. Applications are
 * branch-scoped: another branch's answers 404. Each run and each notes change
 * is a new immutable revision; the figures are the server's.
 */
class OriginationAssessmentController extends Controller
{
    public const READ_REFUSAL = 'You don\'t have access to online applications.';

    public function __construct(
        private readonly AssessmentService $assessments,
        private readonly AssessmentPresenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/loan-origination/assessments',
        summary: 'Current assessments of online applications',
        description: 'The current revision of each assessed application in the caller\'s branches, newest first, paginated (per_page up to 100). `result` filters. `meta.stats` counts the current assessments by result, plus `total`.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Assessments'),
            new OA\Response(response: 403, description: 'Needs loan_origination:view'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('loan_origination:view'), 403, self::READ_REFUSAL);

        $request->validate(['result' => ['sometimes', Rule::in(OriginationAssessment::RESULTS)]], ['result.in' => 'Choose one of the assessment results.']);

        $current = OriginationAssessment::query()
            ->whereIn('id', OriginationAssessment::query()->selectRaw('max(id)')->groupBy('application_id'))
            ->whereHas('application', fn ($query) => $query->visibleTo($request->user()));

        $stats = (clone $current)->toBase()->selectRaw('result, count(*) as n')->groupBy('result')->pluck('n', 'result');
        $page = (clone $current)
            ->when($request->filled('result'), fn ($query) => $query->where('result', $request->string('result')->toString()))
            ->with('application')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (OriginationAssessment $a) => $this->presenter->present($a, $a->application, $request->user()))->all(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'stats' => [
                    ...array_fill_keys(OriginationAssessment::RESULTS, 0),
                    ...$stats->map(fn ($n) => (int) $n)->all(),
                    'total' => (int) $stats->sum(),
                ],
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/loan-origination/applications/{application}/assessment',
        summary: 'An application\'s current assessment',
        description: 'Null before the first run.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The current revision'), new OA\Response(response: 404, description: 'Not found or another branch\'s')],
    )]
    public function show(Request $request, OriginationApplication $application): JsonResponse
    {
        abort_unless($request->user()->can('loan_origination:view'), 403, self::READ_REFUSAL);
        $current = OriginationAssessment::currentFor($application);

        return response()->json(['success' => true, 'data' => $current === null ? null : $this->presenter->present($current, $application, $request->user())]);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/assessment/rerun',
        summary: 'Run an application\'s assessment',
        description: 'Works out the figures on the server and stores them as the next revision. `verified_monthly_income` and `verified_debt_payments` are optional: left out they keep the previous run\'s, null clears them. `start_date` (Y-m-d) is the loan\'s planned start date, today when left out; the hand-off assesses again at the loan\'s own. From submitted to under assessment only (422 otherwise). Needs loan_origination:assess. Audited with ids and the result only.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'The new revision'),
            new OA\Response(response: 403, description: 'Needs loan_origination:assess'),
            new OA\Response(response: 422, description: 'Not assessable now, or an amount isn\'t pesos with at most two decimals'),
        ],
    )]
    public function rerun(RunAssessmentRequest $request, OriginationApplication $application): JsonResponse
    {
        $assessment = $this->assessments->run($application, $request->user(), $request->figures());

        return response()->json(['success' => true, 'message' => 'Assessment saved.', 'data' => $this->presenter->present($assessment, $application->refresh(), $request->user())], 201);
    }

    #[OA\Patch(
        path: '/api/loan-origination/applications/{application}/assessment',
        summary: 'Save the officer\'s findings and recommendations',
        description: 'A new revision with the current figures and the notes sent. Needs loan_origination:assess.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The new revision'), new OA\Response(response: 422, description: 'No assessment yet, or not assessable now')],
    )]
    public function update(SaveAssessmentNotesRequest $request, OriginationApplication $application): JsonResponse
    {
        $assessment = $this->assessments->saveNotes($application, $request->user(), $request->notes());

        return response()->json(['success' => true, 'message' => 'Notes saved.', 'data' => $this->presenter->present($assessment, $application->refresh(), $request->user())]);
    }

    #[OA\Get(
        path: '/api/loan-origination/applications/{application}/assessment/history',
        summary: 'Every revision of an application\'s assessment',
        description: 'Newest first.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Revisions')],
    )]
    public function history(Request $request, OriginationApplication $application): JsonResponse
    {
        abort_unless($request->user()->can('loan_origination:view'), 403, self::READ_REFUSAL);

        $revisions = OriginationAssessment::query()->where('application_id', $application->id)->orderByDesc('version')->get();

        return response()->json(['success' => true, 'data' => $revisions->map(fn (OriginationAssessment $a) => $this->presenter->present($a, $application, $request->user()))->all()]);
    }

    #[OA\Get(
        path: '/api/loan-origination/applications/{application}/assessment/pdf',
        summary: 'The current assessment as a PDF',
        description: 'Made on the server; downloaded as an attachment. Each download is audited with ids only.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 422, description: 'No assessment yet')],
    )]
    public function pdf(Request $request, OriginationApplication $application, AssessmentPdf $pdf, RequiredDocuments $documents): Response
    {
        abort_unless($request->user()->can('loan_origination:view'), 403, self::READ_REFUSAL);

        $current = OriginationAssessment::currentFor($application);

        if ($current === null) {
            abort(response()->json(['message' => 'Run the assessment first.', 'errors' => ['assessment' => ['Run the assessment first.']]], 422));
        }

        $bytes = $pdf->render($current, $application, $request->user(), $documents->status($application));

        AuditLogService::log(
            'exported',
            $application,
            newValues: ['assessment_id' => $current->id, 'version' => $current->version, 'format' => 'pdf'],
            description: "Assessment v{$current->version} PDF downloaded for online application #{$application->id}",
        );

        $name = 'assessment-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) ($application->reference_number ?? $application->id)).'-v'.$current->version.'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
