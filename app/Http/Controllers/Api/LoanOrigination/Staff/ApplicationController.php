<?php

namespace App\Http\Controllers\Api\LoanOrigination\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\Staff\AddApplicationNoteRequest;
use App\Http\Requests\LoanOrigination\Staff\AssignApplicationRequest;
use App\Http\Requests\LoanOrigination\Staff\LinkMemberRequest;
use App\Http\Requests\LoanOrigination\Staff\ListApplicationsRequest;
use App\Http\Requests\LoanOrigination\Staff\RequestInformationRequest;
use App\Http\Requests\LoanOrigination\Staff\TransitionApplicationRequest;
use App\Models\OriginationApplication;
use App\Services\LoanOrigination\Staff\ApplicantMessages;
use App\Services\LoanOrigination\Staff\ApplicationPresenter;
use App\Services\LoanOrigination\Staff\ApplicationQueue;
use App\Services\LoanOrigination\Staff\MemberMatches;
use App\Services\LoanOrigination\Staff\StaffAccess;
use App\Services\LoanOrigination\Staff\StaffProcessing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Staff processing of online applications (Phase 6, LO-B3): the list, the
 * detail, moves, notes, assignment, information requests, reminders and
 * member matches. Reads need `loan_origination:view`, writes
 * `loan_origination:update` (StaffAccess, in the form requests). Another
 * branch's application answers 404 through the route binding. Messages to
 * the applicant are logged, never sent.
 */
