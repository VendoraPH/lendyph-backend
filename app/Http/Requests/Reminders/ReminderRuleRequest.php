<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use App\Models\ReminderTemplate;
use App\Services\BranchAccess;
use App\Services\Reminders\Api\TemplateFit;
use App\Services\Reminders\ContactHours;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reminder rule, checked as the rule form checks it (`validateRule` in the
 * frontend's src/lib/reminders.ts): 0 days on the due date and 1-90
 * otherwise, a send time inside contact hours (before they end), at least
 * one channel, and a template that fits the trigger (TemplateFit::FOR_TRIGGER).
 * A `branch_id` outside the caller's branches fails like a missing one, and
 * a rule with no branch needs `branches:view_all`.
 */
class ReminderRuleRequest extends FormRequest
{
    public const MAX_DAYS = 90;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('reminders:settings')) {
            return false;
        }

        // A rule with no branch covers every branch: only staff who see every
        // branch may ask for one (Arvin, 2026-11-09).
        return filled($this->input('branch_id')) || $user->can(BranchAccess::VIEW_ALL);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(ReminderRule::TRIGGERS)],
            'days' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                $days = (int) $value;

                if ($this->input('trigger') === 'on_due' && $days !== 0) {
                    $fail('Days must be 0 on the due date.');
                } elseif ($this->input('trigger') !== 'on_due' && ($days < 1 || $days > self::MAX_DAYS)) {
                    $fail('Days must be between 1 and '.self::MAX_DAYS.'.');
                }
            }],
            'send_time' => ['required', 'date_format:H:i', function (string $attribute, mixed $value, Closure $fail): void {
                $hours = ContactHours::from(ReminderSetting::current());
                [$hour, $minute] = array_map('intval', explode(':', (string) $value));
                $at = $hour * 60 + $minute;

                if ($at < $hours->startMinute || $at >= $hours->endMinute) {
                    $fail(sprintf('The send time must be within contact hours: from %s and before %s.', self::clock($hours->startMinute), self::clock($hours->endMinute)));
                }
            }],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', 'distinct', Rule::in(ReminderRule::CHANNELS)],
            'branch_id' => ['nullable', 'integer', app(BranchAccess::class)->constrainBranchExists(Rule::exists('branches', 'id'), $this->user())],
            'loan_product_id' => ['nullable', 'integer', Rule::exists('loan_products', 'id')],
            'borrower_type' => ['sometimes', Rule::in(ReminderRule::BORROWER_TYPES)],
            'template_type' => ['required', Rule::in(ReminderTemplate::TYPES), function (string $attribute, mixed $value, Closure $fail): void {
                $fits = TemplateFit::FOR_TRIGGER[(string) $this->input('trigger')] ?? null;

                if ($fits !== null && ! in_array($value, $fits, true)) {
                    $fail(sprintf('This rule can use only: %s.', implode(', ', array_map(ReminderTemplate::typeLabel(...), $fits))));
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
