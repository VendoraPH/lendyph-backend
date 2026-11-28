<?php

namespace App\Http\Requests\Cic;

use App\Models\CicLoanOverride;
use App\Models\CicProductMapping;
use App\Models\Loan;
use App\Services\Cic\CicAccess;
use App\Services\Cic\Records\CicContractClassifier;
use App\Services\Cic\Settings\CicClassificationCodes;
use App\Services\Cic\Settings\CicLoanOverrideService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /cic/loan-overrides (with `loan_id`) and PUT /cic/loan-overrides/{id}:
 * a reportable loan's own record type, contract type and transaction type (a
 * null keeps the product's), with a reason. Each code is checked against the
 * record type the loan will be reported as, by CicContractClassifier: the
 * override's own, else the loan's structure (one lump sum is CN, else CI). A
 * product mapped to the other record type gives no codes, so the override
 * must then carry its own contract and transaction types.
 */
class CicLoanOverrideRequest extends CicFormRequest
{
    protected function permission(): string
    {
        return CicAccess::PREPARE;
    }

    public function rules(): array
    {
        return [
            'loan_id' => $this->isCreate() ? ['required', 'integer', Rule::exists('loans', 'id')] : ['prohibited'],
            'record_type' => ['nullable', 'string', Rule::in(CicClassificationCodes::RECORD_TYPES)],
            'contract_type' => ['nullable', 'string', 'max:3'],
            'transaction_type' => ['nullable', 'string', 'max:10'],
            'reason' => self::reasonRules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $loan = $this->loan();

            if ($loan === null) {
                return;
            }

            if (! in_array($loan->status, CicLoanOverrideService::REPORTABLE_STATUSES, true)) {
                $validator->errors()->add('loan_id', "Only loans that were released are reported to CIC; loan {$loan->displayNumber()} is ".self::statusWords($loan->status).'.');

                return;
            }

            if (! $this->filled('record_type') && ! $this->filled('contract_type') && ! $this->filled('transaction_type')) {
                $validator->errors()->add('record_type', 'Set at least one of the record type, contract type or transaction type to override.');

                return;
            }

            $mapping = CicProductMapping::query()->where('loan_product_id', $loan->loan_product_id)->first();
            $class = CicContractClassifier::classify(CicContractClassifier::structure($loan), $this->only(['record_type', 'contract_type', 'transaction_type']), $mapping);
            $recordType = $class->recordType;

            foreach (['contract_type', 'transaction_type'] as $code) {
                $refusal = CicClassificationCodes::refusal($recordType, $code, $this->input($code));

                if ($refusal !== null) {
                    $validator->errors()->add($code, $refusal);
                }
            }

            // A product mapped to the other record type gives no codes, so the
            // override supplies its own.
            if ($class->mappingMismatch) {
                foreach (['contract_type', 'transaction_type'] as $code) {
                    if (! $this->filled($code)) {
                        $validator->errors()->add($code, 'Choose a '.CicClassificationCodes::fieldWords($code)." for {$recordType}: the loan reports as a {$recordType} contract, and its product is mapped to {$mapping->record_type} contracts, so the product's codes don't apply.");
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'loan_id.required' => 'Choose the loan to override.',
            'loan_id.exists' => 'That loan doesn\'t exist.',
            'loan_id.prohibited' => 'An override stays on its loan; remove it and add one on the other loan instead.',
            'record_type.in' => 'The record type is CI (instalment) or CN (non-instalment).',
            'reason.required' => 'Give a reason for the override.',
        ];
    }

    public function attributes(): array
    {
        return [
            'loan_id' => 'loan',
            'record_type' => 'record type',
            'contract_type' => 'contract type',
            'transaction_type' => 'transaction type',
            'reason' => 'reason',
        ];
    }

    private function isCreate(): bool
    {
        return $this->route('override') === null;
    }

    private function loan(): ?Loan
    {
        if ($this->isCreate()) {
            return Loan::query()->find((int) $this->input('loan_id'));
        }

        /** @var CicLoanOverride $override */
        $override = $this->route('override');

        return $override->loan;
    }

    private static function statusWords(string $status): string
    {
        return match ($status) {
            'draft' => 'still a draft',
            'for_review' => 'still for approval',
            'approved' => 'approved but not released yet',
            'rejected' => 'rejected',
            'void', 'voided', 'cancelled' => 'voided',
            default => str_replace('_', ' ', $status),
        };
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->safe()->only(['loan_id', 'record_type', 'contract_type', 'transaction_type', 'reason']);
    }
}
