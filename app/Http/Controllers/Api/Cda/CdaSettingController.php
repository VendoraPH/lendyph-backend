<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\UpdateCdaSettingsRequest;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\CdaSetting;
use App\Services\Accounting\Money;
use App\Services\Cda\CdaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * CDA settings (CDA design §1, §3): deadline days, reminder days, the
 * trainings each officer position needs and the asset size bounds.
 * Reminders go to users by permission, so there is no recipient list.
 */
class CdaSettingController extends Controller
{
    #[OA\Get(
        path: '/api/cda/settings',
        summary: 'CDA settings',
        description: 'Needs `cda:view` and `branches:view_all`. Asset size bounds are pesos as two-decimal strings: micro up to `micro`, small up to `small`, medium up to `medium`, large above.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The settings'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all')],
    )]
    public function show(Request $request, CdaAccess $access): JsonResponse
    {
        $access->authorize($request->user(), CdaAccess::VIEW);

        return response()->json(['success' => true, 'data' => CdaPresenter::settings(CdaSetting::current())]);
    }

    #[OA\Put(
        path: '/api/cda/settings',
        summary: 'Save CDA settings',
        description: 'Needs `cda:settings` and `branches:view_all`. Partial. Days are 1 to 366; reminder days distinct, 1 to 120; required trainings map an officer position to training keys; the asset size bounds rise micro < small < medium. A report\'s default deadline is set when its period opens; a later change applies to periods opened after it. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'annual_deadline_days', type: 'integer', example: 120),
            new OA\Property(property: 'mediation_deadline_days', type: 'integer', example: 30),
            new OA\Property(property: 'reminder_days', type: 'array', items: new OA\Items(type: 'integer'), example: [30, 15, 7, 3]),
            new OA\Property(property: 'required_trainings', type: 'object', example: ['chairperson' => ['fundamentals_of_cooperatives']]),
            new OA\Property(property: 'asset_class_thresholds', type: 'object', example: ['micro' => '3000000.00', 'small' => '15000000.00', 'medium' => '100000000.00']),
        ])),
        responses: [new OA\Response(response: 200, description: 'The saved settings'), new OA\Response(response: 403, description: 'Missing cda:settings or branches:view_all'), new OA\Response(response: 422, description: 'Outside the limits, in words')],
    )]
    public function update(UpdateCdaSettingsRequest $request): JsonResponse
    {
        $settings = CdaSetting::current();
        $changes = $request->validated();

        if (isset($changes['asset_class_thresholds'])) {
            $changes['asset_class_thresholds'] = array_map(
                fn ($value) => CdaPresenter::money((int) Money::toCentavos((string) $value)),
                $changes['asset_class_thresholds'],
            );
        }

        if (isset($changes['reminder_days'])) {
            $changes['reminder_days'] = array_values(array_map('intval', $changes['reminder_days']));
        }

        $settings->fill($changes);

        if ($settings->isDirty()) {
            $settings->forceFill(['updated_by' => $request->user()->id])->save();
        }

        return response()->json(['success' => true, 'message' => 'CDA settings saved.', 'data' => CdaPresenter::settings($settings->refresh())]);
    }
}
