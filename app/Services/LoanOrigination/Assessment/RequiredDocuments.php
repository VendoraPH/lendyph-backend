<?php

namespace App\Services\LoanOrigination\Assessment;

use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationProductSetting;
use App\Models\OriginationSetting;

/**
 * The documents an application needs before its hand-off (origination
 * decision 11): every settings document type marked required, plus the ones
 * its product's online settings add. A document counts once staff verified
 * it.
 */
final class RequiredDocuments
{
    /**
     * Each required type with its label and whether it is verified.
     *
     * @return list<array{type: string, label: string, verified: bool}>
     */
    public function for(OriginationApplication $application): array
    {
        $settings = OriginationSetting::current();
        $labels = [];
        $required = [];

        foreach ($settings->document_types ?? [] as $type) {
            $labels[(string) $type['type']] = (string) $type['label'];

            if ((bool) ($type['required'] ?? false)) {
                $required[] = (string) $type['type'];
            }
        }

        $product = $application->loan_product_id === null ? null : OriginationProductSetting::query()->where('loan_product_id', $application->loan_product_id)->first();

        foreach ($product?->required_documents ?? [] as $type) {
            $required[] = (string) $type;
        }

        $verified = OriginationDocument::query()->where('application_id', $application->getKey())->where('status', 'verified')->pluck('type')->all();

        return array_map(fn (string $type) => [
            'type' => $type,
            'label' => $labels[$type] ?? ucfirst(str_replace('_', ' ', $type)),
            'verified' => in_array($type, $verified, true),
        ], array_values(array_unique($required)));
    }

    /** "All 2 required documents verified", "1 of 2 required documents verified". */
    public function status(OriginationApplication $application): string
    {
        $required = $this->for($application);
        $verified = count(array_filter($required, fn (array $d) => $d['verified']));
        $total = count($required);

        return match (true) {
            $total === 0 => 'No documents are required',
            $verified === $total => "All {$total} required documents verified",
            default => "{$verified} of {$total} required documents verified",
        };
    }

    /**
     * The labels of the required documents not verified yet.
     *
     * @return list<string>
     */
    public function unverifiedLabels(OriginationApplication $application): array
    {
        return array_values(array_map(fn (array $d) => $d['label'], array_filter($this->for($application), fn (array $d) => ! $d['verified'])));
    }
}
