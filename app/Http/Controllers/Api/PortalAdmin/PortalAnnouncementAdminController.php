<?php

namespace App\Http\Controllers\Api\PortalAdmin;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\PortalAnnouncement;
use App\Services\BranchAccess;
use App\Services\Portal\Announcements\AnnouncementDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Staff announcements to members (portal design §3, §4, §7):
 * `member_portal:announcements`. Company-wide audiences (all members, a
 * member type) need `branches:view_all`; branch staff announce to, and see,
 * only their own branches (another's announcement answers 404; a branch_id
 * filter outside theirs 403).
 */
class PortalAnnouncementAdminController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly AnnouncementDesk $desk,
        private readonly BranchAccess $branches,
    ) {}

    #[OA\Get(
        path: '/api/portal-admin/announcements',
        summary: 'Announcements to members',
        description: 'member_portal:announcements. Newest first, 25 a page. Rows: {id, title, body, audience, branch_ids, branches[{id, name}], member_types, publish_at, expires_at, pinned, status, live, reads_count, created_by, published_by, created_at, updated_at}.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: PortalAnnouncement::STATUSES)),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'The announcements'), new OA\Response(response: 403, description: 'Missing member_portal:announcements, or another branch')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('member_portal:announcements');
        $this->branches->authorizeBranchFilter($request->user(), $request->query('branch_id'));

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PortalAnnouncement::STATUSES)],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $page = $this->desk->scope($request->user())
            ->when($filters['status'] ?? null, fn ($q, string $status) => $q->where('status', $status))
            ->when($filters['branch_id'] ?? null, fn ($q, $branch) => $q->whereJsonContains('branch_ids', (int) $branch))
            ->withCount('reads')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (PortalAnnouncement $a) => $this->desk->row($a))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    #[OA\Post(
        path: '/api/portal-admin/announcements',
        summary: 'Draft an announcement',
        description: 'member_portal:announcements. {title (150), body (plain text, 5,000), audience (all|branch|member_type), branch_ids (branch), member_types (member_type), publish_at?, expires_at?, pinned?}. all and member_type need branches:view_all (403); branch_ids only the caller\'s branches (422). A draft until published. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'The draft'), new OA\Response(response: 403, description: 'No permission, or a company-wide audience without branches:view_all'), new OA\Response(response: 422, description: 'Invalid')],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('member_portal:announcements');
        $data = $request->validate($this->rules($request, partial: false), $this->messages());

        return response()->json(['success' => true, 'data' => $this->desk->row($this->desk->create($data, $request->user()))], 201);
    }

    #[OA\Put(
        path: '/api/portal-admin/announcements/{announcement}',
        summary: 'Edit an announcement',
        description: 'member_portal:announcements. A draft or published one (a withdrawn one is 422); the same fields as drafting, each optional. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The announcement'), new OA\Response(response: 404, description: 'Not one the caller manages'), new OA\Response(response: 422, description: 'Invalid or withdrawn')],
    )]
    public function update(Request $request, int $announcement): JsonResponse
    {
        $this->authorize('member_portal:announcements');
        $found = $this->desk->find($request->user(), $announcement);
        $data = $request->validate($this->rules($request, partial: true), $this->messages());

        return response()->json(['success' => true, 'data' => $this->desk->row($this->desk->update($found, $data, $request->user()))]);
    }

    #[OA\Delete(
        path: '/api/portal-admin/announcements/{announcement}',
        summary: 'Delete a draft',
        description: 'member_portal:announcements. Drafts only (422 otherwise: withdraw instead). Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Deleted'), new OA\Response(response: 404, description: 'Not one the caller manages'), new OA\Response(response: 422, description: 'Not a draft')],
    )]
    public function destroy(Request $request, int $announcement): JsonResponse
    {
        $this->authorize('member_portal:announcements');
        $this->desk->delete($this->desk->find($request->user(), $announcement), $request->user());

        return response()->json(['success' => true, 'message' => 'The draft was deleted.']);
    }

    #[OA\Post(
        path: '/api/portal-admin/announcements/{announcement}/publish',
        summary: 'Publish a draft',
        description: 'member_portal:announcements. Members see it from its publish_at (now when none). 409 when it isn\'t a draft. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Published'), new OA\Response(response: 404, description: 'Not one the caller manages'), new OA\Response(response: 409, description: 'Not a draft')],
    )]
    public function publish(Request $request, int $announcement): JsonResponse
    {
        $this->authorize('member_portal:announcements');

        return response()->json(['success' => true, 'data' => $this->desk->row($this->desk->publish($this->desk->find($request->user(), $announcement), $request->user()))]);
    }

    #[OA\Post(
        path: '/api/portal-admin/announcements/{announcement}/withdraw',
        summary: 'Withdraw a published announcement',
        description: 'member_portal:announcements. Members stop seeing it. 409 when it isn\'t published. Audited.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'announcement', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Withdrawn'), new OA\Response(response: 404, description: 'Not one the caller manages'), new OA\Response(response: 409, description: 'Not published')],
    )]
    public function withdraw(Request $request, int $announcement): JsonResponse
    {
        $this->authorize('member_portal:announcements');

        return response()->json(['success' => true, 'data' => $this->desk->row($this->desk->withdraw($this->desk->find($request->user(), $announcement), $request->user()))]);
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $partial): array
    {
        $presence = $partial ? ['sometimes'] : [];

        return [
            'title' => [...$presence, 'required', 'string', 'max:150'],
            'body' => [...$presence, 'required', 'string', 'max:5000'],
            'audience' => [...$presence, 'required', 'string', Rule::in(PortalAnnouncement::AUDIENCES)],
            'branch_ids' => ['exclude_unless:audience,'.PortalAnnouncement::AUDIENCE_BRANCH, 'required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', $this->branches->constrainBranchExists(Rule::exists('branches', 'id'), $request->user())],
            'member_types' => ['exclude_unless:audience,'.PortalAnnouncement::AUDIENCE_MEMBER_TYPE, 'required', 'array', 'min:1'],
            'member_types.*' => ['string', 'distinct', Rule::in(Borrower::MEMBER_TYPES)],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', $request->filled('publish_at') ? 'after:publish_at' : 'after:now'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'title.required' => 'Give the announcement a title.',
            'title.max' => 'The title can be at most 150 characters.',
            'body.required' => 'Write the announcement.',
            'body.max' => 'The announcement can be at most 5,000 characters.',
            'audience.in' => 'Choose all members, some branches or a member type.',
            'branch_ids.required' => 'Choose at least one branch.',
            'branch_ids.*.exists' => 'Choose one of your own branches.',
            'member_types.required' => 'Choose at least one member type.',
            'member_types.*.in' => 'Choose regular or associate members.',
            'expires_at.after' => 'The end date must be after the start.',
        ];
    }
}
