<?php

namespace App\Http\Controllers\Api\LoanOrigination;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\OriginationAccess;
use App\Http\Requests\LoanOrigination\UpdateOriginationSettingsRequest;
use App\Services\LoanOrigination\OriginationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Settings → Online Applications (origination decisions 15–18): the
 * frontend's OriginationSettings object, plus the server's own switches
 * (`server`) and the assessment rules version (`rules_version`).
 */
class OriginationSettingsController extends Controller
{
    public function __construct(private readonly OriginationSettings $settings) {}

    #[OA\Get(
        path: '/api/loan-origination/settings',
        summary: 'Online application settings',
        description: 'General, products, assessment, documents and notifications, as the frontend\'s OriginationSettings. Rates and money are JSON numbers worked out from exact stored decimals. `rules_version` is the current assessment rules revision. `server.enabled` is LOAN_ORIGINATION_ENABLED; `server.public_url` is the address links are built on, or null with `server.public_url_problem` in words; `server.scanner` names the upload scanner.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The settings'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view and loan_origination:settings'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        abort_unless(OriginationAccess::canRead($request->user()), 403, OriginationAccess::READ_REFUSAL);

        return response()->json(['success' => true, 'data' => $this->settings->present()]);
    }

    #[OA\Put(
        path: '/api/loan-origination/settings',
        summary: 'Save online application settings',
        description: 'Each section is optional; a section sent replaces what it holds (`products` is the whole list). A change to the assessment rules adds the next immutable revision. Audited.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The saved settings'),
            new OA\Response(response: 403, description: 'Missing loan_origination:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'Outside the limits, in words'),
        ],
    )]
    public function update(UpdateOriginationSettingsRequest $request): JsonResponse
    {
        $this->settings->save($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Online application settings saved.',
            'data' => $this->settings->present(),
        ]);
    }
}
