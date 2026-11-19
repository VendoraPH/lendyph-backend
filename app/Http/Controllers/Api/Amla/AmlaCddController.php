<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\AmlaCddIndexRequest;
use App\Http\Requests\Amla\StoreAmlaIdVerificationRequest;
use App\Http\Requests\Amla\StoreAmlaReviewRequest;
use App\Http\Requests\Amla\StoreAmlaRiskAssessmentRequest;
use App\Http\Requests\Amla\UpdateAmlaProfileRequest;
use App\Http\Resources\Amla\AmlaCddPresenter;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Services\Amla\Cdd\CddRecords;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Cdd\CddSubjects;
use App\Services\Amla\Cdd\CddWorklist;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\BranchAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * AMLA customer due diligence and risk (AMLA-B4; AMLA design §4.8–10; task
 * §9–10). Every endpoint needs `amla:cdd`, checked before the record is
 * looked up, so a caller without it gets 403 for every id. A member in a
 * branch the caller can't see, and any walk-in without head-office access,
 * answers 404 like a missing one. Each view and write goes to the AMLA log,
 * never the main audit trail.
 */
class AmlaCddController extends Controller
{
    public function __construct(
        private readonly AmlaAccess $access,
        private readonly CddSubjects $subjects,
        private readonly CddState $state,
        private readonly CddRecords $records,
        private readonly AmlaLog $log,
    ) {}

