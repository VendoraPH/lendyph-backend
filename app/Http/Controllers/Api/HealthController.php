<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeploymentIdentity;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path: '/api/health',
        summary: 'Health check',
        description: 'Returns the API health status and which commit this deployment is serving.',
        tags: ['System'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'API is running',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'commit', type: 'string', nullable: true, example: 'cabc7af'),
                        new OA\Property(property: 'branch', type: 'string', nullable: true, example: 'main'),
                        new OA\Property(property: 'env', type: 'string', example: 'production'),
                    ],
                ),
            ),
        ],
    )]
    public function __invoke()
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            ...(new DeploymentIdentity(base_path()))->toArray(),
        ]);
    }
}
