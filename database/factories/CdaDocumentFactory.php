<?php

namespace Database\Factories;

use App\Models\CdaDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaDocument>
 */
class CdaDocumentFactory extends Factory
{
    protected $model = CdaDocument::class;

    public function definition(): array
    {
        return [
            'category' => 'other',
            'title' => $this->faker->sentence(3),
            'status' => 'active',
        ];
    }
}
