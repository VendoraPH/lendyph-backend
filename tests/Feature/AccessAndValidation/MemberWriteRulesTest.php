<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Three write rules on members and their documents: valid IDs have their own
 * upload, a street address reaches `address`, and a status change goes through
 * its own route.
 */
class MemberWriteRulesTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Storage::fake('private');
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    // ── L1: the generic document upload ──

    /**
     * @return array<string, array{string}>
     */
    public static function validIdSpellings(): array
    {
        return [
            'as stored' => ['valid_id'],
            'capitals' => ['VALID_ID'],
            'hyphen' => ['Valid-ID'],
            'space' => ['valid id'],
            'run together' => ['ValidID'],
        ];
    }

    #[DataProvider('validIdSpellings')]
    public function test_the_generic_upload_refuses_a_valid_id_however_it_is_spelled(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, Document::where('documentable_id', $this->member->id)->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeTypes(): array
    {
        return [
            'path traversal' => ['../../etc'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'dot' => ['a.b'],
            'markup' => ['<b>x</b>'],
        ];
    }

    #[DataProvider('unsafeTypes')]
    public function test_the_generic_upload_refuses_unsafe_characters_in_a_type(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('doc.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesTheScreensSend(): array
    {
        return [
            'members tab' => ['PDF'],
            'co-maker dialog' => ['jpg'],
            'loan letter' => ['policy_exception_letter'],
            'other words' => ['proof of income'],
        ];
    }

    #[DataProvider('typesTheScreensSend')]
    public function test_the_generic_upload_keeps_the_types_the_screens_send(string $type): void
    {
        $this->post("/api/borrowers/{$this->member->id}/documents", [
            'type' => $type,
            'file' => UploadedFile::fake()->image('doc.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    // ── L2: a street address reaches `address` ──

    public function test_creating_with_only_a_street_address_fills_address(): void
    {
        $id = $this->postJson('/api/borrowers', [
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'branch_id' => $this->branch->id,
            'birthdate' => '1985-06-15',
            'gender' => 'female',
            'civil_status' => 'single',
            'contact_number' => '09171234567',
            'street_address' => '9 Rizal Ave',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
        ])->assertCreated()->json('data.id');

        $this->assertSame('9 Rizal Ave', Borrower::find($id)->address);
    }

    public function test_an_update_fills_a_blank_address_but_never_overwrites_one(): void
    {
        $this->member->update(['address' => '12 Mabini St']);

        $this->putJson("/api/borrowers/{$this->member->id}", ['street_address' => '9 Rizal Ave'])->assertOk();
        $this->assertSame('12 Mabini St', $this->member->fresh()->address);

        $this->putJson("/api/borrowers/{$this->member->id}", ['address' => null, 'street_address' => '9 Rizal Ave'])->assertOk();
        $this->assertSame('9 Rizal Ave', $this->member->fresh()->address);

        Borrower::whereKey($this->member->id)->update(['address' => null]);
        $this->putJson("/api/borrowers/{$this->member->id}", ['street_address' => '44 Luna St'])->assertOk();
        $this->assertSame('44 Luna St', $this->member->fresh()->address);
    }

    // ── L7: status has its own routes ──

    public function test_an_update_cannot_activate_a_pending_registration(): void
    {
        $pending = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);

        $this->putJson("/api/borrowers/{$pending->id}", ['status' => 'active'])->assertOk();

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->approved_by);
        $this->assertNull($pending->approved_at);
    }

    public function test_an_update_cannot_change_a_members_status_and_the_routes_still_can(): void
    {
        $this->putJson("/api/borrowers/{$this->member->id}", ['status' => 'blacklisted'])->assertOk();
        $this->assertSame('active', $this->member->fresh()->status);

        $this->patchJson("/api/borrowers/{$this->member->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $this->member->fresh()->status);

        $this->patchJson("/api/borrowers/{$this->member->id}/reactivate")->assertOk();
        $this->assertSame('active', $this->member->fresh()->status);
    }
}
