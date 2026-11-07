<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\CoMaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Audit finding 15: the fields the member forms require are enforced on the
 * server too, so an API caller can't skip them. Walk-ins and the CSV importer
 * keep their own, documented rules.
 */
class MemberRequiredFieldsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'branch_id' => $this->branch->id,
            'birthdate' => '1985-06-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'address' => '12 Mabini St',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
        ], $overrides);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'contact number' => ['contact_number'],
            'birthdate' => ['birthdate'],
            'gender' => ['gender'],
            'civil status' => ['civil_status'],
            'street address' => ['address'],
            'city' => ['city'],
            'province' => ['province'],
        ];
    }

    #[DataProvider('requiredFields')]
    public function test_an_operator_cannot_create_a_member_without_it(string $field): void
    {
        $payload = $this->memberPayload();
        unset($payload[$field]);

        $this->postJson('/api/borrowers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->postJson('/api/borrowers', $this->memberPayload([$field => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    #[DataProvider('requiredFields')]
    public function test_a_public_registration_cannot_skip_it(string $field): void
    {
        auth()->forgetGuards();

        $payload = $this->memberPayload(['status' => 'pending']);
        unset($payload[$field]);

        $this->postJson('/api/borrowers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    #[DataProvider('requiredFields')]
    public function test_an_update_may_omit_it_but_cannot_clear_it(string $field): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $stored = $member->fresh()->getRawOriginal($field);

        $this->putJson("/api/borrowers/{$member->id}", ['employer_or_business' => 'Sari-sari Store'])
            ->assertOk();

        $this->putJson("/api/borrowers/{$member->id}", [$field => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame($stored, $member->fresh()->getRawOriginal($field));
    }

    public function test_a_complete_member_is_created_with_either_address_column(): void
    {
        $this->postJson('/api/borrowers', $this->memberPayload())->assertCreated();

        $payload = $this->memberPayload(['first_name' => 'Teodora', 'last_name' => 'Villanueva', 'contact_number' => '09181234567', 'street_address' => '9 Rizal Ave']);
        unset($payload['address']);

        $this->postJson('/api/borrowers', $payload)->assertCreated();
    }

    public function test_an_update_may_clear_one_address_column_while_the_other_stays(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->putJson("/api/borrowers/{$member->id}", ['address' => null, 'street_address' => '9 Rizal Ave'])
            ->assertOk();

        $this->putJson("/api/borrowers/{$member->id}", ['address' => null, 'street_address' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address', 'street_address']);
    }

    public function test_a_members_valid_id_needs_its_number(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->post("/api/borrowers/{$member->id}/valid-ids", [
            'type' => 'philippine_id',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_number']);

        $this->post("/api/borrowers/{$member->id}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => '   ',
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_number']);

        $this->assertSame(0, $member->documents()->count());

        $this->post("/api/borrowers/{$member->id}/valid-ids", [
            'type' => 'philippine_id',
            'id_number' => '1234-5678-9012',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_the_generic_document_upload_is_no_way_around_the_number(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->post("/api/borrowers/{$member->id}/documents", [
            'type' => 'valid_id',
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_number']);

        $this->post("/api/borrowers/{$member->id}/documents", [
            'type' => 'valid_id',
            'id_number' => '1234-5678-9012',
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseHas('documents', [
            'documentable_id' => $member->id,
            'type' => 'valid_id',
            'id_number' => '1234-5678-9012',
        ]);

        $this->post("/api/borrowers/{$member->id}/documents", [
            'type' => 'proof_of_income',
            'file' => UploadedFile::fake()->image('payslip.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    /**
     * The co-maker forms have the ID number optional, so this stays as it was
     * until they ask for it.
     */
    public function test_a_co_makers_valid_id_keeps_its_optional_number(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $coMaker = CoMaker::factory()->create(['borrower_id' => $member->id]);

        $this->post("/api/co-makers/{$coMaker->id}/valid-ids", [
            'type' => 'philippine_id',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_a_walk_in_can_still_be_saved_without_an_id(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Walk In',
            'mobile_number' => '09171112222',
        ])->assertCreated();
    }
}