    #[OA\Get(
        path: '/api/amla/cdd/subjects',
        summary: 'CDD worklist',
        description: 'Needs `amla:cdd`. Members in the caller\'s branches (never pending or rejected registrations) and, with `branches:view_all`, walk-ins; each with its CDD status (worked out on read), risk level, next review and how many items are missing. Filters: `type` (member, walk_in), `status`, `search` (name or code), `branch_id`. 25 a page, `per_page` up to 100. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The worklist'), new OA\Response(response: 403, description: 'Missing amla:cdd or an out-of-scope branch, in words'), new OA\Response(response: 422, description: 'Bad filter')],
    )]
    public function index(AmlaCddIndexRequest $request, CddWorklist $worklist, BranchAccess $branches): JsonResponse
    {
        $filters = $request->validated();
        $branches->authorizeBranchFilter($request->user(), $filters['branch_id'] ?? null);

        $this->log->record('cdd_list_viewed', $request->user(), details: [
            ...array_intersect_key($filters, array_flip(['type', 'status', 'branch_id', 'page'])),
            'searched' => filled($filters['search'] ?? null),
        ]);

        $list = $worklist->list($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => $list['rows'],
            'meta' => [
                'current_page' => $list['page'],
                'per_page' => $list['per_page'],
                'total' => $list['total'],
                'last_page' => max(1, (int) ceil($list['total'] / $list['per_page'])),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/amla/cdd/{subjectType}/{id}',
        summary: 'One member\'s or walk-in\'s CDD profile',
        description: 'Needs `amla:cdd`; a walk-in also needs `branches:view_all` (404 otherwise, as for another branch\'s member). The subject (type, name, code, branch, imported and ID-on-file markers, never the member record\'s personal data), the AMLA profile\'s KYC answers, the completeness check, the CDD status, the latest risk assessment, and the last 20 ID verifications, assessments, reviews and profile changes. A subject with no profile reads Not Started; reading creates nothing. Logged to the AMLA log.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'subjectType', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['members', 'walk-ins'])),
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'The profile'), new OA\Response(response: 403, description: 'Missing amla:cdd, in words'), new OA\Response(response: 404, description: 'Not found, or not in the caller\'s scope')],
    )]
    public function show(Request $request, string $subjectType, int $id): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::CDD);
        $subject = $this->subjects->resolve($request->user(), $subjectType, $id);
        $profile = $this->subjects->profileFor($subject);

        $this->log->record('cdd_profile_viewed', $request->user(), CddSubjects::type($subject), $subject->getKey(), [
            'profile_id' => $profile?->id,
        ]);

        return $this->respond($subject);
    }

    #[OA\Put(
        path: '/api/amla/cdd/{subjectType}/{id}',
        summary: 'Save CDD answers',
        description: 'Needs `amla:cdd` (and `branches:view_all` for a walk-in). Partial: `nationality` (two-letter code), `occupation`, `source_of_funds` (salary, business, remittance, pension, savings, other; `source_of_funds_other` with other), `purpose`, `expected_monthly_amount` (pesos, two decimals), `expected_monthly_count`, `pep_status` (not_pep, pep, pep_family, pep_associate), `pep_details`, `adverse_info`, `adverse_info_note`. The first save starts the profile. Each change keeps its old and new values; a change to a risk factor reassesses the risk. An unchanged save writes nothing. Logged by field name only.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The profile'), new OA\Response(response: 403, description: 'Missing amla:cdd, in words'), new OA\Response(response: 404, description: 'Not found, or not in scope'), new OA\Response(response: 422, description: 'Invalid answers, in words')],
    )]
    public function update(UpdateAmlaProfileRequest $request, string $subjectType, int $id): JsonResponse
    {
        $subject = $this->subjects->resolve($request->user(), $subjectType, $id);
        $this->records->update($request->user(), $subject, $request->answers());

        return $this->respond($subject);
    }

    #[OA\Post(
        path: '/api/amla/cdd/{subjectType}/{id}/id-verifications',
        summary: 'Record an ID verification',
        description: 'Needs `amla:cdd` (and `branches:view_all` for a walk-in). `method` (original_seen, certified_copy, copy_only), `result` (verified, failed; a copy alone never verifies), optional `expires_on` and `notes`. A member\'s names one of their own valid IDs (`document_id`); a walk-in\'s reads the ID on its record (422 when it has none). Only the ID type and last four characters are kept. Starts the profile if needed and reassesses the risk. Kept as written.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The verification'), new OA\Response(response: 403, description: 'Missing amla:cdd, in words'), new OA\Response(response: 404, description: 'Not found, or not in scope'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function storeIdVerification(StoreAmlaIdVerificationRequest $request, string $subjectType, int $id): JsonResponse
    {
        $subject = $this->subjects->resolve($request->user(), $subjectType, $id);
        $verification = $this->records->verifyId($request->user(), $subject, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'The ID verification is recorded.',
            'data' => AmlaCddPresenter::verification($verification->load('verifier')),
        ], 201);
    }

    #[OA\Post(
        path: '/api/amla/cdd/{subjectType}/{id}/risk-assessments',
        summary: 'Assess AML risk now',
        description: 'Needs `amla:cdd` (and `branches:view_all` for a walk-in). Scores under the current rules (versioned, "to verify"); optional `override_level` (low, medium, high) with a required `override_reason`. An override stands until the computed level changes. 422 before the profile is started. Kept as written.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The assessment'), new OA\Response(response: 403, description: 'Missing amla:cdd, in words'), new OA\Response(response: 404, description: 'Not found, or not in scope'), new OA\Response(response: 422, description: 'Invalid, or no profile yet')],
    )]
    public function storeRiskAssessment(StoreAmlaRiskAssessmentRequest $request, string $subjectType, int $id): JsonResponse
    {
        $subject = $this->subjects->resolve($request->user(), $subjectType, $id);
        $assessment = $this->records->assessNow(
            $request->user(),
            $subject,
            $request->validated('override_level'),
            $request->validated('override_reason'),
        );

        return response()->json([
            'success' => true,
            'message' => 'The risk assessment is recorded.',
            'data' => AmlaCddPresenter::assessment($assessment->load('assessor', 'ruleVersion')),
        ], 201);
    }

    #[OA\Post(
        path: '/api/amla/cdd/{subjectType}/{id}/reviews',
        summary: 'Record a CDD review',
        description: 'Needs `amla:cdd` (and `branches:view_all` for a walk-in). `kind` (periodic, edd, trigger), `outcome` (no_change, updated, escalated), `notes` (required for edd). Sets the next review the settings\' cycle for the current risk level after today (high 1 year, medium 2, low 3, "to verify"). An EDD review completes enhanced due diligence. 422 before the profile is started. Kept as written.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The review'), new OA\Response(response: 403, description: 'Missing amla:cdd, in words'), new OA\Response(response: 404, description: 'Not found, or not in scope'), new OA\Response(response: 422, description: 'Invalid, or no profile yet')],
    )]
    public function storeReview(StoreAmlaReviewRequest $request, string $subjectType, int $id): JsonResponse
    {
        $subject = $this->subjects->resolve($request->user(), $subjectType, $id);
        $review = $this->records->review($request->user(), $subject, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'The review is recorded.',
            'data' => AmlaCddPresenter::review($review->load('reviewer')),
        ], 201);
    }

    private function respond(Borrower|GCashNonMember $subject): JsonResponse
    {
        $profile = $this->subjects->profileFor($subject);

        return response()->json(['success' => true, 'data' => AmlaCddPresenter::show($subject, $profile, $this->state->of($subject, $profile))]);
    }
}
