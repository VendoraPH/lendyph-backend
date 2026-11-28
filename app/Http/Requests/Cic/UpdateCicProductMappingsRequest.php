<?php

namespace App\Http\Requests\Cic;

use App\Services\Cic\CicAccess;
use App\Services\Cic\Settings\CicClassificationCodes;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /cic/product-mappings: `mappings`, a list of {loan_product_id,
 * record_type CI|CN, contract_type, transaction_type, purpose_code?}, each
 * code checked against the list its record's field uses.
 */
class UpdateCicProductMappingsRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::SETTINGS;
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1', 'max:500'],
            'mappings.*' => ['required', 'array'],
            'mappings.*.loan_product_id' => ['required', 'integer', 'distinct', Rule::exists('loan_products', 'id')],
            'mappings.*.record_type' => ['required', 'string', Rule::in(CicClassificationCodes::RECORD_TYPES)],
            'mappings.*.contract_type' => ['required', 'string', 'max:3'],
            'mappings.*.transaction_type' => ['required', 'string', 'max:10'],
            'mappings.*.purpose_code' => ['nullable', 'string', 'max:3'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ((array) $this->input('mappings') as $index => $entry) {
                foreach (['contract_type', 'transaction_type', 'purpose_code'] as $code) {
                    $refusal = CicClassificationCodes::refusal((string) $entry['record_type'], $code, $entry[$code] ?? null);

                    if ($refusal !== null) {
                        $validator->errors()->add("mappings.{$index}.{$code}", $refusal);
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'mappings.required' => 'List at least one loan product to map.',
            'mappings.*.loan_product_id.exists' => 'One of the loan products no longer exists. Reload the page.',
            'mappings.*.loan_product_id.distinct' => 'A loan product is listed twice.',
            'mappings.*.record_type.in' => 'The record type is CI (instalment) or CN (non-instalment).',
        ];
    }

    public function attributes(): array
    {
        return [
            'mappings.*.loan_product_id' => 'loan product',
            'mappings.*.record_type' => 'record type',
            'mappings.*.contract_type' => 'contract type',
            'mappings.*.transaction_type' => 'transaction type',
            'mappings.*.purpose_code' => 'purpose of credit',
        ];
    }
}
