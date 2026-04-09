<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Borrower\StoreBorrowerRequest;
use App\Http\Requests\Borrower\UpdateBorrowerRequest;
use App\Http\Resources\BorrowerResource;
use App\Http\Resources\DocumentResource;
use App\Models\Borrower;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class BorrowerController extends Controller
{
    #[OA\Get(
        path: '/api/borrowers',
        summary: 'List borrowers',
        description: 'Get a paginated list of borrowers with search and filters',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'blacklisted'])),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated borrower list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('borrowers:view');

        $borrowers = Borrower::with('branch')
            ->when(request('search'), fn ($q, $search) => $q->search($search))
            ->when(request('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(request('branch_id'), fn ($q, $branchId) => $q->forBranch($branchId))
            ->latest()
            ->paginate(request('per_page', 15));

        return BorrowerResource::collection($borrowers);
    }

    #[OA\Post(
        path: '/api/borrowers',
        summary: 'Create borrower',
        description: 'Create a new borrower profile',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name', 'branch_id'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Juan'),
                    new OA\Property(property: 'middle_name', type: 'string', example: 'Santos'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Dela Cruz'),
                    new OA\Property(property: 'suffix', type: 'string', example: 'Jr.'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', example: '1990-01-15'),
                    new OA\Property(property: 'civil_status', type: 'string', enum: ['single', 'married', 'widowed', 'separated', 'divorced']),
                    new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female']),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09171234567'),
                    new OA\Property(property: 'email', type: 'string', example: 'juan@email.com'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number', example: 25000),
                    new OA\Property(property: 'branch_id', type: 'integer', example: 1),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Borrower created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreBorrowerRequest $request): JsonResponse
    {
        $borrower = Borrower::create($request->validated());
        $borrower->load('branch');

        return (new BorrowerResource($borrower))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/borrowers/{id}',
        summary: 'Show borrower',
        description: 'Get full borrower profile with co-makers and documents',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(Borrower $borrower): BorrowerResource
    {
        $this->authorize('borrowers:view');

        $borrower->load('branch', 'coMakers.documents', 'documents');

        return new BorrowerResource($borrower);
    }

    #[OA\Put(
        path: '/api/borrowers/{id}',
        summary: 'Update borrower',
        description: 'Update borrower profile',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'first_name', type: 'string'),
                    new OA\Property(property: 'middle_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string'),
                    new OA\Property(property: 'suffix', type: 'string'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date'),
                    new OA\Property(property: 'civil_status', type: 'string'),
                    new OA\Property(property: 'gender', type: 'string'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string'),
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'employer_or_business', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number'),
                    new OA\Property(property: 'branch_id', type: 'integer'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Borrower updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateBorrowerRequest $request, Borrower $borrower): BorrowerResource
    {
        $borrower->update($request->validated());
        $borrower->load('branch');

        return new BorrowerResource($borrower);
    }

    #[OA\Delete(
        path: '/api/borrowers/{id}',
        summary: 'Delete borrower',
        description: 'Permanently delete a borrower and all related records',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:delete');

        DB::transaction(function () use ($borrower) {
            // Delete co-maker documents from disk
            foreach ($borrower->coMakers as $coMaker) {
                foreach ($coMaker->documents as $doc) {
                    Storage::disk('public')->delete($doc->file_path);
                }
                $coMaker->documents()->delete();
            }

            // Delete borrower documents from disk
            foreach ($borrower->documents as $doc) {
                Storage::disk('public')->delete($doc->file_path);
            }
            $borrower->documents()->delete();

            // Delete photo from disk
            if ($borrower->photo_path) {
                Storage::disk('public')->delete($borrower->photo_path);
            }

            $borrower->delete();
        });

        return response()->json(['message' => 'Borrower deleted successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/deactivate',
        summary: 'Deactivate borrower',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower deactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function deactivate(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        $borrower->update(['status' => 'inactive']);

        return response()->json(['message' => 'Borrower deactivated successfully.']);
    }

    #[OA\Patch(
        path: '/api/borrowers/{id}/reactivate',
        summary: 'Reactivate borrower',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Borrower reactivated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function reactivate(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        $borrower->update(['status' => 'active']);

        return response()->json(['message' => 'Borrower reactivated successfully.']);
    }

    #[OA\Post(
        path: '/api/borrowers/{id}/photo',
        summary: 'Upload borrower photo',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['photo'],
                    properties: [
                        new OA\Property(property: 'photo', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Photo uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadPhoto(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        request()->validate([
            'photo' => ['required', 'image', 'max:5120'],
        ]);

        // Delete old photo
        if ($borrower->photo_path) {
            Storage::disk('public')->delete($borrower->photo_path);
        }

        $path = request()->file('photo')->store("borrowers/photos/{$borrower->id}", 'public');
        $borrower->update(['photo_path' => $path]);

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo_url' => Storage::disk('public')->url($path),
        ]);
    }

    #[OA\Delete(
        path: '/api/borrowers/{id}/photo',
        summary: 'Delete borrower photo',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Photo deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function deletePhoto(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        if ($borrower->photo_path) {
            Storage::disk('public')->delete($borrower->photo_path);
            $borrower->update(['photo_path' => null]);
        }

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    #[OA\Post(
        path: '/api/borrowers/{id}/valid-ids',
        summary: 'Upload borrower valid ID',
        tags: ['Borrowers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file', 'type'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary'),
                        new OA\Property(property: 'type', type: 'string', example: 'PhilSys ID'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Valid ID uploaded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function uploadValidId(Borrower $borrower): JsonResponse
    {
        $this->authorize('borrowers:update');

        request()->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf'],
            'type' => ['required', 'string', 'max:100'],
        ]);

        $file = request()->file('file');
        $path = $file->store("documents/valid_id/borrower/{$borrower->id}", 'public');

        $document = $borrower->documents()->create([
            'type' => 'valid_id',
            'label' => request()->input('type'),
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(201);
    }
}
