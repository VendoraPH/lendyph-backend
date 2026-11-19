<?php

namespace App\Http\Controllers\Api\Amla;

use App\Http\Controllers\Controller;
use App\Http\Resources\Amla\AmlaCasePresenter;
use App\Http\Resources\Amla\AmlaDetectionPresenter;
use App\Models\AmlaAlert;
use App\Models\AmlaAlertAction;
use App\Services\Amla\Cases\AlertWorkflow;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The AMLA alert workflow (AMLA-B6; task §12). Each route checks
 * `amla:alerts` before the alert is looked up; an alert outside the caller's
 * scope answers 404. Escalating also needs `amla:cases` and head-office
 * access, since cases are head office's. Every step is in AMLA's own history
 * and the AMLA log.
 */
class AmlaAlertWorkflowController extends Controller
{
    public function __construct(
        private readonly AmlaAccess $access,
        private readonly AlertWorkflow $workflow,
        private readonly AmlaDetectionPresenter $presenter,
        private readonly AmlaLog $log,
    ) {}

    #[OA\Post(path: '/api/amla/alerts/{id}/assign', summary: 'Assign an AMLA alert', description: 'Needs `amla:alerts`. `assignee_id`: an active user holding `amla:alerts` who can see the alert; optional `note`. The assignee is told in AMLA\'s own inbox, by reference and priority only. 409 once the alert is escalated, closed or cleared.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The alert')])]
    public function assign(Request $request, int $id): JsonResponse
    {
        $alert = $this->alert($request, $id);
        $input = $request->validate(['assignee_id' => ['required', 'integer', 'min:1'], 'note' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->workflow->assign($request->user(), $alert, (int) $input['assignee_id'], $input['note'] ?? null));
    }

    #[OA\Post(path: '/api/amla/alerts/{id}/review', summary: 'Start reviewing an AMLA alert', description: 'Needs `amla:alerts`. Open or assigned to under review; optional `note`. 409 otherwise.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The alert')])]
    public function review(Request $request, int $id): JsonResponse
    {
        $alert = $this->alert($request, $id);
        $input = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->workflow->review($request->user(), $alert, $input['note'] ?? null));
    }

    #[OA\Post(path: '/api/amla/alerts/{id}/close', summary: 'Close an AMLA alert with a documented reason', description: 'Needs `amla:alerts`. `reason` is required and kept encrypted in the alert\'s history. The window is freed, so later activity raises a new alert.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The alert')])]
    public function close(Request $request, int $id): JsonResponse
    {
        $alert = $this->alert($request, $id);
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], ['reason.required' => 'Say why the alert is closed.']);

        return $this->respond($request, $this->workflow->close($request->user(), $alert, $input['reason']));
    }

    #[OA\Post(path: '/api/amla/alerts/{id}/escalate', summary: 'Escalate an AMLA alert to a case', description: 'Needs `amla:alerts`, `amla:cases` and `branches:view_all`. Into an open case about the same subject (`case_id`), or a new case (`title`, optional).', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 201, description: 'The case')])]
    public function escalate(Request $request, int $id, AmlaDetectionPresenter $alerts): JsonResponse
    {
        $this->access->authorizeHeadOffice($request->user(), AmlaAccess::CASES);
        $alert = $this->alert($request, $id);
        $input = $request->validate([
            'case_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $case = $this->workflow->escalate($request->user(), $alert, $input['case_id'] ?? null, $input['title'] ?? null, $input['note'] ?? null);

        return response()->json(['success' => true, 'data' => AmlaCasePresenter::caseDetail($case->load(['borrower', 'walkIn', 'branch', 'assignee', 'opener', 'decider']), $alerts, $request->user())], 201);
    }

    #[OA\Get(path: '/api/amla/alerts/{id}/history', summary: 'An AMLA alert\'s workflow history', description: 'Needs `amla:alerts`. Each step with who, when, from and to which status, and its note. Logged.', tags: ['AMLA'], security: [['sanctum' => []]], responses: [new OA\Response(response: 200, description: 'The history')])]
    public function history(Request $request, int $id): JsonResponse
    {
        $alert = $this->alert($request, $id);
        $this->log->record('alert_history_viewed', $request->user(), 'alert', $alert->id, ['reference' => $alert->reference]);

        return response()->json([
            'success' => true,
            'data' => $alert->actions()->with(['user', 'assignee'])->orderBy('id')->get()->map(fn (AmlaAlertAction $action) => AmlaCasePresenter::alertAction($action, $request->user()))->values()->all(),
        ]);
    }

    private function alert(Request $request, int $id): AmlaAlert
    {
        $this->access->authorize($request->user(), AmlaAccess::ALERTS);

        return $this->workflow->find($request->user(), $id);
    }

    private function respond(Request $request, AmlaAlert $alert): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->presenter->alertRow($alert->load(['events', 'borrower', 'walkIn', 'branch', 'assignee', 'amlaCase']), $request->user())]);
    }
}
