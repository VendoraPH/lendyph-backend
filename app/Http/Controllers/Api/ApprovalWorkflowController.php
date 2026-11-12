<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflowSetting;
use App\Models\CdaSetting;
use App\Models\Role;
use App\Services\AuditLogService;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Reports\CdaApprovalChain;
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
        ApprovalWorkflowSetting::TYPE_CDA,
    ];

    #[OA\Get(
        path: '/api/settings/approval-workflow',
        summary: 'Get approval workflow chain',
        description: 'Returns the configured approval chain for `normal`, `policy_exception` or `cda` (the CDA report review chain). Falls back to defaults if not yet configured; the `cda` default is no steps (a single review).',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['normal', 'policy_exception', 'cda'], default: 'policy_exception')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Approval chain steps',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'type', type: 'string', enum: ['normal', 'policy_exception', 'cda']),
                                new OA\Property(
                                    property: 'steps',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/ApprovalChainStep'),
                                ),
                                new OA\Property(property: 'is_default', type: 'boolean', description: 'True if the chain is the built-in default (no custom save)'),
                            ],
                        ),
                    ],
                ),
            ),
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
        description: 'Upserts the chain for the given type. A loan chain must start with a `submit` step and end with a `release` step, and step ids must be unique. The `cda` chain also needs `cda:settings` with `branches:view_all`: 1 to 10 approve-only steps {name, role, status?}, each role an existing active role and each name used once; audited. Reset (DELETE) returns `cda` to a single review.',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'steps'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['normal', 'policy_exception', 'cda']),
                    new OA\Property(
                        property: 'steps',
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(ref: '#/components/schemas/ApprovalChainStep'),
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'type', type: 'string', enum: ['normal', 'policy_exception', 'cda']),
                                new OA\Property(
                                    property: 'steps',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/ApprovalChainStep'),
                                ),
                                new OA\Property(property: 'is_default', type: 'boolean', example: false),
                            ],
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error (empty chain, bad order, duplicate ids, etc.)'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $this->authorize('settings:update');

        if ($request->input('type') === ApprovalWorkflowSetting::TYPE_CDA) {
            return $this->updateCda($request);
        }

        $validated = $request->validate([
            'type' => ['required', Rule::in(self::VALID_TYPES)],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['required', 'string', 'max:100'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.role' => ['required', 'string', 'max:100'],
            'steps.*.kind' => ['required', Rule::in(['submit', 'approve', 'release'])],
            'steps.*.status' => ['nullable', 'string', 'max:100'],
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
        description: 'Deletes the custom chain for the given type. Subsequent GETs return the built-in default with `is_default: true`.',
        tags: ['Settings'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['normal', 'policy_exception', 'cda'], default: 'policy_exception')),
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

        if ($type === ApprovalWorkflowSetting::TYPE_CDA) {
            app(CdaAccess::class)->authorize($request->user(), CdaAccess::SETTINGS);
            $setting = ApprovalWorkflowSetting::where('type', $type)->first();

            if ($setting !== null) {
                $setting->delete();
                $this->auditCda($setting->steps ?? [], [], 'CDA review chain reset to a single review.');
            }
        } else {
            ApprovalWorkflowSetting::where('type', $type)->delete();
        }

        return response()->json([
            'data' => [
                'type' => $type,
                'steps' => ApprovalWorkflowSetting::defaultStepsFor($type),
                'is_default' => true,
            ],
        ]);
    }

    /**
     * The CDA review chain: approve-only steps by role, at most ten, each
     * name used once and each role an existing active role (admin and
     * super_admin always count). Saving needs `cda:settings` too.
     */
    private function updateCda(Request $request): JsonResponse
    {
        app(CdaAccess::class)->authorize($request->user(), CdaAccess::SETTINGS);

        $validated = $request->validate([
            'type' => ['required', Rule::in([ApprovalWorkflowSetting::TYPE_CDA])],
            'steps' => ['required', 'array', 'min:1', 'max:'.CdaApprovalChain::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.id' => ['nullable', 'string', 'max:100'],
            'steps.*.name' => ['required', 'string', 'max:100', 'regex:/\S/'],
            'steps.*.role' => ['required', 'string', 'max:100'],
            'steps.*.kind' => ['nullable', Rule::in(['approve'])],
            'steps.*.status' => ['nullable', 'string', 'max:100'],
        ], [
            'steps.required' => 'Add at least one review step, or reset the chain to keep a single review.',
            'steps.min' => 'Add at least one review step, or reset the chain to keep a single review.',
            'steps.max' => 'A CDA review chain has at most '.CdaApprovalChain::MAX_STEPS.' steps.',
            'steps.*.name.required' => 'Give each step a name.',
            'steps.*.name.regex' => 'Give each step a name.',
            'steps.*.name.max' => 'A step name takes up to 100 characters.',
            'steps.*.role.required' => 'Choose the role for each step.',
            'steps.*.kind.in' => 'CDA review steps only approve; there is no submit or release step.',
            'steps.*.status.max' => 'A status label takes up to 100 characters.',
        ]);

        $names = [];
        $steps = [];

        foreach ($validated['steps'] as $index => $step) {
            $name = trim($step['name']);
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $name));

            if (in_array($key, $names, true)) {
                throw ValidationException::withMessages(["steps.{$index}.name" => "Two steps are named \"{$name}\". Give each step its own name."]);
            }

            $names[] = $key;
            $active = Role::query()->where('name', $step['role'])
                ->where(fn ($roles) => $roles->where('is_active', true)->orWhereIn('name', Role::PROTECTED_KEYS))
                ->exists();

            if (! $active) {
                throw ValidationException::withMessages(["steps.{$index}.role" => "Step \"{$name}\" needs an existing, active role."]);
            }

            $status = trim((string) ($step['status'] ?? ''));
            $steps[] = [
                'id' => 'cda-step-'.($index + 1),
                'name' => $name,
                'role' => $step['role'],
                'kind' => 'approve',
                'status' => $status === '' ? null : $status,
            ];
        }

        $old = ApprovalWorkflowSetting::where('type', ApprovalWorkflowSetting::TYPE_CDA)->value('steps') ?? [];
        $setting = ApprovalWorkflowSetting::updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_CDA], ['steps' => $steps]);

        if ($old !== $steps) {
            $this->auditCda($old, $steps, 'CDA review chain saved: '.implode(', ', array_map(
                fn (array $step) => $step['name'].' ('.CdaApprovalChain::roleLabel($step['role']).')',
                $steps,
            )).'.');
        }

        return response()->json([
            'data' => [
                'type' => $setting->type,
                'steps' => $setting->steps,
                'is_default' => false,
            ],
        ]);
    }

    /**
     * The CDA chain's audit row, filed under CDA settings (the chain is CDA
     * configuration, and its row may be gone after a reset).
     *
     * @param  array<int, mixed>  $old
     * @param  array<int, mixed>  $new
     */
    private function auditCda(array $old, array $new, string $description): void
    {
        AuditLogService::log('cda_review_chain_updated', CdaSetting::current(), ['steps' => $old], ['steps' => $new], $description);
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
