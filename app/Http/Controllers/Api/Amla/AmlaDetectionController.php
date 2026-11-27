<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\AmlaAlertIndexRequest;
use App\Http\Requests\Amla\AmlaCtrCandidateIndexRequest;
use App\Http\Resources\Amla\AmlaDetectionPresenter;
use App\Models\AmlaAlert;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaRuleVersion;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\BranchAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * AMLA detection (AMLA-B3; AMLA design §4.6–4.7, §4.12; task §8, §12).
 * Alerts need `amla:alerts`, CTR candidates `amla:ctr`, both checked before
 * any record is looked up; branch users see only their branches' member
 * records (an alert only when all its transactions are theirs), and anything
 * else answers 404 like a missing one. The rules read with any of
 * `amla:view`, `amla:alerts` or `amla:settings` plus head-office access; their history
 * with `amla:settings` and head-office access; a change goes through
 * POST /amla/change-requests (`detection_rules`). Every view is written to
 * the AMLA log, never the main audit trail.
 */
class AmlaDetectionController extends Controller
{
    public function __construct(
        private readonly AmlaAccess $access,
        private readonly AmlaLog $log,
        private readonly DetectionScope $scope,
        private readonly AmlaDetectionPresenter $presenter,
    ) {}

    #[OA\Get(
        path: '/api/amla/alerts',
        summary: 'AMLA alerts',
        description: 'Needs `amla:alerts`. The caller\'s branches\' alerts (head office: all, including cross-branch and walk-in alerts), newest first. Filters: `status` (open, cleared), `rule` (A–L), `priority`, `branch_id`, `from`/`to` (detection date), `search` (reference). 25 a page, `per_page` up to 100. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The alerts'), new OA\Response(response: 403, description: 'Missing amla:alerts or an out-of-scope branch, in words'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function alerts(AmlaAlertIndexRequest $request, BranchAccess $branches): JsonResponse
    {
        $filters = $request->validated();
        $branches->authorizeBranchFilter($request->user(), $filters['branch_id'] ?? null);

        $this->log->record('alerts_viewed', $request->user(), details: [
            ...array_intersect_key($filters, array_flip(['status', 'rule', 'priority', 'branch_id', 'from', 'to', 'page'])),
            'searched' => filled($filters['search'] ?? null),
        ]);

        $page = $this->scope->constrainAlerts(AmlaAlert::query(), $request->user())
            ->with(['events', 'borrower', 'walkIn', 'branch'])
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['rule']), fn (Builder $q) => $q->where('rule_code', $filters['rule']))
            ->when(isset($filters['priority']), fn (Builder $q) => $q->where('priority', $filters['priority']))
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('created_at', '<=', $filters['to'].' 23:59:59'))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->where('reference', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return $this->page($page, fn (AmlaAlert $alert) => $this->presenter->alertRow($alert, $request->user()));
    }

    #[OA\Get(
        path: '/api/amla/alerts/{id}',
        summary: 'One AMLA alert',
        description: 'Needs `amla:alerts`. The triggered rule and the version it cites, the reason, risk indicators, the subject (name and code), branch, window and every transaction behind it, with "Data not available" where Lendy holds no value. An alert outside the caller\'s scope answers 404. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The alert'), new OA\Response(response: 403, description: 'Missing amla:alerts, in words'), new OA\Response(response: 404, description: 'No such alert in the caller\'s scope')],
    )]
    public function alert(Request $request, int $id): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::ALERTS);

        $alert = $this->scope->constrainAlerts(AmlaAlert::query(), $request->user())->with(['events', 'borrower', 'walkIn', 'branch'])->whereKey($id)->first() ?? abort(404);
        $this->log->record('alert_viewed', $request->user(), 'alert', $alert->id, ['reference' => $alert->reference]);

        return response()->json(['success' => true, 'data' => $this->presenter->alertDetail($alert, $request->user())]);
    }

    #[OA\Get(
        path: '/api/amla/ctr-candidates',
        summary: 'Covered transaction (CTR) candidates',
        description: 'Needs `amla:ctr`. One cash transaction above the CTR threshold of the AMLA settings version in force (to verify); exactly the threshold isn\'t one. Branch-scoped like alerts. Filters: `branch_id`, `from`/`to` (transaction date), `search` (reference). 25 a page. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The candidates'), new OA\Response(response: 403, description: 'Missing amla:ctr or an out-of-scope branch, in words')],
    )]
    public function ctrCandidates(AmlaCtrCandidateIndexRequest $request, BranchAccess $branches): JsonResponse
    {
        $filters = $request->validated();
        $branches->authorizeBranchFilter($request->user(), $filters['branch_id'] ?? null);

        $this->log->record('ctr_candidates_viewed', $request->user(), details: [
            ...array_intersect_key($filters, array_flip(['branch_id', 'from', 'to', 'page'])),
            'searched' => filled($filters['search'] ?? null),
        ]);

        $page = $this->scope->constrain(AmlaCtrCandidate::query(), $request->user())
            ->with(['borrower', 'walkIn', 'branch'])
            ->when(isset($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']))
            ->when(isset($filters['from']), fn (Builder $q) => $q->where('occurred_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q) => $q->where('occurred_on', '<=', $filters['to']))
            ->when(filled($filters['search'] ?? null), fn (Builder $q) => $q->where('reference', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return $this->page($page, fn (AmlaCtrCandidate $candidate) => $this->presenter->ctrRow($candidate));
    }

    #[OA\Get(
        path: '/api/amla/ctr-candidates/{id}',
        summary: 'One CTR candidate',
        description: 'Needs `amla:ctr`. Why it is a candidate, the settings version it was measured against, its transaction, its CTR\'s id once prepared (`ctr_report_id`), and a waiting set-aside ask with `requested_by_me` (the asker is named to `amla:approve` holders only). Out of scope answers 404. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The candidate'), new OA\Response(response: 404, description: 'No such candidate in the caller\'s scope')],
    )]
    public function ctrCandidate(Request $request, int $id): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::CTR);

        $candidate = $this->scope->constrain(AmlaCtrCandidate::query(), $request->user())->with(['event.branch', 'borrower', 'walkIn', 'branch'])->whereKey($id)->first() ?? abort(404);
        $this->log->record('ctr_candidate_viewed', $request->user(), 'ctr_candidate', $candidate->id, ['reference' => $candidate->reference]);

        return response()->json(['success' => true, 'data' => $this->presenter->ctrDetail($candidate, $request->user())]);
    }

    #[OA\Get(
        path: '/api/amla/detection-rules',
        summary: 'AMLA detection rules in force',
        description: 'Needs `amla:view`, `amla:alerts` or `amla:settings`, and `branches:view_all`. Rules A–L of the current version: what each looks for, whether it is active and available, its priority and parameters, all starting points "to verify". Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The rules')],
    )]
    public function rules(Request $request, DetectionRules $rules): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), [AmlaAccess::VIEW, AmlaAccess::ALERTS, AmlaAccess::SETTINGS]);

        $current = $rules->current();
        $this->log->record('detection_rules_viewed', $request->user(), details: ['version' => $current->version]);

        return response()->json(['success' => true, 'data' => $this->presenter->version($current)]);
    }

    #[OA\Get(
        path: '/api/amla/detection-rules/versions',
        summary: 'Every AMLA detection rules version',
        description: 'Needs `amla:settings` and `branches:view_all`. Newest first. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The versions')],
    )]
    public function ruleVersions(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::SETTINGS);
        $this->log->record('detection_rules_viewed', $request->user(), details: ['versions' => true]);

        return response()->json([
            'success' => true,
            'data' => AmlaRuleVersion::query()->orderByDesc('version')->get()->map(fn (AmlaRuleVersion $version) => $this->presenter->version($version))->values()->all(),
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, Model>  $page
     */
    private function page($page, callable $row): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map($row)->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
