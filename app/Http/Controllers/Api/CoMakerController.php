<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoMaker\StoreCoMakerRequest;
use App\Http\Requests\CoMaker\UpdateCoMakerRequest;
use App\Http\Resources\CoMakerResource;
use App\Models\Borrower;
use App\Models\CoMaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;

class CoMakerController extends Controller
{
    #[OA\Get(
        path: '/api/borrowers/{borrowerId}/co-makers',
        summary: 'List co-makers for a borrower',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Borrower not found'),
        ],
    )]
    public function index(Borrower $borrower): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('customers.view');

        return CoMakerResource::collection(
            $borrower->coMakers()->with('documents')->get()
        );
    }

    #[OA\Post(
        path: '/api/borrowers/{borrowerId}/co-makers',
        summary: 'Create co-maker for a borrower',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'borrowerId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'last_name'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Maria'),
                    new OA\Property(property: 'middle_name', type: 'string'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Santos'),
                    new OA\Property(property: 'suffix', type: 'string'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string', example: '09181234567'),
                    new OA\Property(property: 'occupation', type: 'string'),
                    new OA\Property(property: 'employer', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number', example: 20000),
                    new OA\Property(property: 'relationship_to_borrower', type: 'string', example: 'Spouse'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Co-maker created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCoMakerRequest $request, Borrower $borrower): JsonResponse
    {
        $coMaker = $borrower->coMakers()->create($request->validated());

        return (new CoMakerResource($coMaker))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/co-makers/{id}',
        summary: 'Show co-maker',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(CoMaker $coMaker): CoMakerResource
    {
        $this->authorize('customers.view');

        $coMaker->load('borrower', 'documents');

        return new CoMakerResource($coMaker);
    }

    #[OA\Put(
        path: '/api/co-makers/{id}',
        summary: 'Update co-maker',
        tags: ['Co-makers'],
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
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'contact_number', type: 'string'),
                    new OA\Property(property: 'occupation', type: 'string'),
                    new OA\Property(property: 'employer', type: 'string'),
                    new OA\Property(property: 'monthly_income', type: 'number'),
                    new OA\Property(property: 'relationship_to_borrower', type: 'string'),
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Co-maker updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCoMakerRequest $request, CoMaker $coMaker): CoMakerResource
    {
        $coMaker->update($request->validated());

        return new CoMakerResource($coMaker);
    }

    #[OA\Delete(
        path: '/api/co-makers/{id}',
        summary: 'Delete co-maker',
        tags: ['Co-makers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Co-maker deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(CoMaker $coMaker): JsonResponse
    {
        $this->authorize('customers.delete');

        foreach ($coMaker->documents as $doc) {
            Storage::disk('public')->delete($doc->file_path);
        }
        $coMaker->documents()->delete();
        $coMaker->delete();

        return response()->json(['message' => 'Co-maker deleted successfully.']);
    }
}
