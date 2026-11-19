<?php

namespace App\Http\Requests\LoanOrigination;

use App\Models\OriginationSetting;
use App\Services\LoanOrigination\ApplicationReference;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /loan-origination/settings`: the frontend's whole OriginationSettings
 * object, each section optional. Company-wide, so it needs
 * `loan_origination:settings` plus `branches:view_all`.
 *
 * - The reference format is a 2–5 letter prefix then `-YYYY-NNNNNN`, and the
 *   prefix is never one other Lendy records use (`LA`, `LN`, `RCP`, …).
 * - Files: JPEG, PNG and PDF only, at most 10 MB (the store's own limits;
 *   Settings can only narrow them).
 * - Rates are 0–100% with at most 4 decimals, money at most 2, never rounded.
 * - A product's required fields are fields of the application form, and its
 *   required documents are among the document types.
 */
class UpdateOriginationSettingsRequest extends FormRequest
{
    private const FILE_TYPES = ['jpg', 'jpeg', 'jpe', 'jfif', 'png', 'pdf'];

    public function authorize(): bool
    {
        return OriginationAccess::canChangeCompanyWide($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(OriginationAccess::SETTINGS_REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rate = ['numeric', 'min:0', 'max:100', 'decimal:0,4'];
        $money = ['numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];
        $documentTypes = $this->has('documents.types')
            ? array_values(array_filter(array_map(fn ($t) => is_array($t) ? ($t['type'] ?? null) : null, (array) $this->input('documents.types')), 'is_string'))
            : OriginationSetting::current()->documentTypeKeys();

        return [
            'general' => ['sometimes', 'array'],
            'general.online_applications_enabled' => ['sometimes', 'required', 'boolean'],
            'general.default_branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'general.reference_format' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2,5}-YYYY-NNNNNN$/'],
            'general.draft_expiration_days' => ['sometimes', 'required', 'integer', 'between:1,365'],
            'general.estimated_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,240'],

            'products' => ['sometimes', 'array', 'max:500'],
            'products.*' => ['array'],
            'products.*.loan_product_id' => ['required', 'integer', 'distinct', Rule::exists('loan_products', 'id')],
            'products.*.online' => ['required', 'boolean'],
            'products.*.required_fields' => ['present', 'array'],
            'products.*.required_fields.*' => ['string', Rule::in(DraftRules::fieldKeys())],
            'products.*.eligibility' => ['nullable', 'string', 'max:2000'],
            'products.*.required_documents' => ['present', 'array'],
            'products.*.required_documents.*' => ['string', Rule::in($documentTypes)],
            'products.*.instructions' => ['nullable', 'string', 'max:2000'],

            'assessment' => ['sometimes', 'array'],
            'assessment.max_dti' => ['sometimes', 'required', ...$rate],
            'assessment.min_coverage' => ['sometimes', 'required', ...$rate],
            'assessment.min_remaining_income' => ['sometimes', 'required', ...$money],
            'assessment.income_recognition' => ['sometimes', 'array'],
            'assessment.income_recognition.*' => ['required', ...$rate],
            'assessment.require_verified_income' => ['sometimes', 'required', 'boolean'],
            'assessment.min_employment_years' => ['sometimes', 'nullable', 'integer', 'between:0,60'],
            'assessment.missing_information_rule' => ['sometimes', 'required', Rule::in(['provisional', 'insufficient'])],
            'assessment.product_overrides' => ['sometimes', 'array', 'max:500'],
            'assessment.product_overrides.*' => ['array'],
            'assessment.product_overrides.*.loan_product_id' => ['required', 'integer', 'distinct', Rule::exists('loan_products', 'id')],
            'assessment.product_overrides.*.max_dti' => ['sometimes', 'nullable', ...$rate],
            'assessment.product_overrides.*.min_coverage' => ['sometimes', 'nullable', ...$rate],
            'assessment.product_overrides.*.min_remaining_income' => ['sometimes', 'nullable', ...$money],

            'documents' => ['sometimes', 'array'],
            'documents.accepted_file_types' => ['sometimes', 'required', 'array', 'min:1'],
            'documents.accepted_file_types.*' => ['string', 'distinct', Rule::in(self::FILE_TYPES)],
            'documents.max_upload_mb' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.OriginationFileStore::MAX_MB],
            'documents.retention_days' => ['sometimes', 'required', 'integer', 'between:30,3650'],
            'documents.types' => ['sometimes', 'array', 'max:30'],
            'documents.types.*' => ['array'],
            'documents.types.*.type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/', 'distinct'],
            'documents.types.*.label' => ['required', 'string', 'max:100'],
            'documents.types.*.description' => ['nullable', 'string', 'max:500'],
            'documents.types.*.required' => ['required', 'boolean'],

            'notifications' => ['sometimes', 'array'],
            'notifications.application_confirmation' => ['sometimes', 'required', 'boolean'],
            'notifications.incomplete_reminder' => ['sometimes', 'required', 'boolean'],
            'notifications.incomplete_reminder_after_days' => ['sometimes', 'required', 'integer', 'between:1,30'],
            'notifications.document_request' => ['sometimes', 'required', 'boolean'],
            'notifications.status_change' => ['sometimes', 'required', 'boolean'],
            'notifications.decision' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $format = $this->input('general.reference_format');

            if (is_string($format) && ! $validator->errors()->has('general.reference_format')) {
                $prefix = explode('-', $format)[0];

                if (in_array($prefix, ApplicationReference::RESERVED_PREFIXES, true)) {
                    $validator->errors()->add('general.reference_format', "{$prefix} is already used by other Lendy records. Choose another prefix.");
                }
            }

            foreach (array_keys((array) $this->input('assessment.income_recognition', [])) as $source) {
                if (! in_array($source, DraftRules::INCOME_SOURCES, true)) {
                    $validator->errors()->add("assessment.income_recognition.{$source}", 'Income recognition is set for employed, self-employed, business owner, farmer, pensioner and other income only.');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $rateMessages = function (string $key, string $label): array {
            $range = "The {$label} must be from 0% to 100%.";

            return [
                "{$key}.required" => "Enter the {$label}.",
                "{$key}.numeric" => $range,
                "{$key}.min" => $range,
                "{$key}.max" => $range,
                "{$key}.decimal" => "The {$label} can have at most 4 decimal places.",
            ];
        };
        $moneyMessages = function (string $key): array {
            $message = 'The minimum remaining income must be in pesos with at most two decimals, ₱0.00 or more.';

            return ["{$key}.required" => $message, "{$key}.numeric" => $message, "{$key}.min" => $message, "{$key}.max" => $message, "{$key}.decimal" => $message];
        };

        return [
            'general.default_branch_id.exists' => 'Choose an active branch.',
            'general.default_branch_id.integer' => 'Choose an active branch.',
            'general.reference_format.regex' => 'The reference format must read like OA-YYYY-NNNNNN: 2 to 5 capital letters, then -YYYY-NNNNNN.',
            'general.draft_expiration_days.between' => 'Unsubmitted drafts are kept from 1 to 365 days.',
            'general.estimated_minutes.between' => 'The time to complete must be from 1 to 240 minutes.',
            'products.*.loan_product_id.exists' => 'Choose loan products that exist.',
            'products.*.loan_product_id.distinct' => 'Each loan product can be listed once.',
            'products.*.required_fields.*.in' => 'Required fields must be fields of the application form.',
            'products.*.required_documents.*.in' => 'Required documents must be one of the document types below.',
            ...$rateMessages('assessment.max_dti', 'maximum debt-to-income ratio'),
            ...$rateMessages('assessment.min_coverage', 'minimum coverage ratio'),
            ...$rateMessages('assessment.income_recognition.*', 'income recognition'),
            ...$rateMessages('assessment.product_overrides.*.max_dti', 'product\'s maximum debt-to-income ratio'),
            ...$rateMessages('assessment.product_overrides.*.min_coverage', 'product\'s minimum coverage ratio'),
            ...$moneyMessages('assessment.min_remaining_income'),
            ...$moneyMessages('assessment.product_overrides.*.min_remaining_income'),
            'assessment.product_overrides.*.loan_product_id.exists' => 'Choose loan products that exist.',
            'assessment.product_overrides.*.loan_product_id.distinct' => 'Each loan product can have one override.',
            'assessment.min_employment_years.between' => 'The minimum years employed must be from 0 to 60.',
            'documents.accepted_file_types.*.in' => 'Online applications take JPEG, PNG and PDF files only (.jpg, .jpeg, .jpe, .jfif, .png or .pdf).',
            'documents.accepted_file_types.min' => 'Accept at least one file type.',
            'documents.max_upload_mb.max' => 'The largest file can be at most '.OriginationFileStore::MAX_MB.' MB.',
            'documents.max_upload_mb.min' => 'The largest file must be at least 1 MB.',
            'documents.retention_days.between' => 'Files of declined or withdrawn applications are kept from 30 to 3,650 days.',
            'documents.types.*.type.regex' => 'A document type\'s key uses lowercase letters, digits and underscores.',
            'documents.types.*.type.distinct' => 'Each document type can be listed once.',
            'documents.types.*.label.required' => 'Give each document type a name.',
            'notifications.incomplete_reminder_after_days.between' => 'The reminder is sent from 1 to 30 days after the last save.',
        ];
    }
}
