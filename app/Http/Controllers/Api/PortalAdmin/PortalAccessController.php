<?php

namespace App\Http\Controllers\Api\PortalAdmin;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalUser;
use App\Services\BranchAccess;
use App\Services\Portal\Admin\AccessDesk;
use App\Services\Portal\Auth\CodeDesk;
use App\Services\Portal\PortalAccess;
use App\Services\Portal\PortalNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Staff access to members' portal accounts (portal design §3, §4): counts,
 * the accounts list, a member's access card, activation and reset codes,
 * turning access off and on, unlocking, signing out everywhere and the
 * communications log. `{borrower}` uses the branch-scoped binding, so another
 * branch's member answers 404. A code is shown once, in the 201 body, and
 * never again.
 */
class PortalAccessController extends Controller
{
    public function __construct(
        private readonly AccessDesk $desk,
        private readonly CodeDesk $codes,
        private readonly PortalAccess $access,
        private readonly PortalNotices $notices,
        private readonly BranchAccess $branches,
    ) {}

    #[OA\Get(
        path: '/api/portal-admin/stats',
        summary: 'Member Portal counts for the caller\'s branches',
        description: 'Needs member_portal:view. {eligible (active members), invited, codes_expired (invited with no live code), active, active_this_month (Manila month), never_signed_in, locked, disabled}. A `branch_id` outside the caller\'s branches answers 403.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The counts'), new OA\Response(response: 403, description: 'Missing member_portal:view, or another branch')],
    )]
    public function stats(Request $request): JsonResponse
    {
        $this->authorize('member_portal:view');
        $filters = $request->validate(['branch_id' => ['nullable', 'integer', 'min:1']]);
        $this->branches->authorizeBranchFilter($request->user(), $filters['branch_id'] ?? null);

        return response()->json(['success' => true, 'data' => $this->desk->stats($request->user(), isset($filters['branch_id']) ? (int) $filters['branch_id'] : null)]);
    }

    #[OA\Get(
        path: '/api/portal-admin/accounts',
        summary: 'Members with their portal state, paged',
        description: 'Needs member_portal:view. Rows {borrower_id, member_number, full_name, branch, member_status, state (not_invited|invited|active|locked|disabled), invited_at, activated_at, last_login_at, contact_number, email}. Contact details are null without borrowers:view. Caller\'s branches only; another `branch_id` answers 403.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: AccessDesk::STATES)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: '{data, meta{current_page, last_page, per_page, total}}'), new OA\Response(response: 403, description: 'Missing member_portal:view, or another branch')],
    )]
    public function accounts(Request $request): JsonResponse
    {
        $this->authorize('member_portal:view');
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(AccessDesk::STATES)],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->branches->authorizeBranchFilter($request->user(), $filters['branch_id'] ?? null);

        $page = $this->desk->accounts($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/portal-admin/members/{borrower}/access',
        summary: 'A member\'s portal access card',
        description: 'Needs member_portal:view. {member{id, name, member_number, status, has_birthdate}, state, invited_at, activated_at, last_login_at, password_changed_at, locked_until, disabled_reason, terms_version, code{kind, expires_at, status}}. Never the code itself. Another branch\'s member answers 404.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The card'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(Borrower $borrower): JsonResponse
    {
        $this->authorize('member_portal:view');

        return response()->json(['success' => true, 'data' => $this->desk->card($borrower)]);
    }

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/invite',
        summary: 'Issue an activation code (shown once)',
        description: 'Needs member_portal:invite. `identity_checked_as` is the valid ID type the staff member checked (recorded in the audit row). 201 {code (XXXX-XXXX-XXXX), kind, expires_at, slip{title, member_name, member_number, code, expires_at, expires_at_text, portal_path, instructions[]}}, sent with no-store: the code is never shown again, logged or audited. Supersedes an open code. 422 when the member isn\'t active, has no birthdate, has access turned off, or no terms are published; 409 when the member already uses the portal ("issue a reset code"); 429 after 5 codes for the member in 24 hours or 30 by the caller in an hour.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['identity_checked_as'], properties: [
            new OA\Property(property: 'identity_checked_as', type: 'string', example: 'UMID'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The code, once'),
            new OA\Response(response: 409, description: 'Already active on the portal'),
            new OA\Response(response: 422, description: 'In words'),
            new OA\Response(response: 429, description: 'Too many codes'),
        ],
    )]
    public function invite(Request $request, Borrower $borrower): JsonResponse
    {
        return $this->issue($request, $borrower, PortalCode::KIND_ACTIVATION);
    }

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/reset-code',
        summary: 'Issue a password reset code (shown once)',
        description: 'Needs member_portal:invite. For an active or locked portal account; the same body and limits as the invite. 422 for an account not activated yet ("issue a new activation code") or with access turned off.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['identity_checked_as'], properties: [
            new OA\Property(property: 'identity_checked_as', type: 'string', example: 'UMID'),
        ])),
        responses: [new OA\Response(response: 201, description: 'The code, once'), new OA\Response(response: 422, description: 'In words'), new OA\Response(response: 429, description: 'Too many codes')],
    )]
    public function resetCode(Request $request, Borrower $borrower): JsonResponse
    {
        return $this->issue($request, $borrower, PortalCode::KIND_RESET);
    }

    #[OA\Put(
        path: '/api/portal-admin/members/{borrower}/access',
        summary: 'Turn a member\'s portal access off or back on',
        description: 'Needs member_portal:manage. Turning it off needs a `reason` (member_request, security_concern, duplicate_account, administrative) and signs the member out everywhere. Access ended by a withdrawal or death can\'t be turned back on while the membership is ended (422). Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['enabled'], properties: [
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'reason', type: 'string', enum: PortalUser::STAFF_DISABLE_REASONS),
        ])),
        responses: [new OA\Response(response: 200, description: 'The card'), new OA\Response(response: 422, description: 'In words')],
    )]
    public function updateAccess(Request $request, Borrower $borrower): JsonResponse
    {
        $this->authorize('member_portal:manage');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'required_if:enabled,false,0', 'string', Rule::in(PortalUser::STAFF_DISABLE_REASONS)],
        ], ['reason.required_if' => 'Choose why portal access is being turned off.']);

        $portalUser = $this->portalUserOrFail($borrower);

        if ($request->boolean('enabled')) {
            if (in_array($borrower->status, Borrower::FORMER_MEMBER_STATUSES, true)) {
                throw ValidationException::withMessages(['enabled' => ['This membership has ended, so portal access stays off.']]);
            }

            $this->access->enable($portalUser, (int) $request->user()->getKey());
        } else {
            $this->access->disable($portalUser, (string) $validated['reason'], (int) $request->user()->getKey());
        }

        return response()->json(['success' => true, 'data' => $this->desk->card($borrower)]);
    }

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/unlock',
        summary: 'Unlock a member\'s portal account',
        description: 'Needs member_portal:manage. Clears a 15-minute lockout or a lock until staff unlock, and the failure count. An account with nothing to clear is unchanged. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The card, with `unlocked`'), new OA\Response(response: 422, description: 'Not invited yet')],
    )]
    public function unlock(Request $request, Borrower $borrower): JsonResponse
    {
        $this->authorize('member_portal:manage');

        $unlocked = $this->access->unlock($this->portalUserOrFail($borrower), (int) $request->user()->getKey());

        return response()->json(['success' => true, 'message' => $unlocked ? 'Portal account unlocked.' : 'This portal account wasn\'t locked.', 'data' => [...$this->desk->card($borrower), 'unlocked' => $unlocked]]);
    }

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/sign-out',
        summary: 'Sign a member out of every device',
        description: 'Needs member_portal:manage. Ends every portal session and tells the member in the portal. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{sessions_ended}'), new OA\Response(response: 422, description: 'Not invited yet')],
    )]
    public function signOut(Request $request, Borrower $borrower): JsonResponse
    {
        $this->authorize('member_portal:manage');

        $portalUser = $this->portalUserOrFail($borrower);
        $count = $this->access->signOutEverywhere($portalUser, (int) $request->user()->getKey());
        $this->notices->signedOutByStaff($portalUser);

        return response()->json(['success' => true, 'message' => 'The member was signed out of every device.', 'data' => ['sessions_ended' => $count]]);
    }

    #[OA\Get(
        path: '/api/portal-admin/members/{borrower}/communications',
        summary: 'What the portal sent or recorded for a member',
        description: 'Needs member_portal:view. [{date, kind, description}], newest first: codes issued (kind only), OTPs sent (masked number, never the value), notices (titles), and the member\'s downloads.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The log')],
    )]
    public function communications(Borrower $borrower): JsonResponse
    {
        $this->authorize('member_portal:view');

        return response()->json(['success' => true, 'data' => $this->desk->communications($borrower)]);
    }

    private function issue(Request $request, Borrower $borrower, string $kind): JsonResponse
    {
        $this->authorize('member_portal:invite');

        $validated = $request->validate([
            'identity_checked_as' => ['required', 'string', 'max:64'],
        ], ['identity_checked_as.required' => 'Tick that you checked the member\'s valid ID, and say which ID it was.']);

        $issued = $this->codes->issue($borrower, $kind, trim((string) $validated['identity_checked_as']), $request->user());

        return response()->json(['success' => true, 'data' => $issued], 201)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function portalUserOrFail(Borrower $borrower): PortalUser
    {
        return PortalUser::query()->where('borrower_id', $borrower->getKey())->first()
            ?? throw ValidationException::withMessages(['member' => ['This member hasn\'t been invited to the portal yet.']]);
    }
}
