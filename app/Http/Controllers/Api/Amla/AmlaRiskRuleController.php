<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Resources\Amla\AmlaCddPresenter;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaRiskRuleVersion;
use App\Services\Amla\Cdd\RiskRules;
use App\Services\Amla\Cdd\RiskScorer;
use App\Services\Amla\Foundation\AmlaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The AML risk scoring rules (AMLA design §4.9). Company-wide, so reading
 * them needs head-office access; changing them is a `risk_rules` change
 * request that a second `amla:settings` holder approves.
 */
class AmlaRiskRuleController extends Controller
{
    #[OA\Get(
        path: '/api/amla/risk-rules',
        summary: 'AML risk scoring rules',
        description: 'Needs `amla:cdd`, `amla:settings` or `amla:audit`, and `branches:view_all`. The version in force (each factor\'s points, the Medium and High thresholds, the high-risk countries, "to verify"), every version newest first, each factor and value in words, and the waiting `risk_rules` change, if any.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The rules'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function show(Request $request, AmlaAccess $access, RiskRules $rules): JsonResponse
    {
        $access->authorizeHeadOffice($request->user(), [AmlaAccess::CDD, AmlaAccess::SETTINGS, AmlaAccess::AUDIT]);

        $versions = AmlaRiskRuleVersion::query()->with('approver')->orderByDesc('version')->get();
        $pending = AmlaChangeRequest::query()->where('pending_key', AmlaChangeRequest::RISK_RULES)->value('id');

        return response()->json(['success' => true, 'data' => [
            'current' => AmlaCddPresenter::ruleVersion($rules->current()->load('approver')),
            'versions' => $versions->map(fn (AmlaRiskRuleVersion $version) => AmlaCddPresenter::ruleVersion($version))->values(),
            'factor_words' => RiskScorer::FACTOR_WORDS,
            'value_words' => RiskScorer::VALUE_WORDS,
            'pending_request_id' => $pending === null ? null : (int) $pending,
        ]]);
    }
}
