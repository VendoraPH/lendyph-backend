<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Finish run round 2, decision 3: a public registration is never refused as a
 * duplicate. Refusing one on the anonymous form let anyone confirm that a
 * person is on file and probe their birthdate (#356 security review). It is
 * saved pending instead, with a server-set link to the record it may
 * duplicate, which only a reviewer who can see that record is shown, and the
 * public answer is the same either way. Staff duplicate checks are unchanged.
 */
class PublicRegistrationPossibleDuplicateTest extends TestCase
{
    private const HIDDEN_NOTICE = 'This registration may be a duplicate of someone already on file outside your branches. Check with head office before approving.';

    private const HIDDEN_REFUSAL = 'This registration may be a duplicate of someone already on file outside your branches. Check with head office, then confirm to approve it.';

    private Branch $branch;

    private Branch $far;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::first();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->admin = User::where('username', 'super_admin')->first();
        Storage::fake('private');
    }

    // ── The public answer ────────────────────────────────────────────────

    public function test_the_public_answer_is_the_same_with_or_without_a_match(): void
    {
        $this->member();
        $this->register(['first_name' => 'Warm', 'last_name' => 'Up'])->assertCreated();

        [$none, $noneQueries] = $this->registerCountingQueries(['first_name' => 'Pedro', 'last_name' => 'Penduko']);
        [$same, $sameQueries] = $this->registerCountingQueries();
        [$similar, $similarQueries] = $this->registerCountingQueries(['last_name' => 'Crus']);

        $this->assertSame(201, $none->status());

        foreach ([$same, $similar] as $matched) {
            $this->assertSame($none->status(), $matched->status());
            $this->assertSame($this->shape($none), $this->shape($matched));
        }

        // The same work either way, so the answer can't be told apart by its
        // timing either.
        $this->assertSame($noneQueries, $sameQueries);
        $this->assertSame($noneQueries, $similarQueries);
    }

    public function test_the_public_answer_never_names_the_match(): void
    {
        $member = $this->member(['first_name' => 'Juana']);

        $body = $this->register(['last_name' => 'Crus'])->assertCreated()->getContent();

        $this->assertStringNotContainsString($member->borrower_code, $body);
        $this->assertStringNotContainsString('Juana', $body);
        $this->assertStringNotContainsString('1990-05-12', $body);
    }

    // ── The link ─────────────────────────────────────────────────────────

    public function test_a_registration_with_the_same_name_is_saved_pending_and_linked(): void
    {
        $member = $this->member();

        $saved = $this->savedFrom($this->register());

        $this->assertSame('pending', $saved->status);
        $this->assertSame($member->id, $saved->possible_duplicate_of_id);
        $this->assertSame('same_name', $saved->possible_duplicate_reason);
    }

    public function test_a_similar_name_with_the_same_birthdate_is_linked_with_its_own_reason(): void
    {
        $member = $this->member();

        $saved = $this->savedFrom($this->register(['last_name' => 'Crus']));

        $this->assertSame($member->id, $saved->possible_duplicate_of_id);
        $this->assertSame('similar_name_same_birthdate', $saved->possible_duplicate_reason);
    }

    public function test_a_registration_that_matches_no_one_has_no_link(): void
    {
        $this->member();

        $saved = $this->savedFrom($this->register(['first_name' => 'Pedro', 'last_name' => 'Penduko']));

        $this->assertNull($saved->possible_duplicate_of_id);
        $this->assertNull($saved->possible_duplicate_reason);
    }

    public function test_the_applicant_cannot_set_the_link(): void
    {
        $member = $this->member();

        $saved = $this->savedFrom($this->register([
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'possible_duplicate_of_id' => $member->id,
            'possible_duplicate_reason' => 'same_name',
        ]));

        $this->assertNull($saved->possible_duplicate_of_id);
        $this->assertNull($saved->possible_duplicate_reason);
    }

    // ── Staff paths ──────────────────────────────────────────────────────

    public function test_staff_creates_are_still_refused_and_create_anyway_links_nothing(): void
    {
        $this->member();
        $before = Borrower::count();

        foreach (['active', 'pending'] as $status) {
            $this->actingAs($this->admin)->postJson('/api/borrowers', $this->applicant(['status' => $status]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('first_name');
        }

        $this->assertSame($before, Borrower::count());

        $created = $this->actingAs($this->admin)
            ->postJson('/api/borrowers', $this->applicant(['status' => 'active', 'force' => true]))
            ->assertCreated();

        $this->assertNull(Borrower::findOrFail($created->json('data.id'))->possible_duplicate_of_id);
    }

    public function test_a_staff_edit_into_a_duplicate_is_still_refused(): void
    {
        $this->member();
        $other = $this->member(['first_name' => 'Pedro', 'middle_name' => null, 'last_name' => 'Penduko', 'birthdate' => '1985-01-01']);

        $this->actingAs($this->admin)
            ->putJson("/api/borrowers/{$other->id}", ['first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');

        $this->assertSame('Pedro', $other->fresh()->first_name);
    }

    // ── The review API ───────────────────────────────────────────────────

    public function test_a_reviewer_who_can_see_the_member_is_shown_the_link_in_words(): void
    {
        $member = $this->member();
        $registration = $this->savedFrom($this->register());
        $expected = [
            'visible' => true,
            'id' => $member->id,
            'borrower_code' => $member->borrower_code,
            'full_name' => 'Juan Dela Cruz',
            'is_member' => true,
            'reason' => 'Same full name',
            'message' => "Possible duplicate of {$member->borrower_code} (Juan Dela Cruz): same full name.",
        ];

        $this->assertSame($expected, $this->actingAs($this->admin)->getJson("/api/borrowers/{$registration->id}")->assertOk()->json('data.possible_duplicate'));
        $this->assertSame($expected, $this->actingAs($this->reviewer($this->branch))->getJson("/api/borrowers/{$registration->id}")->assertOk()->json('data.possible_duplicate'));
    }

    public function test_a_link_to_another_registration_says_it_is_not_a_member(): void
    {
        $first = $this->savedFrom($this->register());
        $second = $this->savedFrom($this->register());

        $link = $this->actingAs($this->admin)->getJson("/api/borrowers/{$second->id}")->assertOk()->json('data.possible_duplicate');

        $this->assertSame($first->id, $link['id']);
        $this->assertFalse($link['is_member']);
    }

    public function test_a_reviewer_who_cannot_see_the_member_learns_only_that_one_may_exist(): void
    {
        $member = $this->member(['branch_id' => $this->far->id]);
        $registration = $this->savedFrom($this->register());

        $response = $this->actingAs($this->reviewer($this->branch))->getJson("/api/borrowers/{$registration->id}")->assertOk();

        $this->assertSame(['visible' => false, 'message' => self::HIDDEN_NOTICE], $response->json('data.possible_duplicate'));
        $this->assertStringNotContainsString($member->borrower_code, $response->getContent());
    }

    public function test_a_registration_with_no_link_reads_null_and_the_list_carries_no_link(): void
    {
        $plain = $this->savedFrom($this->register(['first_name' => 'Pedro', 'last_name' => 'Penduko']));
        $this->actingAs($this->admin);

        $data = $this->getJson("/api/borrowers/{$plain->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('possible_duplicate', $data);
        $this->assertNull($data['possible_duplicate']);

        // The list never loads the link, so it costs nothing per row.
        foreach ($this->getJson('/api/borrowers?status=pending')->assertOk()->json('data') as $row) {
            $this->assertArrayNotHasKey('possible_duplicate', $row);
        }
    }

    // ── Approving ────────────────────────────────────────────────────────

    public function test_approving_a_possible_duplicate_needs_the_reviewers_confirmation(): void
    {
        $member = $this->member();
        $registration = $this->flaggedWithValidId();
        $this->actingAs($this->admin);
        $url = "/api/borrowers/{$registration->id}/approve-registration";

        foreach ([[], ['acknowledge_possible_duplicate' => false]] as $body) {
            $this->patchJson($url, $body)
                ->assertUnprocessable()
                ->assertJsonPath('errors.possible_duplicate', [
                    "This registration may be a duplicate of {$member->borrower_code} (Juan Dela Cruz). Check that this is a different person, then confirm to approve it.",
                ]);
        }

        $this->assertSame('pending', $registration->fresh()->status);
        $this->assertNull($registration->fresh()->approved_by);

        $this->patchJson($url, ['acknowledge_possible_duplicate' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_the_refusal_names_the_member_only_to_a_reviewer_who_may_see_them(): void
    {
        $member = $this->member(['branch_id' => $this->far->id]);
        $registration = $this->flaggedWithValidId();
        $url = "/api/borrowers/{$registration->id}/approve-registration";

        $branchReviewer = $this->actingAs($this->reviewer($this->branch))->patchJson($url)->assertUnprocessable();
        $this->assertSame([self::HIDDEN_REFUSAL], $branchReviewer->json('errors.possible_duplicate'));
        $this->assertStringNotContainsString($member->borrower_code, $branchReviewer->getContent());

        // Approving needs only borrowers:approve; naming the member also needs
        // borrowers:view.
        $approverOnly = $this->actingAs($this->reviewer($this->branch, ['borrowers:approve', 'branches:view_all']))->patchJson($url)->assertUnprocessable();
        $this->assertSame([self::HIDDEN_REFUSAL], $approverOnly->json('errors.possible_duplicate'));

        $this->actingAs($this->reviewer($this->branch))
            ->patchJson($url, ['acknowledge_possible_duplicate' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_the_confirmation_is_asked_only_once_the_other_checks_pass(): void
    {
        $this->member();
        $registration = $this->savedFrom($this->register());

        $this->actingAs($this->admin)
            ->patchJson("/api/borrowers/{$registration->id}/approve-registration")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_id')
            ->assertJsonMissingValidationErrors('possible_duplicate');
    }

    public function test_the_confirmation_must_be_true_or_false(): void
    {
        $this->member();
        $registration = $this->flaggedWithValidId();

        $this->actingAs($this->admin)
            ->patchJson("/api/borrowers/{$registration->id}/approve-registration", ['acknowledge_possible_duplicate' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acknowledge_possible_duplicate');

        $this->assertSame('pending', $registration->fresh()->status);
    }

    // ── The audit trail ──────────────────────────────────────────────────

    public function test_the_audit_rows_name_the_link_by_member_code_only(): void
    {
        // A similar name, so the member's own name differs from the applicant's.
        $member = $this->member(['first_name' => 'Juana']);
        $registration = $this->flaggedWithValidId(['last_name' => 'Crus']);

        $this->actingAs($this->admin)
            ->patchJson("/api/borrowers/{$registration->id}/approve-registration", ['acknowledge_possible_duplicate' => true])
            ->assertOk();

        $created = $this->auditRow($registration, 'created');
        $approved = $this->auditRow($registration, 'updated');

        $this->assertSame("Public registration saved as a possible duplicate of {$member->borrower_code} (similar name and the same birthdate).", $created->description);
        $this->assertSame("Registration approved after the reviewer checked the possible duplicate of {$member->borrower_code}.", $approved->description);
        $this->assertSame('similar_name_same_birthdate', $created->new_values['possible_duplicate_reason']);

        foreach ([$created, $approved] as $row) {
            $written = (string) json_encode([$row->old_values, $row->new_values, $row->description]);

            $this->assertStringNotContainsString('possible_duplicate_of_id', $written);
            $this->assertStringNotContainsString('Juana', $written);
            $this->assertStringNotContainsString('Cruz', $written);
        }
    }

    public function test_a_registration_with_no_link_keeps_its_audit_rows_as_before(): void
    {
        $registration = $this->savedFrom($this->register(['first_name' => 'Pedro', 'last_name' => 'Penduko']));

        $this->assertNull($this->auditRow($registration, 'created')->description);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Juan Dela Cruz, born 1990-05-12, a member of the seeded branch.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function member(array $overrides = []): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'birthdate' => '1990-05-12',
            ...$overrides,
        ]);
    }

    /**
     * A complete registration for Juan Dela Cruz, born 1990-05-12. No email:
     * each test sends several, and an email must be unique.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applicant(array $overrides = []): array
    {
        return [
            'branch_id' => $this->branch->id,
            'first_name' => 'Juan',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'birthdate' => '1990-05-12',
            'gender' => 'male',
            'civil_status' => 'married',
            'contact_number' => '09171234567',
            'address' => '123 Mango St',
            'city' => 'Butuan',
            'province' => 'Agusan del Norte',
            ...$overrides,
        ];
    }

    /**
     * The public form's request, from a caller who is not signed in.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/borrowers', $this->applicant(['status' => 'pending', ...$overrides]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: TestResponse, 1: int}
     */
    private function registerCountingQueries(array $overrides = []): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->register($overrides);
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return [$response, $queries];
    }

    /**
     * The answer with what is new on every submission (the id, the token and
     * its expiry) replaced by its form.
     *
     * @return array<string, mixed>
     */
    private function shape(TestResponse $response): array
    {
        $body = $response->json();
        $data = $body['data'];

        return [
            'keys' => array_keys($body),
            'success' => $body['success'],
            'data' => array_keys($data),
            'id' => is_int($data['id']),
            'token' => strlen($data['submission_token']),
            'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $data['expires_at']),
        ];
    }

    private function savedFrom(TestResponse $response): Borrower
    {
        return Borrower::findOrFail($response->assertCreated()->json('data.id'));
    }

    /**
     * A public registration for Juan Dela Cruz with a valid ID on file, ready
     * to approve but for its possible duplicate.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function flaggedWithValidId(array $overrides = []): Borrower
    {
        $registration = $this->savedFrom($this->register($overrides));
        $this->assertNotNull($registration->possible_duplicate_of_id);

        $registration->documents()->create([
            'type' => 'valid_id',
            'label' => 'umid',
            'file_path' => 'documents/valid_id/test.jpg',
            'original_filename' => 'test.jpg',
        ]);

        return $registration;
    }

    /**
     * A staff member in $branch only, holding $permissions.
     *
     * @param  list<string>  $permissions
     */
    private function reviewer(Branch $branch, array $permissions = ['borrowers:view', 'borrowers:approve']): User
    {
        $role = Role::create(['name' => 'reviewer_'.Str::lower(Str::random(8)), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);
        $user = User::factory()->inBranches($branch)->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function auditRow(Borrower $borrower, string $action): AuditLog
    {
        return AuditLog::query()
            ->where('auditable_type', Borrower::class)
            ->where('auditable_id', $borrower->id)
            ->where('action', $action)
            ->latest('id')
            ->firstOrFail();
    }
}
