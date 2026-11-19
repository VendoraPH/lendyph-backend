<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Requests\Amla\AmlaCalendarRequest;
use App\Http\Requests\Amla\AmlaDeadlinePreviewRequest;
use App\Http\Resources\Amla\AmlaPresenter;
use App\Models\AmlaCalendarEntry;
use App\Models\AmlaCalendarYear;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaSettingsVersion;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaMonitoring;
use App\Services\Amla\Foundation\AmlaSettings;
use App\Services\Amla\Foundation\ChangeRequests;
use App\Services\Amla\Foundation\Deadlines;
use App\Services\Amla\Foundation\WorkingDayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The AMLA foundation's reads (AMLA design §4.12–15): the module status, the
 * settings in force and their history, the working-day calendar and a
 * deadline preview. Changes go through AmlaChangeRequestController.
 */
class AmlaFoundationController extends Controller
{
    public function __construct(private readonly AmlaAccess $access) {}

    #[OA\Get(
        path: '/api/amla/status',
        summary: 'AMLA module status',
        description: 'Needs `amla:view`. Whether the server monitoring switch is on (off by default), the settings version in force and how many settings are still to verify, and whether this Manila year\'s calendar is confirmed.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The status'), new OA\Response(response: 403, description: 'Missing amla:view, in words')],
    )]
    public function status(Request $request, AmlaMonitoring $monitoring, AmlaSettings $settings): JsonResponse
    {
        $this->access->authorize($request->user(), AmlaAccess::VIEW);

        $current = $settings->current();
        $year = (int) CarbonImmutable::now(WorkingDayCalendar::TIMEZONE)->format('Y');

        return response()->json(['success' => true, 'data' => [
            'monitoring_enabled' => $monitoring->enabled(),
            'settings_version' => $current->version,
            'settings_to_verify' => count($current->to_verify),
            'calendar_year' => $year,
            'calendar_confirmed' => AmlaCalendarYear::query()->where('year', $year)->exists(),
        ]]);
    }

    #[OA\Get(
        path: '/api/amla/settings',
        summary: 'AMLA settings in force',
        description: 'Needs `amla:view` or `amla:settings`, and `branches:view_all`. Each setting with its label, unit, value (money as a two-decimal string), value in words, whether it is still to verify, and what confirmed it. `pending_request_id` is the settings change waiting for approval, if any.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The settings version in force'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function settings(Request $request, AmlaSettings $settings): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), [AmlaAccess::VIEW, AmlaAccess::SETTINGS]);

        $current = $settings->current()->load('creator', 'approver');
        $pending = AmlaChangeRequest::query()->where('pending_key', AmlaChangeRequest::SETTINGS)->value('id');

        return response()->json(['success' => true, 'data' => [
            ...AmlaPresenter::settingsVersion($current),
            'pending_request_id' => $pending === null ? null : (int) $pending,
        ]]);
    }

    #[OA\Get(
        path: '/api/amla/settings/versions',
        summary: 'AMLA settings history',
        description: 'Needs `amla:settings` or `amla:audit`, and `branches:view_all`. Every version, newest first; version 1 is the system default.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The versions'), new OA\Response(response: 403, description: 'Missing permission or head-office access, in words')],
    )]
    public function settingsVersions(Request $request): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), [AmlaAccess::SETTINGS, AmlaAccess::AUDIT]);

        $versions = AmlaSettingsVersion::query()->with('creator', 'approver')->orderByDesc('version')->get();

        return response()->json(['success' => true, 'data' => $versions->map(fn (AmlaSettingsVersion $version) => AmlaPresenter::settingsVersion($version))->values()]);
    }

    #[OA\Get(
        path: '/api/amla/calendar',
        summary: 'AMLA working-day calendar for a year',
        description: 'Needs `amla:view`. `year` defaults to this Manila year. The days in force that aren\'t working days (holidays, AMLC non-reporting days, facility suspensions) with their source and approver, whether the year is confirmed (`provisional` when not), and the calendar changes waiting for approval for that year.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 2026))],
        responses: [new OA\Response(response: 200, description: 'The calendar'), new OA\Response(response: 403, description: 'Missing amla:view, in words')],
    )]
    public function calendar(AmlaCalendarRequest $request): JsonResponse
    {
        $year = (int) ($request->validated('year') ?? CarbonImmutable::now(WorkingDayCalendar::TIMEZONE)->format('Y'));
        $confirmed = AmlaCalendarYear::query()->where('year', $year)->first();

        $entries = AmlaCalendarEntry::query()->active()
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->with('request.decider')
            ->orderBy('date')->orderBy('kind')
            ->get();

        $pending = AmlaChangeRequest::query()
            ->where('status', AmlaChangeRequest::PENDING)
            ->whereIn('type', [AmlaChangeRequest::CALENDAR_ADD, AmlaChangeRequest::CALENDAR_REMOVE, AmlaChangeRequest::CALENDAR_CONFIRM_YEAR])
            ->with('requester', 'decider')
            ->orderBy('id')
            ->get()
            ->filter(fn (AmlaChangeRequest $change) => ChangeRequests::calendarYear($change) === $year);

        return response()->json(['success' => true, 'data' => [
            'year' => $year,
            'confirmed' => $confirmed !== null,
            'confirmed_at' => $confirmed?->confirmed_at,
            'provisional' => $confirmed === null,
            'entries' => $entries->map(fn (AmlaCalendarEntry $entry) => AmlaPresenter::calendarEntry($entry))->values(),
            'pending' => $pending->map(fn (AmlaChangeRequest $change) => AmlaPresenter::changeRequest($change))->values(),
        ]]);
    }

    #[OA\Get(
        path: '/api/amla/deadlines/preview',
        summary: 'Preview an AMLA report deadline',
        description: 'Needs `amla:view`. `kind` ctr or str, `from` the transaction date (CTR) or the date the suspicion was established (STR), YYYY-MM-DD. Counts working days from the next day under the settings in force; `provisional` when a year it reaches has no confirmed calendar, `to_verify` while the deadline setting is unconfirmed.',
        tags: ['AMLA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'kind', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['ctr', 'str'])),
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'The due date'), new OA\Response(response: 403, description: 'Missing amla:view, in words'), new OA\Response(response: 422, description: 'Bad kind or date, in words')],
    )]
    public function deadlinePreview(AmlaDeadlinePreviewRequest $request, Deadlines $deadlines): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $deadlines->due((string) $request->validated('kind'), (string) $request->validated('from'))]);
    }
}
