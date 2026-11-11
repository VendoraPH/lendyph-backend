<?php

namespace App\Http\Controllers\Api\PortalAdmin;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Services\Portal\Read\DocumentSharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * "Share with member" (portal design §1, §3, §4): needs member_portal:manage
 * and borrowers:view, checked before anything is looked up. `{borrower}`
 * uses the branch-scoped binding, so another branch's member answers 404,
 * and the file is found among that member's own and their loans' files.
 */
class PortalDocumentShareController extends Controller
{
    public function __construct(private readonly DocumentSharing $sharing) {}

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/documents/share',
        summary: 'Share a member\'s file in their Member Portal',
        description: 'Needs member_portal:manage and borrowers:view. `document_id`: one of the member\'s own files or a file of one of their loans, which becomes downloadable in the member\'s portal. 404 for any other file; 422 in words for a valid ID or a co-maker\'s file. Sharing again changes nothing. Audited. {id, shared, portal_shared_at}.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_id'], properties: [
            new OA\Property(property: 'document_id', type: 'integer'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Shared'),
            new OA\Response(response: 403, description: 'A permission is missing'),
            new OA\Response(response: 404, description: 'Not this member\'s file, or a member outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'This file can\'t be shared'),
        ],
    )]
    public function share(Request $request, Borrower $borrower): JsonResponse
    {
        $this->authorizeSharing();
        $document = $this->sharing->find($borrower, $this->documentId($request));

        return response()->json(['success' => true, 'data' => $this->sharing->share($document, $request->user())]);
    }

    #[OA\Post(
        path: '/api/portal-admin/members/{borrower}/documents/unshare',
        summary: 'Stop sharing a member\'s file in the Member Portal',
        description: 'Needs member_portal:manage and borrowers:view. `document_id` as for share. Unsharing a file that isn\'t shared changes nothing. Audited. {id, shared, portal_shared_at}.',
        tags: ['Member Portal (staff)'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['document_id'], properties: [
            new OA\Property(property: 'document_id', type: 'integer'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'No longer shared'),
            new OA\Response(response: 403, description: 'A permission is missing'),
            new OA\Response(response: 404, description: 'Not this member\'s file, or a member outside the caller\'s branches'),
        ],
    )]
    public function unshare(Request $request, Borrower $borrower): JsonResponse
    {
        $this->authorizeSharing();
        $document = $this->sharing->find($borrower, $this->documentId($request));

        return response()->json(['success' => true, 'data' => $this->sharing->unshare($document)]);
    }

    private function authorizeSharing(): void
    {
        $this->authorize('member_portal:manage');
        $this->authorize('borrowers:view');
    }

    private function documentId(Request $request): int
    {
        return (int) $request->validate(
            ['document_id' => ['required', 'integer', 'min:1']],
            ['document_id.required' => 'Choose the file to share.', 'document_id.integer' => 'Choose the file to share.', 'document_id.min' => 'Choose the file to share.'],
        )['document_id'];
    }
}
