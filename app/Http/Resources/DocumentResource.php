<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Document',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', example: 'valid_id'),
        new OA\Property(property: 'label', type: 'string', nullable: true, example: 'philippine_id'),
        new OA\Property(property: 'custom_type_name', type: 'string', nullable: true, example: 'Company HR ID'),
        new OA\Property(property: 'id_number', type: 'string', nullable: true, example: 'N01-23-456789'),
        new OA\Property(property: 'side', type: 'string', nullable: true, enum: ['front', 'back'], description: 'Which side of the ID (for paired uploads)'),
        new OA\Property(property: 'url', type: 'string'),
        new OA\Property(property: 'original_filename', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'file_size', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'portal_shared_at', type: 'string', format: 'date-time', nullable: true, example: '2026-11-10T10:15:00+08:00', description: 'When the file was shared with the member in the Member Portal (Manila time), or null. Sent only to staff who can share it (member_portal:manage and borrowers:view).'),
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
            'custom_type_name' => $this->custom_type_name,
            'id_number' => $this->id_number,
            'side' => $this->side,
            'url' => $this->url,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'created_at' => $this->created_at,
            'portal_shared_at' => $this->when(self::canShare($request), fn (): ?string => $this->portalSharedAt()),
        ];
    }

    /** Staff who could share the file with the member ("Share with member" needs both). */
    private static function canShare(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->can('member_portal:manage') && $user->can('borrowers:view');
    }

    /** The share time, stored in the app's timezone, as a Manila ISO datetime. */
    private function portalSharedAt(): ?string
    {
        $sharedAt = $this->resource->getAttribute('portal_shared_at');

        return $sharedAt === null ? null : Carbon::parse($sharedAt, config('app.timezone'))->setTimezone('Asia/Manila')->toIso8601String();
    }
}
