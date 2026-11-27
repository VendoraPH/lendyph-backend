<?php

namespace App\Http\Requests\Reminders;

use App\Models\ReminderRule;
use App\Models\ReminderTemplate;
use App\Services\BranchAccess;
use App\Services\Reminders\ReminderRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A reminder template. Email needs a subject; a `{{variable}}` the renderer
 * doesn't know (ReminderRenderer::unknownVariables, aliases included) is
 * refused; one template per type, channel and language. Writing one needs
 * `branches:view_all` as well as `reminders:settings`.
 */
class ReminderTemplateRequest extends FormRequest
{
    public const DUPLICATE = 'A template for this type, channel and language already exists.';

    public function authorize(): bool
    {
        $user = $this->user();

        // Templates speak for every branch, so only staff who see every
        // branch write them (Arvin, 2026-11-09).
        return $user !== null && $user->can('reminders:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ReminderTemplate::TYPES)],
            'channel' => ['required', Rule::in(ReminderRule::CHANNELS)],
            'subject' => ['nullable', 'required_if:channel,email', 'string', 'max:191'],
            'body' => ['required', 'string', 'max:5000'],
            'language' => ['sometimes', 'string', 'alpha_dash', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach (['subject', 'body'] as $field) {
                $unknown = ReminderRenderer::unknownVariables((string) $this->input($field));

                if ($unknown !== []) {
                    $validator->errors()->add($field, sprintf('The %s uses unknown variables: %s.', $field, implode(', ', $unknown)));
                }
            }

            $taken = ReminderTemplate::query()
                ->where('type', $this->input('type'))
                ->where('channel', $this->input('channel'))
                ->where('language', $this->templateLanguage())
                ->when($this->templateId() !== null, fn ($query) => $query->whereKeyNot($this->templateId()))
                ->exists();

            if ($taken) {
                $validator->errors()->add('type', self::DUPLICATE);
            }
        }];
    }

    public function templateLanguage(): string
    {
        return (string) ($this->input('language') ?: ReminderTemplate::DEFAULT_LANGUAGE);
    }

    private function templateId(): ?int
    {
        $id = $this->route('id');

        return $id === null ? null : (int) $id;
    }
}
