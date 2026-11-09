<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditScoring\ListBorrowerScoresRequest;
use App\Http\Requests\CreditScoring\ScoreHistoryRequest;
use App\Http\Requests\CreditScoring\StoreCreditDecisionRequest;
use App\Http\Requests\CreditScoring\UpdateCreditScoringSettingsRequest;
use App\Http\Requests\CreditScoring\UpdateScorecardConfigRequest;
use App\Models\Borrower;
use App\Models\CreditDecision;
use App\Models\CreditPolicyFlag;
use App\Models\CreditScore;
use App\Models\CreditScoreMember;
use App\Services\BranchAccess;
use App\Services\CreditScoring\Api\CreditScope;
use App\Services\CreditScoring\Api\DecisionRecorder;
use App\Services\CreditScoring\Api\Portfolio;
use App\Services\CreditScoring\Api\Presenter;
use App\Services\CreditScoring\Api\ScorecardEditor;
use App\Services\CreditScoring\Api\SettingsEditor;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Credit Scoring: the Lendy Credit Score, an advisory 0 to 100 risk score.
 * Nothing here writes a loan, schedule, approval, payment or member; the
 * only writes are the scorecard, the settings and credit decisions.
 *
 * Every answer is `{ success, data }`, shaped as the frontend's
 * src/types/credit-scoring.ts (Presenter). Lists are Laravel paginators, 25
 * a page by default, `per_page` from 1 to 100. Members are read through
 * CreditScope: only those the caller's branches can see, so a hidden member
 * answers 404 and a `branch_id` filter outside the caller's branches 403.
 */
