<?php

namespace App\Http\Resources\Reminders;

use App\Models\ReminderRule;
use App\Services\Reminders\Api\ReminderFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReminderRule
 */
class ReminderRuleResource extends JsonResource
{
    public const RELATIONS = ['branch:id,name', 'loanProduct:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger' => $this->trigger,
            'days' => (int) $this->days,
            'send_time' => ReminderFormat::clock($this->send_time),
            'channels' => array_values((array) $this->channels),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'loan_product_id' => $this->loan_product_id,
            'loan_product_name' => $this->loanProduct?->name,
            'borrower_type' => $this->borrower_type,
            'template_type' => $this->template_type,
            'is_active' => (bool) $this->is_active,
            'created_at' => ReminderFormat::time($this->created_at),
            'updated_at' => ReminderFormat::time($this->updated_at),
        ];
    }
}
