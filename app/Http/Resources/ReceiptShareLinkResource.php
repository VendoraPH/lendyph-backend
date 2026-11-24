<?php

namespace App\Http\Resources;

use App\Models\ReceiptShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A receipt share link as staff see it (finish run round 2, decision 6). The
 * token is never part of it: the token is returned once, when the link is made.
 *
 * @mixin ReceiptShareLink
 */
#[OA\Schema(
    schema: 'ReceiptShareLink',
    description: 'A receipt share link as staff see it: its status and who made or revoked it, never its token (shown once, when it is made). Times are Manila time.',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'expired', 'revoked']),
        new OA\Property(property: 'status_label', type: 'string', enum: ['Active', 'Expired', 'Revoked']),
        new OA\Property(property: 'created_at', type: 'string', example: '2026-11-24 09:15:00'),
        new OA\Property(property: 'created_by', type: 'string', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', example: '2026-12-24 09:15:00'),
        new OA\Property(property: 'revoked_at', type: 'string', nullable: true),
        new OA\Property(property: 'revoked_by', type: 'string', nullable: true),
    ],
)]
class ReceiptShareLinkResource extends JsonResource
{
    private const STATUS_LABELS = [
        ReceiptShareLink::STATUS_ACTIVE => 'Active',
        ReceiptShareLink::STATUS_EXPIRED => 'Expired',
        ReceiptShareLink::STATUS_REVOKED => 'Revoked',
    ];

    public function toArray(Request $request): array
    {
        $status = $this->status();

        return [
            'id' => $this->id,
            'status' => $status,
            'status_label' => self::STATUS_LABELS[$status],
            'created_at' => $this->created_at?->toDateTimeString(),
            'created_by' => $this->creator?->full_name,
            'expires_at' => $this->expires_at->toDateTimeString(),
            'revoked_at' => $this->revoked_at?->toDateTimeString(),
            'revoked_by' => $this->revoker?->full_name,
        ];
    }
}
