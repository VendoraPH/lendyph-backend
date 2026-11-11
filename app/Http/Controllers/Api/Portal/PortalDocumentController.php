<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\Pdf\PortalDownload;
use App\Services\Portal\Read\DocumentReader;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents a member may see (portal design §1): files staff shared, and the
 * PDFs the server makes. Files stream after sign-in, never by a link.
 */
class PortalDocumentController extends Controller
{
    public function __construct(private readonly DocumentReader $documents) {}

    #[OA\Get(
        path: '/api/portal/documents',
        summary: 'The member\'s documents',
        description: 'Signed-in portal token; feature `documents`. {files[]{id, title, category, shared_on, file_name, size (bytes)}, generated[]{kind (loan_statement, share_capital_statement, share_capital_certificate, savings_statement, time_deposit_certificate), id (the loan, account or placement; null for share capital), title, reference}}. Files are those staff shared on the member or the member\'s own loans; never a co-maker\'s file or a valid ID. `generated` follows the statements feature and each area\'s feature.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The documents')],
    )]
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->documents->list()]);
    }

    #[OA\Get(
        path: '/api/portal/documents/{id}/download',
        summary: 'Download a shared document',
        description: 'Signed-in portal token; feature `documents`. Streams the file as an attachment (`nosniff`, `no-store`). 404 for a file not shared with the member, another member\'s file, a co-maker\'s file, a valid ID or a file that is gone. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The file'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function download(int $id, PortalDownload $download): StreamedResponse
    {
        return $download->file($this->documents->find($id));
    }
}
