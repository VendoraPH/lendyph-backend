<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cda\UpdateCdaRegulatoryProfileRequest;
use App\Models\CdaRegulatoryProfile;
use App\Services\Cda\Applicability;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\RegulatoryProfileView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The cooperative's regulatory profile (CDA design §1, §3): CDA's own fields
 * stored here, with the name, address, contact, branch count and officers
 * pulled read-only from where they live.
 */
class CdaRegulatoryProfileController extends Controller
{
    public function __construct(private readonly CdaAccess $access, private readonly RegulatoryProfileView $view) {}

    #[OA\Get(
        path: '/api/cda/regulatory-profile',
        summary: 'The regulatory profile',
        description: 'Needs `cda:view` and `branches:view_all`. `{stored, pulled, asset_class_suggested}`: `pulled` values are read-only, each with its `source`.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The profile'), new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all')],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), CdaAccess::VIEW);

        return response()->json(['success' => true, 'data' => $this->view->present(CdaRegulatoryProfile::current())]);
    }

    #[OA\Put(
        path: '/api/cda/regulatory-profile',
        summary: 'Save the regulatory profile',
        description: 'Needs `cda:settings` and `branches:view_all`. Partial. `cte_status` is none, valid, pending or expired; `cooperative_category` primary, secondary, tertiary, federation or union; `asset_class` micro, small, medium or large (setting one makes `asset_class_source` manual, clearing it computed). A CTE change updates whether ATIR and ABR apply to reports still in draft. Audited.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'cda_registration_no', type: 'string'),
            new OA\Property(property: 'cte_status', type: 'string', enum: CdaRegulatoryProfile::CTE_STATUSES),
            new OA\Property(property: 'cte_expires_on', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'cooperative_category', type: 'string', enum: CdaRegulatoryProfile::CATEGORIES),
            new OA\Property(property: 'asset_class', type: 'string', enum: CdaRegulatoryProfile::ASSET_CLASSES, nullable: true),
        ])),
        responses: [new OA\Response(response: 200, description: 'The saved profile'), new OA\Response(response: 403, description: 'Missing cda:settings or branches:view_all'), new OA\Response(response: 422, description: 'A value outside its list, in words')],
    )]
    public function update(UpdateCdaRegulatoryProfileRequest $request, Applicability $applicability): JsonResponse
    {
        $profile = DB::transaction(function () use ($request, $applicability): CdaRegulatoryProfile {
            $profile = CdaRegulatoryProfile::query()->whereKey(CdaRegulatoryProfile::current()->id)->lockForUpdate()->firstOrFail();
            $changes = $request->validated();

            if (array_key_exists('asset_class', $changes)) {
                $changes['asset_class_source'] = $changes['asset_class'] === null ? 'computed' : 'manual';
            }

            $profile->fill($changes);

            if (! $profile->isDirty()) {
                return $profile;
            }

            $cteChanged = $profile->isDirty(['cte_status', 'cte_expires_on']);
            $profile->forceFill(['updated_by' => $request->user()->id])->save();

            if ($cteChanged) {
                $applicability->syncDrafts($profile);
            }

            return $profile;
        });

        return response()->json(['success' => true, 'message' => 'Regulatory profile saved.', 'data' => $this->view->present($profile->refresh())]);
    }
}
