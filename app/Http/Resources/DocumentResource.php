<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Document',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', example: 'valid_id'),
        new OA\Property(property: 'label', type: 'string', nullable: true, example: "Driver's License"),
        new OA\Property(property: 'id_number', type: 'string', nullable: true, example: 'N01-23-456789'),
        new OA\Property(property: 'side', type: 'string', nullable: true, enum: ['front', 'back'], description: 'Which side of the ID (for paired uploads)'),
        new OA\Property(property: 'url', type: 'string'),
        new OA\Property(property: 'original_filename', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'file_size', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'id_number' => $this->id_number,
            'side' => $this->side,
            'url' => $this->url,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'created_at' => $this->created_at,
        ];
    }
}