class CreditScoringController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly CreditScope $scope,
        private readonly Presenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/credit-scoring/dashboard',
        summary: 'Credit Scoring portfolio summary: scored members, average, risk distribution and recent changes',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The CreditScoringDashboardSummary, for the members the caller may see'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
        ],
    )]
    public function dashboard(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorize('credit_scoring:view');

        return $this->ok($portfolio->dashboard($request->user()));
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers',
        summary: 'Scored members, with their current score',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'risk_level', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'min_score', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'max_score', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'has_active_loan', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'past_due', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A paginator of BorrowerScoreRow'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function borrowers(ListBorrowerScoresRequest $request, BranchAccess $access): JsonResponse
    {
        $access->authorizeBranchFilter($request->user(), $request->input('branch_id'));
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $hasActiveLoan = $request->flag('has_active_loan');
        $pastDue = $request->flag('past_due');

        $rows = $this->scope->currentScores($request->user(), $branchId)
            ->when($request->filled('search'), fn ($q) => $q->whereIn(
                'credit_score_members.borrower_id',
                Borrower::query()->search((string) $request->input('search'), false)->select('borrowers.id'),
            ))
            ->when($request->filled('risk_level'), fn ($q) => $q->where('credit_score_members.risk_level', $request->input('risk_level')))
            ->when($request->filled('min_score'), fn ($q) => $q->where('credit_score_members.score', '>=', (int) $request->input('min_score')))
            ->when($request->filled('max_score'), fn ($q) => $q->where('credit_score_members.score', '<=', (int) $request->input('max_score')))
            ->when($hasActiveLoan !== null, fn ($q) => $q->where('credit_score_members.has_active_loan', $hasActiveLoan))
            ->when($pastDue !== null, fn ($q) => $q->where('credit_score_members.past_due', $pastDue))
            ->with(['borrower:'.implode(',', CreditScope::MEMBER_COLUMNS), 'borrower.branch:id,name'])
            ->orderBy('borrowers.last_name')
            ->orderBy('borrowers.first_name')
            ->orderBy('credit_score_members.borrower_id')
            ->paginate($this->perPage($request));

        return $this->page($rows, fn (CreditScoreMember $current) => $this->presenter->row($current, $request->user()));
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}',
        summary: "A member's credit profile: their current score, its factors and capacity",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The CreditScore, or status not_scored before the first score'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 404, description: 'No such member, a member in another branch, or a pending or rejected registration'),
        ],
    )]
    public function borrower(Request $request, int $borrowerId): JsonResponse
    {
        $this->authorize('credit_scoring:view');
        $member = $this->scope->member($request->user(), $borrowerId);

        $scoreId = CreditScoreMember::query()->where('borrower_id', $member->id)->value('credit_score_id');
        $score = $scoreId === null ? null : CreditScore::query()->with(['factors', 'revision:id,validated'])->find($scoreId);

        return $this->ok($this->presenter->score($request->user(), $member, $score));
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}/history',
        summary: "A member's score history, newest first",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A paginator of CreditScoreHistoryEntry'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 404, description: 'No such member, or a member in another branch'),
        ],
    )]
    public function borrowerHistory(Request $request, int $borrowerId): JsonResponse
    {
        $this->authorize('credit_scoring:view');
        $member = $this->scope->member($request->user(), $borrowerId);

        $rows = $this->scope->history($request->user())
            ->where('borrower_id', $member->id)
            ->with('borrower:'.implode(',', CreditScope::MEMBER_COLUMNS))
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->page($rows, fn (CreditScore $score) => $this->presenter->history($score, $request->user()));
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}/policy-flags',
        summary: "A member's active policy flags, hard flags first",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{data: PolicyFlag[], details_hidden}: [] for a clean member, and [] with details_hidden true for a caller who may not read the member\'s details'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 404, description: 'No such member, or a member in another branch'),
        ],
    )]
    public function policyFlags(Request $request, int $borrowerId): JsonResponse
    {
        $this->authorize('credit_scoring:view');
        $member = $this->scope->member($request->user(), $borrowerId);

        if (! $this->scope->seesMemberDetails($request->user(), $member)) {
            return $this->ok(['data' => [], 'details_hidden' => true]);
        }

        return $this->ok([
            'data' => $this->scope->openFlags($request->user())
                ->where('borrower_id', $member->id)
                ->orderByRaw("type = 'hard_flag' DESC")
                ->orderByDesc('first_triggered_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (CreditPolicyFlag $flag) => $this->presenter->flag($request->user(), $flag, $member))
                ->all(),
            'details_hidden' => false,
        ]);
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}/decisions',
        summary: "A member's credit decisions, newest first",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A paginator of CreditDecision, plus details_hidden. Member-level decisions (no loan, on a nightly score) and, with loans:view, decisions on loans the caller can see. Without the member\'s details, each leaves out reason and remarks'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 404, description: 'No such member, or a member in another branch'),
        ],
    )]
    public function borrowerDecisions(Request $request, int $borrowerId): JsonResponse
    {
        $this->authorize('credit_scoring:view');
        $member = $this->scope->member($request->user(), $borrowerId);
        $details = $this->scope->seesMemberDetails($request->user(), $member);

        $rows = CreditDecision::query()
            ->where('borrower_id', $member->id)
            ->where(fn ($q) => $q
                ->where(fn ($memberLevel) => $memberLevel->whereNull('loan_id')->whereIn('credit_score_id', CreditScore::query()->where('context', CreditScore::CONTEXT_NIGHTLY)->select('id')))
                ->when($request->user()->can('loans:view'), fn ($q) => $q->orWhereIn('loan_id', $this->scope->loanIds($request->user()))))
            ->with('decidedByUser:id,first_name,last_name')
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->through(fn (CreditDecision $decision) => $this->presenter->decision($decision, $details));

        return $this->ok($rows->toArray() + ['details_hidden' => ! $details]);
    }

    #[OA\Get(
        path: '/api/credit-scoring/score-history',
        summary: 'Score changes across the members the caller may see, newest first',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrower_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A paginator of CreditScoreHistoryEntry'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function scoreHistory(ScoreHistoryRequest $request, BranchAccess $access): JsonResponse
    {
        $access->authorizeBranchFilter($request->user(), $request->input('branch_id'));
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $rows = $this->scope->history($request->user(), $branchId)
            ->when($request->filled('borrower_id'), fn ($q) => $q->where('borrower_id', (int) $request->input('borrower_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('calculated_at', '>=', Carbon::parse($request->input('from'))->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('calculated_at', '<', Carbon::parse($request->input('to'))->addDay()->startOfDay()))
            ->with('borrower:'.implode(',', CreditScope::MEMBER_COLUMNS))
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->page($rows, fn (CreditScore $score) => $this->presenter->history($score, $request->user()));
    }

    #[OA\Get(
        path: '/api/credit-scoring/risk-monitoring',
        summary: 'Risk monitoring summary, affected members and alerts',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The RiskMonitoringData, for the members the caller may see'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
        ],
    )]
    public function riskMonitoring(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorize('credit_scoring:view');

        return $this->ok($portfolio->riskMonitoring($request->user()));
    }

    #[OA\Get(
        path: '/api/credit-scoring/scorecard-config',
        summary: 'The scorecard revision in use',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The ScorecardConfig'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
        ],
    )]
    public function showScorecardConfig(ScorecardEditor $editor): JsonResponse
    {
        $this->authorize('credit_scoring:settings');

        return $this->ok($editor->config());
    }

    #[OA\Put(
        path: '/api/credit-scoring/scorecard-config',
        summary: 'Save the scorecard: a change writes a new, unvalidated revision',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The ScorecardConfig in use after the save'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 422, description: 'Weights not totalling 100.00, other categories, or changed rules'),
        ],
    )]
    public function updateScorecardConfig(UpdateScorecardConfigRequest $request, ScorecardEditor $editor): JsonResponse
    {
        $revision = $editor->save($request->user(), $request->weights(), $request->bandMinimums());

        return $this->ok($editor->config($revision));
    }

    #[OA\Get(
        path: '/api/credit-scoring/settings',
        summary: 'Credit Scoring settings',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The CreditScoringSettings'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
        ],
    )]
    public function showSettings(SettingsEditor $editor): JsonResponse
    {
        $this->authorize('credit_scoring:settings');

        return $this->ok($editor->present());
    }

    #[OA\Put(
        path: '/api/credit-scoring/settings',
        summary: 'Save Credit Scoring settings',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The CreditScoringSettings after the save'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 422, description: 'score_model_version sent, or a bad figure'),
        ],
    )]
    public function updateSettings(UpdateCreditScoringSettingsRequest $request, SettingsEditor $editor): JsonResponse
    {
        return $this->ok($editor->present($editor->save($request->user(), $request->values())));
    }

    #[OA\Post(
        path: '/api/credit-scoring/decisions',
        summary: 'Record a person\'s credit decision against a score (append-only; never changes a loan)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'The CreditDecision'),
            new OA\Response(response: 403, description: 'Missing credit_scoring:override'),
            new OA\Response(response: 404, description: 'No such member, or a member in another branch'),
            new OA\Response(response: 422, description: 'Another member\'s score, a loan that doesn\'t match, or a missing reason'),
        ],
    )]
    public function storeDecision(StoreCreditDecisionRequest $request, DecisionRecorder $recorder): JsonResponse
    {
        $member = $this->scope->member($request->user(), (int) $request->validated('borrower_id'));

        return $this->ok($this->presenter->decision(
            $recorder->record($request->user(), $member, $request->validated()),
            $this->scope->seesMemberDetails($request->user(), $member),
        ), 201);
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    /**
     * A paginator, its rows shaped by `$shape`.
     *
     * @param  LengthAwarePaginator<int, mixed>  $rows
     */
    private function page(LengthAwarePaginator $rows, callable $shape): JsonResponse
    {
        return $this->ok($rows->through($shape)->toArray());
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);
    }
}
