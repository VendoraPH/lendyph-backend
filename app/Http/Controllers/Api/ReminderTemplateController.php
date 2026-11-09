<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ReminderTemplateRequest;
use App\Http\Requests\Reminders\TemplatePreviewRequest;
use App\Http\Resources\Reminders\ReminderTemplateResource;
use App\Models\Loan;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Services\Reminders\Api\LoanReminderSummary;
use App\Services\Reminders\ReminderEligibility;
use App\Services\Reminders\ReminderRenderer;
use App\Services\Reminders\SmsParts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Reminder templates, and the server's preview of one. Each change audits
 * itself.
 */
class ReminderTemplateController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/templates',
        summary: 'Reminder templates (unpaginated)',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The templates'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('reminders:view');

        $templates = ReminderTemplate::query()->orderBy('type')->orderBy('channel')->orderBy('language')->orderBy('id')->get();

        return response()->json(['data' => ReminderTemplateResource::collection($templates)->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/reminders/templates',
        summary: 'Create a reminder template',
        description: 'Email needs a subject. An unknown {{variable}} answers 422, and so does a second template for the same type, channel and language.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'The template'),
            new OA\Response(response: 403, description: 'Missing reminders:settings or branches:view_all'),
            new OA\Response(response: 422, description: 'Invalid template, unknown variables or a duplicate'),
        ],
    )]
    public function store(ReminderTemplateRequest $request): JsonResponse
    {
        $this->authorize('reminders:settings');

        $template = $this->saveOnce(fn () => ReminderTemplate::query()->create(array_merge(
            $request->validated(),
            ['language' => $request->templateLanguage(), 'is_active' => $request->boolean('is_active', true), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id],
        )));

        return response()->json(['message' => 'Reminder template created.', 'data' => (new ReminderTemplateResource($template))->resolve($request)], 201);
    }

    #[OA\Put(
        path: '/api/reminders/templates/{id}',
        summary: 'Update a reminder template',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The template'),
            new OA\Response(response: 403, description: 'Missing reminders:settings or branches:view_all'),
            new OA\Response(response: 404, description: 'No such template'),
            new OA\Response(response: 422, description: 'Invalid template, unknown variables or a duplicate'),
        ],
    )]
    public function update(ReminderTemplateRequest $request, int $id): JsonResponse
    {
        $this->authorize('reminders:settings');
        $template = ReminderTemplate::query()->whereKey($id)->firstOrFail();

        $this->saveOnce(fn () => $template->update(array_merge(
            $request->validated(),
            ['language' => $request->templateLanguage(), 'updated_by' => $request->user()->id],
        )));

        return response()->json(['message' => 'Reminder template updated.', 'data' => (new ReminderTemplateResource($template->fresh()))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/reminders/templates/preview',
        summary: 'Render a template against a loan\'s next open period, or sample values',
        description: 'Writes nothing. Returns the rendered subject and body, the unknown variables and, for SMS, its parts.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'subject, body, unknown_variables, sms_parts'),
            new OA\Response(response: 403, description: 'Missing reminders:settings'),
            new OA\Response(response: 422, description: 'Invalid request, or a loan outside the caller\'s branches'),
        ],
    )]
    public function preview(TemplatePreviewRequest $request, ReminderRenderer $renderer, ReminderEligibility $eligibility): JsonResponse
    {
        $this->authorize('reminders:settings');
        $channel = (string) $request->validated('channel');
        $settings = ReminderSetting::current();
        $loan = $request->filled('loan_id')
            ? Loan::query()->with(['borrower', 'branch', 'amortizationSchedules'])->findOrFail((int) $request->validated('loan_id'))
            : null;
        $period = $loan === null ? null : LoanReminderSummary::nextOpenPeriod($loan);

        $values = $loan !== null && $period !== null
            ? $renderer->variables($loan, (int) $period->period_number, $period->due_date, $eligibility->amountDue($loan, $period, now()), $channel, $settings)
            : $renderer->sampleVariables($channel, $settings);
        $rendered = $renderer->render($request->validated('subject'), (string) $request->validated('body'), $values, $channel);

        return response()->json(['data' => [
            'subject' => $rendered->subject,
            'body' => $rendered->body,
            'unknown_variables' => $rendered->unknownVariables,
            'sms_parts' => $channel === 'sms' ? SmsParts::count($rendered->body) : null,
        ]]);
    }

    /**
     * Two saves racing for the same type, channel and language: the loser
     * answers the same 422 as the validation check, never 500.
     *
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private function saveOnce(callable $save): mixed
    {
        try {
            return $save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['type' => ReminderTemplateRequest::DUPLICATE]);
        }
    }
}
