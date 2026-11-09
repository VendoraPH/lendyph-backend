<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ReminderRuleRequest;
use App\Http\Resources\Reminders\ReminderRuleResource;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Reminder rules: when to remind, on which channels, for which loans. A
 * plain list. A rule scoped to a branch the caller can't see is left out of
 * the list and answers 404. A rule with no branch (stored or requested) can
 * be written only with `branches:view_all`, so a branch user manages only
 * their own branch's rules. Each change audits itself.
 */
class ReminderRuleController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}

    #[OA\Get(
        path: '/api/reminders/rules',
        summary: 'Reminder rules (unpaginated)',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The rules: every-branch rules and the caller\'s branches\' rules'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('reminders:view');

        $rules = $this->visible($request->user())->with(ReminderRuleResource::RELATIONS)->orderBy('id')->get();

        return response()->json(['data' => ReminderRuleResource::collection($rules)->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/reminders/rules',
        summary: 'Create a reminder rule',
        description: 'Days: 0 on the due date, 1-90 before or after it. The send time must be inside contact hours. At least one channel.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'trigger', 'days', 'send_time', 'channels', 'template_type'], properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'trigger', type: 'string', enum: ReminderRule::TRIGGERS),
            new OA\Property(property: 'days', type: 'integer'),
            new OA\Property(property: 'send_time', type: 'string', example: '09:00'),
            new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string', enum: ReminderRule::CHANNELS)),
            new OA\Property(property: 'branch_id', type: 'integer', nullable: true),
            new OA\Property(property: 'loan_product_id', type: 'integer', nullable: true),
            new OA\Property(property: 'borrower_type', type: 'string', enum: ReminderRule::BORROWER_TYPES),
            new OA\Property(property: 'template_type', type: 'string'),
            new OA\Property(property: 'is_active', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The rule'),
            new OA\Response(response: 403, description: 'Missing reminders:settings, or branches:view_all for a rule that covers every branch'),
            new OA\Response(response: 422, description: 'Invalid rule, or a branch outside the caller\'s branches'),
        ],
    )]
    public function store(ReminderRuleRequest $request): JsonResponse
    {
        $this->authorize('reminders:settings');

        $rule = ReminderRule::query()->create(array_merge(
            ['borrower_type' => 'all', 'is_active' => true],
            $this->attributes($request),
            ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id],
        ));

        return response()->json(['message' => 'Reminder rule created.', 'data' => $this->present($rule, $request)], 201);
    }

    #[OA\Put(
        path: '/api/reminders/rules/{id}',
        summary: 'Update a reminder rule',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The rule'),
            new OA\Response(response: 403, description: 'Missing reminders:settings, or branches:view_all for a rule that covers every branch'),
            new OA\Response(response: 404, description: 'No such rule the caller can see'),
            new OA\Response(response: 422, description: 'Invalid rule'),
        ],
    )]
    public function update(ReminderRuleRequest $request, int $id): JsonResponse
    {
        $this->authorize('reminders:settings');
        $rule = $this->visible($request->user())->whereKey($id)->firstOrFail();
        $this->authorizeEveryBranch($rule, $request->user());

        $rule->update(array_merge($this->attributes($request), ['updated_by' => $request->user()->id]));

        return response()->json(['message' => 'Reminder rule updated.', 'data' => $this->present($rule, $request)]);
    }

    #[OA\Post(
        path: '/api/reminders/rules/{id}/deactivate',
        summary: 'Switch a reminder rule off',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The rule, is_active false'),
            new OA\Response(response: 403, description: 'Missing reminders:settings, or branches:view_all for a rule that covers every branch'),
            new OA\Response(response: 404, description: 'No such rule the caller can see'),
        ],
    )]
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->authorize('reminders:settings');
        $rule = $this->visible($request->user())->whereKey($id)->firstOrFail();
        $this->authorizeEveryBranch($rule, $request->user());

        $rule->update(['is_active' => false, 'updated_by' => $request->user()->id]);

        return response()->json(['message' => 'Reminder rule switched off.', 'data' => $this->present($rule, $request)]);
    }

    /**
     * A rule with no branch covers every branch: changing it needs
     * `branches:view_all` (Arvin, 2026-11-09).
     */
    private function authorizeEveryBranch(ReminderRule $rule, User $user): void
    {
        abort_if($rule->branch_id === null && ! $user->can(BranchAccess::VIEW_ALL), 403, 'Only staff who see every branch can change a rule that covers every branch.');
    }

    /**
     * @return Builder<ReminderRule>
     */
    private function visible(User $user): Builder
    {
        $branchIds = $this->branches->for($user);

        return ReminderRule::query()->when($branchIds !== null, fn (Builder $query) => $query
            ->where(fn (Builder $scope) => $scope->whereNull('branch_id')->orWhereIn('branch_id', $branchIds)));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ReminderRuleRequest $request): array
    {
        $data = $request->validated();
        $data['send_time'] = $data['send_time'].':00';
        $data['channels'] = array_values(array_unique($data['channels']));

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReminderRule $rule, Request $request): array
    {
        return (new ReminderRuleResource($rule->fresh(ReminderRuleResource::RELATIONS)))->resolve($request);
    }
}