class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationPresenter $presenter,
        private readonly StaffProcessing $processing,
    ) {}

    #[OA\Get(
        path: '/api/loan-origination/applications',
        summary: 'Online applications',
        description: 'The caller\'s branches only. Filters: search (reference, name; a mobile with borrowers:view), status, branch_id, loan_product_id, officer_id, assigned (me, none), is_test, date_from/date_to (started), sort, direction. `meta.stats` holds per-status counts and `total`, over every filter but the status. Mobiles are masked without borrowers:view.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'A page of applications'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view, or a branch outside the caller\'s'),
        ],
    )]
    public function index(ListApplicationsRequest $request, ApplicationQueue $queue): JsonResponse
    {
        $filters = $request->validated();
        $page = $queue->page($request->user(), $filters, $request->perPage());

        return response()->json([
            'data' => $page->getCollection()->map(fn (OriginationApplication $a) => $this->presenter->summary($a, $request->user()))->values(),
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'stats' => $queue->stats($request->user(), $filters),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/loan-origination/applications/{application}',
        summary: 'One online application',
        description: 'The draft, server totals, documents, notes, history, consents, applicant messages (logged, not sent), member matches (borrowers:view) and the staff moves the caller may make. Contacts and address lines are masked without borrowers:view and the branch.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The application'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view'),
            new OA\Response(response: 404, description: 'No such application, or another branch\'s'),
        ],
    )]
    public function show(Request $request, OriginationApplication $application): JsonResponse
    {
        abort_unless(StaffAccess::canView($request->user()), 403, StaffAccess::VIEW_REFUSAL);

        return $this->detail($request, $application);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/transition',
        summary: 'Move an online application',
        description: 'Only the staff moves of the transition table; For Approval is reached only by the hand-off. Rejecting also needs loans:reject. `applicant_message` is logged, never sent; on a rejection or withdrawal it is the decision notice. 409 when `version` is not the current one.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The application after the move'),
            new OA\Response(response: 403, description: 'Missing a permission, in words'),
            new OA\Response(response: 409, description: 'The application changed meanwhile'),
            new OA\Response(response: 422, description: 'A move the table doesn\'t allow, in words'),
        ],
    )]
    public function transition(TransitionApplicationRequest $request, OriginationApplication $application): JsonResponse
    {
        $message = $this->processing->transition(
            $application,
            (string) $request->validated('to'),
            $request->user(),
            (int) $request->validated('version'),
            $request->validated('note'),
            $request->validated('applicant_message'),
        );

        return $this->detail($request, $application->fresh(), ['applicant_message' => $message === null ? null : ApplicantMessages::present($message)]);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/notes',
        summary: 'Add an internal note',
        description: 'Permanent; never shown to the applicant. Audited by ids only.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 201, description: 'The note'), new OA\Response(response: 403, description: 'Missing loan_origination:update')],
    )]
    public function addNote(AddApplicationNoteRequest $request, OriginationApplication $application): JsonResponse
    {
        $note = $this->processing->addNote($application, $request->user(), (string) $request->validated('body'));

        return response()->json(['success' => true, 'data' => [
            'id' => $note->id,
            'body' => $note->body,
            'author_name' => $request->user()->full_name,
            'created_at' => $note->created_at?->toIso8601String(),
        ]], 201);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/assign',
        summary: 'Assign the officer',
        description: 'An active holder of loan_origination:update who can see the application\'s branch (GET /staff?for=origination), or null. A refused pick answers one fixed message. The officer is told in the app. 409 when `version` is not the current one.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The application'),
            new OA\Response(response: 409, description: 'The application changed meanwhile'),
            new OA\Response(response: 422, description: 'That staff member can\'t be assigned'),
        ],
    )]
    public function assign(AssignApplicationRequest $request, OriginationApplication $application): JsonResponse
    {
        $officerId = $request->validated('officer_id');
        $this->processing->assign($application, $officerId === null ? null : (int) $officerId, $request->user(), (int) $request->validated('version'));

        return $this->detail($request, $application->fresh());
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/request-information',
        summary: 'Ask the applicant for more information',
        description: 'Moves the application to Additional Information Required (or keeps it there), asks again for the named document types and logs the message (never sent).',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The application, with `applicant_message`'),
            new OA\Response(response: 409, description: 'The application changed meanwhile'),
            new OA\Response(response: 422, description: 'Not allowed from this status, or an unknown document type'),
        ],
    )]
    public function requestInformation(RequestInformationRequest $request, OriginationApplication $application): JsonResponse
    {
        $message = $this->processing->requestInformation(
            $application,
            $request->user(),
            (int) $request->validated('version'),
            (string) $request->validated('message'),
            $request->documentTypes(),
        );

        return $this->detail($request, $application->fresh(), ['applicant_message' => ApplicantMessages::present($message)]);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/remind',
        summary: 'Remind the applicant to finish',
        description: 'Only for an unsubmitted application, at most once in 24 hours. Logged, never sent: `delivered` is false and `sent_at` null.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{channel, sent_at, delivered, delivery_label}'), new OA\Response(response: 422, description: 'Not a draft, no contact, or reminded in the last 24 hours')],
    )]
    public function remind(Request $request, OriginationApplication $application): JsonResponse
    {
        abort_unless(StaffAccess::canUpdate($request->user()), 403, StaffAccess::UPDATE_REFUSAL);

        return response()->json(['success' => true, 'data' => $this->processing->remind($application, $request->user())]);
    }

    #[OA\Get(
        path: '/api/loan-origination/applications/{application}/member-matches',
        summary: 'Members this applicant may be',
        description: 'Same full name, mobile (last 10 digits) or email, among members the caller can see; suggestions only, never linked by themselves. Needs borrowers:view.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Up to 5 matches with a reason'), new OA\Response(response: 403, description: 'Missing loan_origination:view or borrowers:view')],
    )]
    public function memberMatches(Request $request, OriginationApplication $application, MemberMatches $matches): JsonResponse
    {
        abort_unless(StaffAccess::canView($request->user()), 403, StaffAccess::VIEW_REFUSAL);
        abort_unless(StaffAccess::canSeeMembers($request->user()), 403, StaffAccess::MATCHES_REFUSAL);

        return response()->json(['success' => true, 'data' => $matches->for($application, $request->user())]);
    }

    #[OA\Post(
        path: '/api/loan-origination/applications/{application}/link-member',
        summary: 'Link an existing member',
        description: 'A member in the caller\'s branches, or null to clear. Former members are refused, and nothing changes after the hand-off. The member\'s record is never changed. Needs loan_origination:update and borrowers:view.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The application'),
            new OA\Response(response: 409, description: 'The application changed meanwhile'),
            new OA\Response(response: 422, description: 'That member can\'t be linked, in words'),
        ],
    )]
    public function linkMember(LinkMemberRequest $request, OriginationApplication $application): JsonResponse
    {
        $borrowerId = $request->validated('borrower_id');
        $this->processing->linkMember($application, $borrowerId === null ? null : (int) $borrowerId, $request->user(), (int) $request->validated('version'));

        return $this->detail($request, $application->fresh());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function detail(Request $request, OriginationApplication $application, array $extra = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [...$this->presenter->detail($application, $request->user()), ...$extra]]);
    }
}
