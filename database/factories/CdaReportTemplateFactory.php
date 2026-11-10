<?php

namespace Database\Factories;

use App\Models\CdaReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaReportTemplate>
 */
class CdaReportTemplateFactory extends Factory
{
    protected $model = CdaReportTemplate::class;

    public function definition(): array
    {
        return [
            'report_type' => 'capr',
            'version' => '2099.'.$this->faker->unique()->numberBetween(1, 999_999),
            'name' => 'CAPR (test)',
            'effective_from' => '2099-01-01',
            'effective_until' => null,
            'cooperative_types' => [],
            'asset_classes' => [],
            'definition' => [
                'schema_version' => 1,
                'meta' => ['verified' => false],
                'parts' => ['annual'],
                'sections' => [
                    ['key' => 'general', 'title' => 'General', 'kind' => 'fields', 'fields' => [
                        ['key' => 'cooperative_name', 'label' => 'Name', 'type' => 'text', 'source' => ['key' => 'coop.name']],
                    ]],
                ],
            ],
            'status' => CdaReportTemplate::STATUS_DRAFT,
        ];
    }
}
