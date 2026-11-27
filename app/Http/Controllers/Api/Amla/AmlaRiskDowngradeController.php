<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Resources\Amla\AmlaCasePresenter;
use App\Models\AmlaRiskDowngrade;
use App\Services\Amla\Cases\RiskDowngrades;
use App\Services\Amla\Cdd\CddRecords;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * High-risk downgrades waiting for a second CDD holder (AMLA-B6). Every
 * route needs `amla:cdd`, checked before the request is looked up; a subject
 * the caller can't see answers 404, and the requester can never decide their
 * own (403, logged).
 */
class AmlaRiskDowngradeController extends Controller
{
    public function __construct(
        private readonly AmlaAccess $access,
        private readonly RiskDowngrades $downgrades,
        private readonly AmlaLog $log,
    ) {}

    #[OA\Get(path: '/api/amla/risk-downgrades', summary: 'High-risk downgrades waiting for approval', description: 'Needs `amla:cdd`. Pending requests on subjects the caller can see. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The requests')])]
    public function index(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::CDD);
        $this->log->record('risk_downgrades_viewed', $request->user());

        return response()->json(['success' => true, 'data' => $this->downgrades->visibleTo($request->user())
            ->where('status', AmlaRiskDowngrade::PENDING)->with('requester')->orderBy('id')->get()
            ->map(fn (AmlaRiskDowngrade $downgrade) => AmlaCasePresenter::downgrade($downgrade))->values()->all()]);
    }

    #[OA\Post(path: '/api/amla/risk-downgrades/{id}/approve', summary: 'Approve a High-risk downgrade', description: 'Needs `amla:cdd`; never the requester. Records the requester\'s override assessment.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The request')])]
    public function approve(Request $request, int $id, CddRecords $records): JsonResponse
    {
        $downgrade = $this->find($request, $id);

        return response()->json(['success' => true, 'data' => AmlaCasePresenter::downgrade($this->downgrades->approve($request->user(), $downgrade, $records))]);
    }

    #[OA\Post(path: '/api/amla/risk-downgrades/{id}/reject', summary: 'Reject a High-risk downgrade', description: 'Needs `amla:cdd`; never the requester. `reason` required; the risk stays as it was.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The request')])]
    public function reject(Request $request, int $id): JsonResponse
    {
        $downgrade = $this->find($request, $id);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => 'Say why the downgrade is refused.']);

        return response()->json(['success' => true, 'data' => AmlaCasePresenter::downgrade($this->downgrades->reject($request->user(), $downgrade, $input['reason']))]);
    }

    private function find(Request $request, int $id): AmlaRiskDowngrade
    {
        $this->access->authorize($request->user(), AmlaAccess::CDD);

        return $this->downgrades->find($request->user(), $id);
    }
}
