<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\UpdateDashboardSettingsRequest;
use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\DashboardSetting;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * The executive dashboard's alert thresholds (Phase 6 EB-C, decision 20):
 * one company-wide row. Reading needs `dashboard:analytics`, or what changing needs:
 * `dashboard:settings` and `branches:view_all`. Each change writes one audit
 * row with the old and new values, in words. The alerts themselves are
 * worked out by the evaluator (EB-D2), never here.
 */
class DashboardSettingsController extends Controller
{
    public const READ_REFUSAL = 'The dashboard alert thresholds need the dashboard analytics permission.';

    #[OA\Get(
        path: '/api/dashboard/settings',
        summary: 'Executive dashboard alert thresholds',
        description: 'Needs dashboard:analytics, or dashboard:settings and branches:view_all. `thresholds` and `defaults`: percentages and points as two-decimal strings, days as whole numbers, the control difference in pesos as a two-decimal string. `labels` names each threshold and `rules` words each alert with the saved values. `can_update` says whether the caller may change them (dashboard:settings and branches:view_all).',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The thresholds'),
            new OA\Response(response: 403, description: 'Missing dashboard:analytics, and not able to save the thresholds'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('dashboard:analytics') && ! $this->canUpdate($user)) {
            throw new AuthorizationException(self::READ_REFUSAL);
        }

        return response()->json(['success' => true, 'data' => $this->present(DashboardSetting::current()->load('updater'), $user)]);
    }

    #[OA\Put(
        path: '/api/dashboard/settings',
        summary: 'Save the executive dashboard alert thresholds',
        description: 'Needs dashboard:settings and branches:view_all (company-wide). Partial. Percentages and points 0 to 100, the control difference ₱0.00 to ₱1,000,000.00, each with at most two decimals (never rounded); days 1 to 365. A save that changes nothing writes nothing. Audited in words.',
        tags: ['Dashboard'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'par30_rise_points', type: 'string', example: '1.00'),
            new OA\Property(property: 'par30_compare_days', type: 'integer', example: 30),
            new OA\Property(property: 'collection_efficiency_below', type: 'string', example: '90.00'),
            new OA\Property(property: 'overdue_share_above', type: 'string', example: '5.00'),
            new OA\Property(property: 'approval_wait_days', type: 'integer', example: 3),
            new OA\Property(property: 'release_wait_days', type: 'integer', example: 2),
            new OA\Property(property: 'control_difference_above', type: 'string', example: '0.00'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The saved thresholds'),
            new OA\Response(response: 403, description: 'Missing dashboard:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'Outside the limits, in words'),
        ],
    )]
    public function update(UpdateDashboardSettingsRequest $request): JsonResponse
    {
        $values = $request->validated();
        DashboardSetting::current();

        $settings = DB::transaction(function () use ($values, $request): DashboardSetting {
            $row = DashboardSetting::query()->whereKey(DashboardSetting::ROW_ID)->lockForUpdate()->firstOrFail();
            $row->fill($values);

            if ($row->isDirty(array_keys($values))) {
                $row->updated_by = $request->user()->id;
                $row->save();
            }

            return $row;
        });

        return response()->json([
            'success' => true,
            'message' => 'Dashboard alert thresholds saved.',
            'data' => $this->present($settings->refresh()->load('updater'), $request->user()),
        ]);
    }

    /** Whoever can save the thresholds (dashboard:settings and branches:view_all) can also read them. */
    private function canUpdate(User $user): bool
    {
        return $user->can('dashboard:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    /** @return array<string, mixed> */
    private function present(DashboardSetting $settings, User $user): array
    {
        return [
            'thresholds' => $settings->thresholds(),
            'defaults' => DashboardSetting::DEFAULTS,
            'labels' => DashboardSetting::LABELS,
            'rules' => $settings->rules(),
            'can_update' => $this->canUpdate($user),
            'updated_by' => SavingsUserRef::from($settings->updater),
            'updated_at' => $settings->updated_at,
        ];
    }
}
