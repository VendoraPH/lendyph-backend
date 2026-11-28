<?php

namespace App\Http\Controllers\Api\Cic;

use App\Http\Requests\Cic\UpdateCicSettingsRequest;
use App\Models\CicSetting;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicSettingsPresenter;
use App\Services\Cic\Settings\CicSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** CIC's lender settings (prompt §3.1). */
class CicSettingsController extends CicController
{
    #[OA\Get(
        path: '/api/cic/settings',
        summary: 'CIC settings',
        description: 'Needs `cic:view` and `branches:view_all`. {provider_code, format_version (fixed "1.0"), is_mfi, institution_name, contact_name, contact_email, contact_phone, updated_by ({id, name} or null), updated_at}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The settings'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 404, description: 'CIC is off or hidden')],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);

        return response()->json(['success' => true, 'data' => CicSettingsPresenter::settings(CicSetting::current())]);
    }

    #[OA\Put(
        path: '/api/cic/settings',
        summary: 'Save CIC settings',
        description: 'Needs `cic:settings` and `branches:view_all`. Partial. `provider_code` is exactly 8 letters or digits, stored in upper case (null clears it); `format_version` can\'t change (sending "1.0" is accepted). Each change is audited with old and new values.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'provider_code', type: 'string', nullable: true, example: 'ABCD1234'),
            new OA\Property(property: 'is_mfi', type: 'boolean'),
            new OA\Property(property: 'institution_name', type: 'string', nullable: true),
            new OA\Property(property: 'contact_name', type: 'string', nullable: true),
            new OA\Property(property: 'contact_email', type: 'string', nullable: true),
            new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'The saved settings'), new OA\Response(response: 403, description: 'No access, in words'), new OA\Response(response: 422, description: 'Invalid, in words')],
    )]
    public function update(UpdateCicSettingsRequest $request, CicSettingsService $service): JsonResponse
    {
        $setting = $service->update($request->changes(), $request->user());

        return response()->json(['success' => true, 'message' => 'CIC settings saved.', 'data' => CicSettingsPresenter::settings($setting->refresh())]);
    }
}
