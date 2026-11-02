<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Services\AuditLogService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Throwable;

class DocumentController extends Controller implements HasMiddleware
{
    private const PARENT_MODELS = [
        'borrower' => Borrower::class,
        'coMaker' => CoMaker::class,
        'loan' => Loan::class,
    ];

    /**
     * Resolve the `{borrower}`, `{coMaker}` or `{loan}` parent before anything
     * else runs, StoreDocumentRequest's validation included.
     *
     * The routes can't type-hint one parent, so implicit binding never sees
     * it. Resolving it here, through the model's own route binding, scopes it
     * to the caller's branches exactly as a typed parameter is: another
     * branch's parent answers 404 whatever the body, never 422.
     */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                foreach (self::PARENT_MODELS as $key => $modelClass) {
                    $value = $request->route($key);

                    if ($value === null || $value instanceof Model) {
                        continue;
                    }

                    $request->route()->setParameter(
                        $key,
                        (new $modelClass)->resolveRouteBinding($value) ?? abort(404),
                    );
                }

                return $next($request);
            }, only: ['index', 'store']),
        ];
    }

    private function resolveParent(): Model
    {
        foreach (array_keys(self::PARENT_MODELS) as $key) {
            $value = request()->route($key);

            if ($value instanceof Model) {
                return $value;
            }
        }

        abort(404, 'Document parent not found.');
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
    #[OA\Get(
        path: '/api/loans/{loanId}/documents',
        summary: 'List loan documents',
        description: 'Returns all documents attached to a loan (e.g. policy exception letters).',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Document list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('borrowers:view');

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
    #[OA\Post(
        path: '/api/loans/{loanId}/documents',
        summary: 'Upload loan document',
        description: 'Attach a document to a loan. Common use: policy exception letters attached to loans that require BOD review.',
        tags: ['Documents'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'loanId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'type'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'type', type: 'string', example: 'policy_exception_letter'),
                        new OA\Property(property: 'label', type: 'string', example: 'Policy Exception Letter'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Document uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden — requires loans:update permission'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $parent = $this->resolveParent();
        $file = $request->file('file');
        $type = $request->input('type');

        $modelType = strtolower(class_basename($parent));
        $path = $file->store("documents/{$type}/{$modelType}/{$parent->id}", 'private');

        try {
            $document = $parent->documents()->create([
                'type' => $type,
                'label' => $request->input('label'),
                'file_path' => $path,
                'original_filename' => Document::cleanOriginalFilename($file->getClientOriginalName()),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        } catch (Throwable $e) {
            // No row points at the stored file, so nothing would ever remove it.
            Storage::disk('private')->delete($path);

            throw $e;
        }

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
        $this->authorize('borrowers:view');

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
        $this->authorize('borrowers:delete');

        Storage::disk('private')->delete($document->file_path);

        AuditLogService::log('deleted', $document->documentable, description: "Document '{$document->type}' deleted");

        $document->delete();

        return response()->json(['message' => 'Document deleted successfully.']);
    }
}
