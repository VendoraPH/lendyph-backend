<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaSocialProgram;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\DocumentCategories;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Gap round B4: the "CDA Correspondence" document category, the financial
 * statements' supporting-document categories (restricted like the audited
 * statements), and the Member Education and Financial Literacy social
 * program categories.
 */
class CdaGapRoundCategoriesTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        Storage::fake('private');
        $this->period();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cda_correspondence_is_an_open_upload_category(): void
    {
        $this->assertSame('CDA Correspondence', DocumentCategories::label('cda_correspondence'));
        $this->assertContains('cda_correspondence', DocumentCategories::uploadable());
        $this->assertNull(DocumentCategories::restriction('cda_correspondence'));

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::UPLOAD]))
            ->post('/api/cda/periods/2026/documents', [
                'title' => 'CDA letter on the 2025 CAPR',
                'category' => 'cda_correspondence',
                'file' => UploadedFile::fake()->create('letter.pdf', 12, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.category_label', 'CDA Correspondence');
    }

    public function test_the_financial_statements_supporting_documents_need_the_audit_view(): void
    {
        foreach (['management_representation' => 'Management representation letter', 'management_responsibility' => 'Statement of management responsibility', 'fs_notes' => 'Notes to the financial statements'] as $key => $label) {
            $this->assertSame($label, DocumentCategories::label($key));
            $this->assertContains($key, DocumentCategories::uploadable());
            $this->assertSame(CdaAccess::VIEW_AUDIT, DocumentCategories::restriction($key), $key);
        }
    }

    public function test_member_education_and_financial_literacy_are_program_categories(): void
    {
        $this->assertContains('member_education', CdaSocialProgram::CATEGORIES);
        $this->assertContains('financial_literacy', CdaSocialProgram::CATEGORIES);

        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_SOCIAL]));

        foreach (['member_education' => 'Pre-membership seminar', 'financial_literacy' => 'Budgeting class'] as $category => $name) {
            $this->postJson('/api/cda/periods/2026/social-programs', ['name' => $name, 'category' => $category])->assertCreated();
        }
    }
}
