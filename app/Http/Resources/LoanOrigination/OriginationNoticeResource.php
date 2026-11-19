<?php

namespace App\Http\Resources\LoanOrigination;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\OriginationNotice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published notice version. `final` is the owner's approved wording; until
 * it is, screens mark the text as draft wording.
 *
 * @mixin OriginationNotice
 */
class OriginationNoticeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OriginationNotice $notice */
        $notice = $this->resource;

        return [
            'id' => $notice->id,
            'type' => $notice->type,
            'type_label' => OriginationNotice::TYPE_LABELS[$notice->type] ?? 'Notice',
            'version' => $notice->version,
            'title' => $notice->title,
            'body' => $notice->body,
            'body_hash' => $notice->body_hash,
            'final' => $notice->is_final,
            'published_at' => $notice->published_at,
            'published_by' => SavingsUserRef::from($notice->publisher),
        ];
    }
}
