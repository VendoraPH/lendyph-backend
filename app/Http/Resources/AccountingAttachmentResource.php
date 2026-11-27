<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * An attachment on a journal or an expense.
 *
 * `url` is the short-lived signed `files.document` link, the only way to the
 * file: the stored path is never returned. `can_remove` follows the removal
 * rule for the caller and this owner (AttachmentScope::canRemove()).
 *
 * @mixin Document
 */
#[OA\Schema(
    schema: 'AccountingAttachment',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'label', type: 'string', nullable: true, example: 'Official receipt'),
        new OA\Property(property: 'original_filename', type: 'string', example: 'or-0001.pdf'),
        new OA\Property(property: 'mime_type', type: 'string', example: 'application/pdf'),
        new OA\Property(property: 'file_size', type: 'integer', description: 'Bytes'),
        new OA\Property(property: 'url', type: 'string', description: 'Signed link, valid for 30 minutes'),
        new OA\Property(
            property: 'uploaded_by',
            type: 'object',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
            ],
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'can_remove', type: 'boolean'),
    ],
)]
class AccountingAttachmentResource extends JsonResource
{
    public function __construct(Document $resource, private bool $canRemove)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $uploader = $this->uploader;

        return [
            'id' => $this->id,
            'label' => $this->label,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'url' => $this->url,
            'uploaded_by' => $uploader ? ['id' => $uploader->id, 'name' => $uploader->full_name] : null,
            'created_at' => $this->created_at,
            'can_remove' => $this->canRemove,
        ];
    }
}
