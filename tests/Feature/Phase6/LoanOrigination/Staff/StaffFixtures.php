<?php

use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanOrigination\DraftRules;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Fixtures shared by the LO-B3 staff processing tests: a staff user with a
 * role of given permissions in a branch, an application already at a status
 * (set straight in the table, as the LO-B1 tests do), and an uploaded
 * document with its file on the private disk.
 */

if (! function_exists('lo3Staff')) {
    function lo3Staff(?Branch $branch, string ...$permissions): User
    {
        $role = Role::query()->create(['name' => 'spec-lo3:'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['branch_id' => $branch?->id, 'status' => 'active']);

        if ($branch !== null) {
            $user->branches()->sync([$branch->id]);
        }

        return tap($user, fn (User $u) => $u->assignRole($role));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $draft  merged into an empty draft
     */
    function lo3Application(string $status = 'submitted', array $attributes = [], array $draft = []): OriginationApplication
    {
        $empty = app(DraftRules::class)->empty();
        // A random mobile by default: one mobile holds at most one open draft.
        $mobile = $attributes['mobile'] ?? '0917'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);

        $application = OriginationApplication::query()->create([
            'reference_number' => 'OA-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'branch_id' => Branch::query()->orderBy('id')->value('id'),
            'applicant_name' => 'Juana Dela Cruz',
            'mobile' => $mobile,
            'mobile_hash' => OriginationApplication::mobileHash($mobile),
            'email' => 'juana@example.test',
            'requested_amount' => '25000.00',
            'requested_term' => 6,
            'started_at' => now()->subDays(3),
            'last_saved_at' => now()->subDays(2),
            'submitted_at' => in_array($status, ['draft', 'incomplete', 'expired'], true) ? null : now()->subDays(2),
            ...array_diff_key($attributes, ['mobile' => true]),
            'draft' => array_replace_recursive($empty, [
                'personal' => ['first_name' => 'Juana', 'last_name' => 'Dela Cruz', 'street_address' => '12 Rizal Street', 'barangay' => 'San Isidro', 'city' => 'Cebu City'],
            ], $draft),
        ]);

        if ($status !== 'draft') {
            DB::table('origination_applications')->where('id', $application->id)->update(['status' => $status]);
        }

        return $application->fresh();
    }

    function lo3Document(OriginationApplication $application, string $type = 'government_id', string $status = 'uploaded', string $bytes = "%PDF-1.4\n% test\n"): OriginationDocument
    {
        $document = OriginationDocument::query()->create([
            'application_id' => $application->id,
            'public_id' => Str::lower((string) Str::ulid()),
            'type' => $type,
            'status' => $status,
        ]);

        $path = OriginationApplication::filesFolder($application->id).'/'.Str::lower((string) Str::ulid()).'.pdf';
        Storage::disk(OriginationFileStore::DISK)->put($path, $bytes);

        $version = OriginationDocumentVersion::query()->create([
            'document_id' => $document->id,
            'version' => 1,
            'public_id' => Str::lower((string) Str::ulid()),
            'disk' => OriginationFileStore::DISK,
            'path' => $path,
            'file_name' => $type.'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'scan_status' => OriginationDocumentVersion::SCAN_NOT_SCANNED,
            'uploaded_at' => now(),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->fresh();
    }

    function lo3Product(): LoanProduct
    {
        return LoanProduct::factory()->create();
    }
}
