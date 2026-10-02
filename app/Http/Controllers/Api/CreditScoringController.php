<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Credit Scoring — placeholders until the module's backend is built.
 *
 * The frontend ships the whole module and the roles screen offers its three
 * permissions, so every endpoint it calls is routed here and answers 501 to a
 * caller who holds the permission. That is deliberate: the frontend reads only
 * 404 and 501 as "not connected yet" and renders anything else as a broken
 * screen. A caller without the permission gets the usual 403.
 *
 * Nothing here reads the database, including the borrower id in the path. The
 * contract each endpoint will fulfil is docs/CREDIT_SCORING_BACKEND_HANDOFF.md
 * in the frontend repo.
 */
class CreditScoringController extends Controller
{
    private const NOT_AVAILABLE = 'Credit Scoring is not available yet.';

    #[OA\Get(
        path: '/api/credit-scoring/dashboard',
        summary: 'Credit Scoring portfolio summary (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function dashboard(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers',
        summary: 'Scored borrowers (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function borrowers(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}',
        summary: "A borrower's credit profile (not available yet)",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function borrower(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}/history',
        summary: "A borrower's score history (not available yet)",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function borrowerHistory(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/borrowers/{borrowerId}/policy-flags',
        summary: "A borrower's policy flags (not available yet)",
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function policyFlags(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/score-history',
        summary: 'Score changes across the portfolio (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function scoreHistory(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/risk-monitoring',
        summary: 'Risk monitoring summary, affected borrowers and alerts (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:view'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function riskMonitoring(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:view');
    }

    #[OA\Get(
        path: '/api/credit-scoring/scorecard-config',
        summary: 'Scorecard configuration (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function showScorecardConfig(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:settings');
    }

    #[OA\Put(
        path: '/api/credit-scoring/scorecard-config',
        summary: 'Save the scorecard configuration (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function updateScorecardConfig(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:settings');
    }

    #[OA\Get(
        path: '/api/credit-scoring/settings',
        summary: 'Credit Scoring settings (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function showSettings(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:settings');
    }

    #[OA\Put(
        path: '/api/credit-scoring/settings',
        summary: 'Save Credit Scoring settings (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:settings'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function updateSettings(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:settings');
    }

    #[OA\Post(
        path: '/api/credit-scoring/decisions',
        summary: 'Record a human credit decision against a score (not available yet)',
        tags: ['Credit Scoring'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 403, description: 'Missing credit_scoring:override'),
            new OA\Response(response: 501, description: 'Credit Scoring is not available yet'),
        ],
    )]
    public function storeDecision(): JsonResponse
    {
        return $this->notAvailable('credit_scoring:override');
    }

    /**
     * 403 without the permission, 501 with it.
     */
    private function notAvailable(string $permission): JsonResponse
    {
        $this->authorize($permission);

        return response()->json(['message' => self::NOT_AVAILABLE], 501);
    }
}
