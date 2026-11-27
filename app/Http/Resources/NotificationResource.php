<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One staff in-app notification, as the header bell reads it.
 *
 * `title` and `body` are finished plain-English text with ₱ amounts, shown as
 * sent. `url` is a frontend path. `data` holds the ids the event concerns.
 * Times carry their offset (ISO 8601), so the bell's relative times are right
 * whatever timezone the browser is in.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = is_array($this->data) ? $this->data : [];

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $payload['title'] ?? '',
            'body' => $payload['body'] ?? '',
            'url' => $payload['url'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'data' => (object) ($payload['data'] ?? []),
        ];
    }
}
