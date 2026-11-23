<?php

namespace Tests\Feature\Phase6\LoanOrigination;

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanOrigination\DraftRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fixtures for LO-B4 (assessment and hand-off): a product with a real
 * schedule, an application at a status with a full draft, staff with chosen
 * permissions, and verified documents on the private disk.
 */
trait OriginationFixtures
{
    public function pinOriginationToday(string $date = '2026-11-19 09:00:00'): void
    {
        $this->travelTo(Carbon::parse($date, 'Asia/Manila'));
    }

    /** @param  array<string, mixed>  $attributes */
    public function originationProduct(array $attributes = []): LoanProduct
    {
        return LoanProduct::factory()->create(array_merge([
            'interest_rate' => 2.0,
            'min_interest_rate' => null,
            'interest_method' => 'straight',
            'term' => 6,
            'min_term' => 1,
            'max_term' => 12,
            'frequency' => 'monthly',
            'processing_fee' => 2.0,
            'service_fee' => 1.0,
            'min_amount' => 5000,
            'max_amount' => 500000,
        ], $attributes));
    }

    /**
     * A full, valid draft for $product.
     *
     * @param  array<string, array<string, mixed>>  $overrides  section => fields
     * @return array<string, array<string, mixed>>
     */
    public function originationDraft(LoanProduct $product, array $overrides = []): array
    {
        $draft = [
            'personal' => [
                'first_name' => 'Maria', 'middle_name' => 'Lopez', 'last_name' => 'Santos'.Str::upper(Str::random(4)),
                'date_of_birth' => '1990-05-10', 'gender' => 'female', 'civil_status' => 'single',
                'province' => 'Agusan del Norte', 'city' => 'Butuan', 'barangay' => 'Poblacion', 'street_address' => '12 Rizal St.',
                'years_at_residence' => '4',
            ],
            'employment' => [
                'income_source' => 'employed', 'employer_name' => 'Butuan Trading', 'job_title' => 'Clerk',
                'years_employed' => '5', 'gross_monthly_income' => '40000', 'salary_frequency' => 'monthly',
            ],
            'expenses' => [
                'housing' => '8000', 'food' => '6000', 'credit_card_payments' => '1500', 'has_existing_loans' => false,
            ],
            'loan' => [
                'loan_product_id' => $product->id, 'amount' => '30000', 'purpose' => 'Store stock', 'term' => '6', 'frequency' => 'monthly',
            ],
        ];

        foreach ($overrides as $section => $fields) {
            $draft[$section] = array_merge($draft[$section] ?? [], $fields);
        }

        return app(DraftRules::class)->normalize($draft);
    }

    /**
     * An application at $status with a full draft, set straight in the table.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $draft
     */
    public function originationApplication(string $status = 'under_assessment', ?LoanProduct $product = null, array $attributes = [], array $draft = []): OriginationApplication
    {
        $product ??= $this->originationProduct();
        $normalized = $this->originationDraft($product, $draft);

        $application = OriginationApplication::query()->create(array_merge([
            'reference_number' => 'OA-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'branch_id' => Branch::query()->orderBy('id')->value('id'),
            'loan_product_id' => $product->id,
            'requested_amount' => $normalized['loan']['amount'],
            'requested_term' => (int) $normalized['loan']['term'],
            'applicant_name' => $normalized['personal']['first_name'].' '.$normalized['personal']['last_name'],
            'mobile' => '09171234567',
            'mobile_hash' => OriginationApplication::mobileHash('09171234567'),
            'email' => null,
            'draft' => $normalized,
            'started_at' => now(),
            'last_saved_at' => now(),
            'submitted_at' => now(),
        ], $attributes));

        DB::table('origination_applications')->where('id', $application->id)->update(['status' => $status]);

        return $application->fresh();
    }

    public function originationStaff(string ...$permissions): User
    {
        $role = Role::query()->create(['name' => 'spec-lo4:'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return tap(User::factory()->create(['branch_id' => Branch::query()->orderBy('id')->value('id')]), fn (User $u) => $u->assignRole($role));
    }

    /** The ID details a verified government ID of these fixtures records. */
    public const ORIGINATION_ID = ['type' => 'drivers_license', 'custom_type_name' => null, 'id_number' => 'N01-23-456789'];

    /**
     * Every settings document type, uploaded and set to $status, each with a
     * small file on the private disk. A verified government ID records its ID
     * type and number, as verifying it does.
     */
    public function originationDocuments(OriginationApplication $application, string $status = 'verified'): void
    {
        foreach (['government_id', 'proof_of_income', 'proof_of_address'] as $type) {
            $path = OriginationApplication::filesFolder($application->id)."/{$type}-v1.pdf";
            Storage::disk('private')->put($path, "%PDF-1.4 {$type}");

            $document = OriginationDocument::query()->create([
                'application_id' => $application->id,
                'public_id' => (string) Str::ulid(),
                'type' => $type,
                'status' => $status,
                'id_details' => $type === 'government_id' && $status === 'verified' ? self::ORIGINATION_ID : null,
            ]);

            $version = OriginationDocumentVersion::query()->create([
                'document_id' => $document->id,
                'version' => 1,
                'public_id' => (string) Str::ulid(),
                'disk' => 'private',
                'path' => $path,
                'file_name' => "{$type}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 20,
                'sha256' => hash('sha256', $type.$application->id),
                'scan_status' => 'not_scanned',
                'uploaded_at' => now(),
            ]);

            DB::table('origination_documents')->where('id', $document->id)->update([
                'current_version_id' => $version->id,
                'verified_at' => $status === 'verified' ? now() : null,
            ]);
        }
    }
}
