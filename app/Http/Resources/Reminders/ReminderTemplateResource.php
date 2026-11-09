<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderTemplate;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReminderTemplate
 */
class ReminderTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'body' => $this->body,
            'language' => $this->language,
            'is_active' => (bool) $this->is_active,
            'updated_at' => ReminderFormat::time($this->updated_at),
        ];
    }
}
