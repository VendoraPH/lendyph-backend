<?php

namespace App\Http\Controllers\Api\Linda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Linda\UpdateLindaSettingsRequest;
use App\Services\Linda\LindaBudget;
use App\Services\Linda\LindaSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Settings → Linda: the model Linda uses. Company-wide, so reading and
 * changing it needs `linda:settings` and `branches:view_all`. Both work while
 * Linda is off. The key, the cap and the switch are never sent or changed
 * here. Each change writes one audit row in words.
 */
class LindaSettingsController extends Controller
{
    public function __construct(
        private readonly LindaSettings $settings,
        private readonly LindaBudget $budget,
    ) {}

    #[OA\Get(
        path: '/api/linda/settings',
        summary: 'Linda settings',
        description: 'Needs linda:settings and branches:view_all. The model in use, the models on offer with ₱ prices per million tokens and about ₱ per question, this Manila month\'s AI cost against the cap and the questions asked (from the log), and whether the AI is on for this server (`ai.reason`: linda_off, model_off, test_driver or no_key; null when on).',
        tags: ['Linda'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The settings'), new OA\Response(response: 403, description: 'Missing linda:settings or branches:view_all')],
    )]
    public function show(Request $request): JsonResponse
    {
        if (! UpdateLindaSettingsRequest::allows($request->user())) {
            throw new AuthorizationException(UpdateLindaSettingsRequest::COMPANY_WIDE_REFUSAL);
        }

        return response()->json(['success' => true, 'data' => $this->present()]);
    }

    #[OA\Put(
        path: '/api/linda/settings',
        summary: 'Choose the model Linda uses',
        description: 'Needs linda:settings and branches:view_all. `model` must be one of the models on offer (422 otherwise). Audited in words.',
        tags: ['Linda'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['model'], properties: [
            new OA\Property(property: 'model', type: 'string', example: 'claude-haiku-5-5'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The saved settings'),
            new OA\Response(response: 403, description: 'Missing linda:settings or branches:view_all'),
            new OA\Response(response: 422, description: 'A model Linda doesn\'t offer'),
        ],
    )]
    public function update(UpdateLindaSettingsRequest $request): JsonResponse
    {
        $this->settings->update((string) $request->validated('model'), $request->user());

        return response()->json(['success' => true, 'message' => 'Linda settings saved.', 'data' => $this->present()]);
    }

    /**
     * @return array{model: string, models: list<array<string, string>>, ai: array{on: bool, reason: ?string}, month: array{label: string, cost: string, cap: string, questions: int, model_questions: int}, updated_at: ?string, updated_by: ?string}
     */
    private function present(): array
    {
        $row = $this->settings->row();

        return [
            'model' => $this->settings->model(),
            'models' => $this->settings->models(),
            'ai' => $this->settings->aiStatus(),
            'month' => $this->budget->summary(),
            'updated_at' => $row?->updated_at?->toIso8601String(),
            'updated_by' => $row?->updatedBy?->full_name,
        ];
    }
}
