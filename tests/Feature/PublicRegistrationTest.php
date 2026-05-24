<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\BorrowerSubmissionToken;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    private Branch $branch;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh');
        $this->seed(DatabaseSeeder::class);
        $this->branch = Branch::first();
        $this->admin = User::where('username', 'super_admin')->first();
        Storage::fake('public');
        // Note: NOT calling actingAs() — these specs cover the anonymous flow.
    }

    public function test_branches_public_endpoint_lists_active_branches_without_auth(): void
    {
        // Branch from seeder is active by default.
        $response = $this->getJson('/api/branches/public');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'city'],
                ],
            ])
            ->assertJsonPath('data.0.name', 'Main Branch');

        // Confirm the slim payload — no internal codes, contact, financial,
        // or audit fields leak through.
        $row = $response->json('data.0');
        $this->assertEqualsCanonicalizing(['id', 'name', 'city'], array_keys($row));
    }

    public function test_branches_public_filters_inactive_branches(): void
    {
        Branch::create([
            'name' => 'Closed Branch',
            'code' => 'CLO',
            'address' => 'Old Location',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/branches/public')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('Closed Branch', $names);
    }

    public function test_anonymous_borrower_create_with_pending_status_returns_submission_token(): void
    {
        $response = $this->postJson('/api/borrowers', [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'birthdate' => '1990-05-12',
            'gender' => 'male',
            'civil_status' => 'married',
            'contact_number' => '09171234567',
            'email' => 'public-juan@example.com',
            'address' => '123 Mango St',
            'employer_or_business' => 'Acme Corp',
            'monthly_income' => 25000,
            'pledge_amount' => 0,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'submission_token', 'expires_at'],
            ])
            ->assertJsonPath('success', true);

        $borrowerId = $response->json('data.id');
        $this->assertDatabaseHas('borrowers', [
            'id' => $borrowerId,
            'status' => 'pending',
            'email' => 'public-juan@example.com',
        ]);

        // The Borrower::created hook auto-creates a pledge — the public flow
        // must not bypass this side effect.
        $this->assertDatabaseHas('share_capital_pledges', [
            'borrower_id' => $borrowerId,
            'amount' => 0,
        ]);

        $token = BorrowerSubmissionToken::where('borrower_id', $borrowerId)->first();
        $this->assertNotNull($token);
        $this->assertSame($response->json('data.submission_token'), $token->token);
        $this->assertTrue($token->expires_at->isFuture());
    }

    public function test_anonymous_borrower_create_rejects_non_pending_status(): void
    {
        // Spec allows either 401 or 422 for anonymous attempts with a non-pending
        // status. We return 422 (validation) so the frontend can surface the
        // specific field error.
        $this->postJson('/api/borrowers', [
            'status' => 'active',
            'branch_id' => $this->branch->id,
            'first_name' => 'Mark',
            'last_name' => 'Anon',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_anonymous_borrower_create_rejects_missing_status(): void
    {
        $this->postJson('/api/borrowers', [
            'branch_id' => $this->branch->id,
            'first_name' => 'Mark',
            'last_name' => 'Anon',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_anonymous_borrower_create_persists_submitted_branch_id(): void
    {
        // Regression: 2026-05-24 FE handoff reported that public POST /borrowers
        // dropped branch_id silently. The DB row WAS being written correctly;
        // this test locks in DB persistence so a future change can't regress it.
        $response = $this->postJson('/api/borrowers', [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Branch',
            'last_name' => 'Saved',
            'email' => 'branch-saved@example.com',
            'contact_number' => '09171234588',
        ])->assertCreated();

        $borrowerId = $response->json('data.id');

        $this->assertDatabaseHas('borrowers', [
            'id' => $borrowerId,
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_show_returns_flat_branch_id_and_nested_branch(): void
    {
        // Regression: 2026-05-24 FE handoff. The DB stored branch_id correctly,
        // but BorrowerResource only exposed nested `branch` (no flat field), so
        // FE code reading `data.branch_id` got undefined and reported the bug.
        // This test locks in BOTH shapes so neither read pattern silently breaks.
        $created = $this->postJson('/api/borrowers', [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'AdminReview',
            'last_name' => 'Probe',
            'contact_number' => '09171234500',
            'email' => 'admin-review@example.com',
        ])->assertCreated();

        $borrowerId = $created->json('data.id');

        $this->actingAs($this->admin)
            ->getJson("/api/borrowers/{$borrowerId}")
            ->assertOk()
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch.id', $this->branch->id);
    }

    public function test_anonymous_borrower_create_without_branch_id_succeeds(): void
    {
        // The public form may skip branch selection (empty/hidden public branch
        // list); an admin assigns the branch during review. branch_id is only
        // required for authenticated operator creates.
        $response = $this->postJson('/api/borrowers', [
            'status' => 'pending',
            'first_name' => 'NoBranch',
            'last_name' => 'Applicant',
            'email' => 'nobranch@example.com',
            'contact_number' => '09171234599',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['success', 'data' => ['id', 'submission_token', 'expires_at']]);

        $this->assertDatabaseHas('borrowers', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
            'branch_id' => null,
        ]);
    }

    public function test_authenticated_borrower_create_still_returns_full_resource_without_token(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson('/api/borrowers', [
            'branch_id' => $this->branch->id,
            'first_name' => 'Admin',
            'last_name' => 'Created',
            'contact_number' => '09171234567',
            'email' => 'admin-create@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Admin')
            ->assertJsonMissingPath('data.submission_token');
    }

    public function test_photo_upload_with_valid_submission_token_succeeds(): void
    {
        $create = $this->postJson('/api/borrowers', [
            'status' => 'pending',
            'branch_id' => $this->branch->id,
            'first_name' => 'Photo',
            'last_name' => 'Tester',
            'email' => 'photo-tester@example.com',
            'contact_number' => '09171234567',
        ])->assertCreated();

        $borrowerId = $create->json('data.id');
        $token = $create->json('data.submission_token');

        $this->postJson(
            "/api/borrowers/{$borrowerId}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
            ['X-Submission-Token' => $token],
        )->assertOk()
            ->assertJsonStructure(['message', 'photo_url']);

        $this->assertNotNull(Borrower::find($borrowerId)->photo_path);
    }

    public function test_photo_upload_rejects_token_bound_to_other_borrower(): void
    {
        $a = $this->postJson('/api/borrowers', [
            'status' => 'pending', 'branch_id' => $this->branch->id,
            'first_name' => 'A', 'last_name' => 'One', 'email' => 'a@x.com', 'contact_number' => '09171000001',
        ])->assertCreated();

        $b = $this->postJson('/api/borrowers', [
            'status' => 'pending', 'branch_id' => $this->branch->id,
            'first_name' => 'B', 'last_name' => 'Two', 'email' => 'b@x.com', 'contact_number' => '09171000002',
        ])->assertCreated();

        $bId = $b->json('data.id');
        $aToken = $a->json('data.submission_token');

        $this->postJson(
            "/api/borrowers/{$bId}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
            ['X-Submission-Token' => $aToken],
        )->assertUnauthorized();
    }

    public function test_photo_upload_rejects_expired_submission_token(): void
    {
        $create = $this->postJson('/api/borrowers', [
            'status' => 'pending', 'branch_id' => $this->branch->id,
            'first_name' => 'Exp', 'last_name' => 'Tok', 'email' => 'exp@x.com', 'contact_number' => '09171000003',
        ])->assertCreated();

        $borrowerId = $create->json('data.id');
        $token = $create->json('data.submission_token');

        BorrowerSubmissionToken::where('token', $token)->update(['expires_at' => now()->subMinutes(5)]);

        $this->postJson(
            "/api/borrowers/{$borrowerId}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
            ['X-Submission-Token' => $token],
        )->assertUnauthorized();
    }

    public function test_photo_upload_without_token_or_auth_returns_401(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson(
            "/api/borrowers/{$borrower->id}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
        )->assertUnauthorized();
    }

    public function test_valid_ids_upload_with_valid_submission_token_succeeds(): void
    {
        $create = $this->postJson('/api/borrowers', [
            'status' => 'pending', 'branch_id' => $this->branch->id,
            'first_name' => 'Id', 'last_name' => 'Up', 'email' => 'idup@x.com', 'contact_number' => '09171000004',
        ])->assertCreated();

        $borrowerId = $create->json('data.id');
        $token = $create->json('data.submission_token');

        $this->postJson(
            "/api/borrowers/{$borrowerId}/valid-ids",
            [
                'type' => 'umid',
                'id_number' => 'CRN-001-002-003',
                'front_file' => UploadedFile::fake()->image('front.jpg'),
                'back_file' => UploadedFile::fake()->image('back.jpg'),
            ],
            ['X-Submission-Token' => $token],
        )->assertCreated();

        $this->assertDatabaseHas('documents', [
            'documentable_id' => $borrowerId,
            'type' => 'valid_id',
            'label' => 'umid',
            'id_number' => 'CRN-001-002-003',
        ]);
    }

    public function test_authenticated_photo_upload_still_works_unchanged(): void
    {
        $this->actingAs($this->admin);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson(
            "/api/borrowers/{$borrower->id}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
        )->assertOk();
    }

    public function test_authenticated_valid_ids_upload_still_works_unchanged(): void
    {
        $this->actingAs($this->admin);

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson("/api/borrowers/{$borrower->id}/valid-ids", [
            'type' => 'umid',
            'front_file' => UploadedFile::fake()->image('front.jpg'),
        ])->assertCreated();
    }

    public function test_auth_header_wins_when_both_auth_and_token_are_present(): void
    {
        $create = $this->postJson('/api/borrowers', [
            'status' => 'pending', 'branch_id' => $this->branch->id,
            'first_name' => 'Both', 'last_name' => 'Auth', 'email' => 'both@x.com', 'contact_number' => '09171000005',
        ])->assertCreated();

        $borrowerId = $create->json('data.id');
        // Pass a bogus token along with a real auth — auth should win.
        $token = $this->admin->createToken('test')->plainTextToken;

        $this->postJson(
            "/api/borrowers/{$borrowerId}/photo",
            ['photo' => UploadedFile::fake()->image('me.jpg')],
            [
                'Authorization' => 'Bearer '.$token,
                'X-Submission-Token' => 'stk_invalid_garbage_value_should_be_ignored',
            ],
        )->assertOk();
    }
}
