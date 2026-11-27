<?php

namespace Database\Factories;

use App\Models\CdaDocument;
use App\Models\CdaDocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CdaDocumentVersion>
 */
class CdaDocumentVersionFactory extends Factory
{
    protected $model = CdaDocumentVersion::class;

    public function definition(): array
    {
        return [
            'cda_document_id' => CdaDocument::factory(),
            'version' => 1,
            'origin' => 'uploaded',
            'is_draft' => false,
        ];
    }
}
