<?php

namespace App\Http\Requests\Cic;

use App\Models\CicCodeMapping;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicDomains;
use App\Services\Cic\Settings\CicCodeMappingService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT /cic/code-mappings: `mappings`, a list of {domain, lendy_value,
 * cic_domain?, cic_code}. A collateral type maps to a GuaranteesDomain code;
 * an ID kind names its code list (IDTypeDomain or IdentificationTypeDomain). A
 * null code removes the mapping. Only values Lendy holds can be mapped.
 */
class UpdateCicCodeMappingsRequest extends CicFormRequest
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
            'mappings.*.domain' => ['required', 'string', Rule::in(CicCodeMapping::DOMAINS)],
            'mappings.*.lendy_value' => ['required', 'string', 'max:120'],
            'mappings.*.cic_domain' => ['nullable', 'string', 'max:40'],
            'mappings.*.cic_code' => ['present', 'nullable', 'string', 'max:10'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = app(CicCodeMappingService::class);
            $values = [];
            $seen = [];

            foreach ((array) $this->input('mappings') as $index => $entry) {
                $domain = (string) $entry['domain'];
                $value = (string) $entry['lendy_value'];
                $values[$domain] ??= $service->values($domain);
                $key = $domain."\0".$value;

                if (isset($seen[$key])) {
                    $validator->errors()->add("mappings.{$index}.lendy_value", 'A value is listed twice.');

                    continue;
                }

                $seen[$key] = true;

                if (! array_key_exists($value, $values[$domain])) {
                    $validator->errors()->add("mappings.{$index}.lendy_value", $domain === CicCodeMapping::DOMAIN_COLLATERAL_TYPE
                        ? 'That collateral type doesn\'t exist. Reload the page.'
                        : 'That ID kind isn\'t one Lendy stores. Reload the page.');

                    continue;
                }

                $code = $entry['cic_code'] ?? null;

                if ($code === null || $code === '') {
                    continue;
                }

                $codeList = $entry['cic_domain'] ?? null;

                if ($domain === CicCodeMapping::DOMAIN_COLLATERAL_TYPE && $codeList !== null && $codeList !== CicCodeMappingService::GUARANTEES) {
                    $validator->errors()->add("mappings.{$index}.cic_domain", 'A collateral type maps to one of CIC\'s guarantee codes.');

                    continue;
                }

                if ($domain === CicCodeMapping::DOMAIN_ID_KIND && ! in_array($codeList, CicCodeMappingService::CODE_LISTS[$domain], true)) {
                    $validator->errors()->add("mappings.{$index}.cic_domain", 'Choose whether this ID kind maps to CIC\'s ID types or its identification types.');

                    continue;
                }

                $list = (string) CicCodeMappingService::codeListFor($domain, $codeList);

                if (! CicDomains::contains($list, (string) $code)) {
                    $validator->errors()->add("mappings.{$index}.cic_code", "\"{$code}\" isn't one of CIC's ".match ($list) {
                        CicCodeMappingService::GUARANTEES => 'guarantee codes',
                        CicCodeMappingService::ID_TYPES => 'ID type codes',
                        default => 'identification type codes',
                    }.'.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'mappings.required' => 'List at least one value to map.',
            'mappings.*.domain.in' => 'A mapping is for a collateral type or an ID kind.',
            'mappings.*.cic_code.present' => 'Send each value\'s CIC code, or none to remove its mapping.',
        ];
    }

    public function attributes(): array
    {
        return [
            'mappings.*.domain' => 'mapping kind',
            'mappings.*.lendy_value' => 'Lendy value',
            'mappings.*.cic_domain' => 'CIC code list',
            'mappings.*.cic_code' => 'CIC code',
        ];
    }
}
