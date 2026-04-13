<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflowSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ApprovalWorkflowController extends Controller
{
    private const VALID_TYPES = [
        ApprovalWorkflowSetting::TYPE_NORMAL,
        ApprovalWorkflowSetting::TYPE_POLICY_EXCEPTION,
    ];

    #[OA\Get(
        path: '/api/settings/approval-workflow',
        summary: 'Get approval workflow chain',
        description: 'Returns the configured approval chain for `normal` or `policy_exception`. Falls back to defaults if not yet configured.',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['normal', 'policy_exception'], default: 'policy_exception')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Approval chain steps'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $this->authorize('settings:view');

        $type = $this->resolveType($request);

        $setting = ApprovalWorkflowSetting::where('type', $type)->first();
        $steps = $setting?->steps ?? ApprovalWorkflowSetting::defaultStepsFor($type);

        return response()->json([
            'data' => [
                'type' => $type,
                'steps' => $steps,
                'is_default' => $setting === null,
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/settings/approval-workflow',
        summary: 'Save approval workflow chain',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Saved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $this->authorize('settings:update');

        $validated = $request->validate([
            'type' => ['required', Rule::in(self::VALID_TYPES)],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['required', 'string', 'max:100'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.role' => ['required', 'string', 'max:100'],
            'steps.*.kind' => ['required', Rule::in(['submit', 'approve', 'release'])],
        ]);

        $this->validateChain($validated['steps']);

        $setting = ApprovalWorkflowSetting::updateOrCreate(
            ['type' => $validated['type']],
            ['steps' => $validated['steps']],
        );

        return response()->json([
            'data' => [
                'type' => $setting->type,
                'steps' => $setting->steps,
                'is_default' => false,
            ],
        ]);
    }

    #[OA\Delete(
        path: '/api/settings/approval-workflow',
        summary: 'Reset approval workflow chain to default',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['normal', 'policy_exception'], default: 'policy_exception')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reset to default'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('settings:update');

        $type = $this->resolveType($request);
        ApprovalWorkflowSetting::where('type', $type)->delete();

        return response()->json([
            'data' => [
                'type' => $type,
                'steps' => ApprovalWorkflowSetting::defaultStepsFor($type),
                'is_default' => true,
            ],
        ]);
    }

    private function resolveType(Request $request): string
    {
        $type = $request->query('type', ApprovalWorkflowSetting::TYPE_POLICY_EXCEPTION);

        if (! in_array($type, self::VALID_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Invalid type. Must be one of: '.implode(', ', self::VALID_TYPES),
            ]);
        }

        return $type;
    }

    private function validateChain(array $steps): void
    {
        if (empty($steps)) {
            throw ValidationException::withMessages(['steps' => 'Chain must have at least one step.']);
        }

        $ids = [];
        foreach ($steps as $step) {
            if (in_array($step['id'], $ids, true)) {
                throw ValidationException::withMessages(['steps' => "Duplicate step id: \"{$step['id']}\"."]);
            }
            $ids[] = $step['id'];
        }

        if ($steps[0]['kind'] !== 'submit') {
            throw ValidationException::withMessages(['steps' => 'The Submit step must be the first step in the chain.']);
        }

        $kinds = array_column($steps, 'kind');
        if (! in_array('submit', $kinds, true)) {
            throw ValidationException::withMessages(['steps' => 'Chain must start with a Submit step.']);
        }

        $lastReleaseIndex = array_keys($kinds, 'release', true);
        if (empty($lastReleaseIndex)) {
            throw ValidationException::withMessages(['steps' => 'Chain must end with a Release step.']);
        }

        if (end($lastReleaseIndex) !== count($steps) - 1) {
            throw ValidationException::withMessages(['steps' => 'The Release step must be the last step in the chain.']);
        }
    }
}
