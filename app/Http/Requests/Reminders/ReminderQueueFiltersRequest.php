<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderRule;
use App\Services\Reminders\Api\ReminderLists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The queue's and dashboard's filters (frontend `ReminderListFilters`).
 * `per_page` is clamped to 1-100 by the list, not refused.
 */
class ReminderQueueFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reminders:view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer'],
            'loan_product_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'borrower_id' => ['nullable', 'integer'],
            'channel' => ['nullable', Rule::in(ReminderRule::CHANNELS)],
            'status' => ['nullable', Rule::in($this->statuses())],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return list<string>
     */
    protected function statuses(): array
    {
        return ReminderLists::QUEUE_STATUSES;
    }
}
