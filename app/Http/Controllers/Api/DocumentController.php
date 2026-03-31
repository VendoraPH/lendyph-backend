<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class DocumentController extends Controller
{
    private function resolveParent(): Model
    {
        if ($borrower = request()->route('borrower')) {
            return $borrower;
        }

        return request()->route('coMaker');
    }

    #[OA\Get(
        path: '/api/borrowers/{borrowerId}/documents',
        summary: 'List borrower documents',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    #[OA\Get(
        path: '/api/co-makers/{coMakerId}/documents',
        summary: 'List co-maker documents',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'coMakerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('customers.view');

        $parent = $this->resolveParent();

        return DocumentResource::collection($parent->documents);
    }

    #[OA\Post(
        path: '/api/borrowers/{borrowerId}/documents',
        summary: 'Upload borrower document',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'type'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'type', type: 'string', example: 'valid_id'),
                        new OA\Property(property: 'label', type: 'string', example: 'PhilID Front'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Document uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    #[OA\Post(
        path: '/api/co-makers/{coMakerId}/documents',
        summary: 'Upload co-maker document',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'coMakerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'type'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'type', type: 'string', example: 'valid_id'),
                        new OA\Property(property: 'label', type: 'string', example: 'PhilID Front'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Document uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $parent = $this->resolveParent();
        $file = $request->file('file');
        $type = $request->input('type');

        $modelType = strtolower(class_basename($parent));
        $path = $file->store("documents/{$type}/{$modelType}/{$parent->id}", 'public');

        $document = $parent->documents()->create([
            'type' => $type,
            'label' => $request->input('label'),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        AuditLogService::log('uploaded', $parent, description: "Document '{$type}' uploaded for {$modelType} #{$parent->id}");

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/documents/{id}',
        summary: 'Show document',
        description: 'Get document metadata and URL',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Document $document): DocumentResource
    {
        $this->authorize('customers.view');

        return new DocumentResource($document);
    }

    #[OA\Delete(
        path: '/api/documents/{id}',
        summary: 'Delete document',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(Document $document): JsonResponse
    {
        $this->authorize('customers.delete');

        Storage::disk('public')->delete($document->file_path);

        AuditLogService::log('deleted', $document->documentable, description: "Document '{$document->type}' deleted");

        $document->delete();

        return response()->json(['message' => 'Document deleted successfully.']);
    }
}
