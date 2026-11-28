<?php

namespace App\Http\Controllers\Api\Cic;

use App\Models\CicCapture;
use App\Models\CicPublicKey;
use App\Models\CicSetting;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * CIC reporting at a glance, for the screens: whether it is set up to make a
 * file. Names no member.
 */
class CicStatusController extends CicController
{
    #[OA\Get(
        path: '/api/cic/status',
        summary: 'CIC reporting status',
        description: 'Needs `cic:view` and `branches:view_all`. {enabled, format_version, provider_code_set, active_key ({fingerprint, key_id, algorithm, expires_at, expired} or null), last_capture ({id, reference_date, kind, status, finished_at} or null, the latest complete capture), test_captures}. 404 while CIC is off or hidden in Menu Visibility.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The status'), new OA\Response(response: 403, description: 'Missing cic:view or branches:view_all'), new OA\Response(response: 404, description: 'CIC is off or hidden')],
    )]
    public function show(Request $request, CicSwitch $switch): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);

        $setting = CicSetting::current();
        $key = CicPublicKey::activeKey();
        $capture = CicCapture::query()
            ->where('status', CicCapture::STATUS_COMPLETE)
            ->orderByDesc('reference_date')
            ->orderByDesc('id')
            ->first();

        return response()->json(['success' => true, 'data' => [
            'enabled' => $switch->enabled(),
            'format_version' => CicSwitch::formatVersion(),
            'provider_code_set' => $setting->hasProviderCode(),
            'active_key' => $key === null ? null : [
                'fingerprint' => $key->fingerprint,
                'key_id' => $key->key_id,
                'algorithm' => $key->algorithm,
                'expires_at' => $key->expires_at?->toJSON(),
                'expired' => $key->isExpired(),
            ],
            'last_capture' => $capture === null ? null : [
                'id' => $capture->id,
                'reference_date' => $capture->reference_date->toDateString(),
                'kind' => $capture->kind,
                'status' => $capture->status,
                'finished_at' => $capture->finished_at?->toJSON(),
            ],
            'test_captures' => $switch->testCaptures(),
        ]]);
    }
}
