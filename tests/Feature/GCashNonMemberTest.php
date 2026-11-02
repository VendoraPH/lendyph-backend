<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class GCashNonMemberTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    public function test_index_lists_non_members_with_transaction_count(): void
    {
        $walkIn = GCashNonMember::factory()->create(['full_name' => 'Ana Reyes']);
        GCashTransaction::factory()->forNonMember($walkIn)->create([
            'transactor_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/gcash/non-members');

        $response->assertOk()
            ->assertJsonPath('data.0.full_name', 'Ana Reyes')
            ->assertJsonPath('data.0.transaction_count', 1);
    }

    public function test_index_search_matches_name_mobile_and_id_number(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana Reyes', 'mobile_number' => '09171234567', 'id_number' => 'AB12345678']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz', 'mobile_number' => '09280000000', 'id_number' => 'ZZ99999999']);

        $this->getJson('/api/gcash/non-members?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana Reyes');

        $this->getJson('/api/gcash/non-members?search=09280000000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ben Cruz');

        $this->getJson('/api/gcash/non-members?search=AB12345678')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana Reyes');
    }

    public function test_store_creates_a_non_member(): void
    {
        $response = $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
            'remarks' => 'Regular walk-in',
        ]);

        $response->assertCreated()->assertJsonPath('data.full_name', 'Carla Dizon');
        $this->assertDatabaseHas('gcash_non_members', ['full_name' => 'Carla Dizon']);
    }

    /**
     * Name and mobile number are always required. The ID is optional (Kevin's
     * request, approved by Arvin), so a request without one fails on the
     * mobile number only.
     */
    public function test_store_requires_identifying_fields(): void
    {
        $this->postJson('/api/gcash/non-members', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name', 'mobile_number'])
            ->assertJsonMissingValidationErrors(['id_type', 'id_number']);

        $this->postJson('/api/gcash/non-members', ['full_name' => 'No ID'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number'])
            ->assertJsonMissingValidationErrors(['id_type', 'id_number']);
    }

    public function test_store_creates_a_walk_in_without_an_id(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertCreated()
            ->assertJsonPath('data.full_name', 'Carla Dizon')
            ->assertJsonPath('data.id_type', null)
            ->assertJsonPath('data.id_number', null);

        $this->assertDatabaseHas('gcash_non_members', [
            'full_name' => 'Carla Dizon',
            'id_type' => null,
            'id_number' => null,
        ]);
    }

    public function test_store_treats_blank_id_fields_as_no_id(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => '',
            'id_number' => '',
        ])->assertCreated()->assertJsonPath('data.id_number', null);
    }

    public function test_store_refuses_an_id_type_without_an_id_number(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['id_number'])
            ->assertJsonMissingValidationErrors(['id_type']);

        $this->assertDatabaseCount('gcash_non_members', 0);
    }

    public function test_store_refuses_an_id_number_without_an_id_type(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_number' => 'CD12345678',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['id_type'])
            ->assertJsonMissingValidationErrors(['id_number']);

        $this->assertDatabaseCount('gcash_non_members', 0);
    }

    public function test_update_may_remove_a_walk_ins_id(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => null,
            'id_number' => null,
        ])->assertOk()->assertJsonPath('data.id_number', null);

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_type' => null, 'id_number' => null]);
    }

    public function test_store_without_an_id_warns_when_name_and_mobile_match_another_walk_in(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla  Dizon',
            'mobile_number' => '0917-111-2222',
        ]);

        $response = $this->postJson('/api/gcash/non-members', [
            'full_name' => ' carla dizon ',
            'mobile_number' => '+63 917 111 2222',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['possible_duplicate']);

        $this->assertStringContainsString('Carla  Dizon', $response->json('errors.possible_duplicate.0'));
        $this->assertStringNotContainsStringIgnoringCase('non-member', $response->getContent());
        $this->assertDatabaseCount('gcash_non_members', 1);
    }

    public function test_store_without_an_id_warns_on_a_match_that_has_an_id(): void
    {
        GCashNonMember::factory()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ]);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '639171112222',
        ])->assertStatus(422)->assertJsonValidationErrors(['possible_duplicate']);
    }

    public function test_store_without_an_id_saves_a_possible_duplicate_once_acknowledged(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'acknowledge_possible_duplicate' => true,
        ])->assertCreated()->assertJsonMissingPath('data.acknowledge_possible_duplicate');

        $this->assertDatabaseCount('gcash_non_members', 2);
    }

    public function test_store_without_an_id_does_not_warn_when_only_the_name_matches(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09179998888',
        ])->assertCreated();
    }

    public function test_store_without_an_id_does_not_warn_about_a_removed_walk_in(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->delete();

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertCreated();
    }

    public function test_store_with_an_id_does_not_get_the_name_and_mobile_warning(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ])->assertCreated();
    }

    public function test_update_without_an_id_does_not_warn_about_the_record_itself(): void
    {
        $walkIn = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'remarks' => 'Comes on Fridays',
        ])->assertOk()->assertJsonPath('data.remarks', 'Comes on Fridays');
    }

    public function test_update_without_an_id_warns_when_another_walk_in_matches(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $walkIn = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla D.',
            'mobile_number' => '09171112222',
        ]);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertStatus(422)->assertJsonValidationErrors(['possible_duplicate']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'acknowledge_possible_duplicate' => true,
        ])->assertOk()->assertJsonPath('data.full_name', 'Carla Dizon');
    }

    public function test_update_sending_only_a_null_id_type_is_refused(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => null,
        ])->assertStatus(422)
            ->assertJsonPath('errors.id_number.0', 'Send both ID fields, or neither.');

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_type' => 'UMID', 'id_number' => 'CD12345678']);
    }

    public function test_update_sending_only_a_null_id_number_is_refused(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_number' => null,
        ])->assertStatus(422)
            ->assertJsonPath('errors.id_type.0', 'Send both ID fields, or neither.');

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_type' => 'UMID', 'id_number' => 'CD12345678']);
    }

    public function test_update_leaving_out_both_id_keys_keeps_the_id_and_does_not_warn(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $walkIn = GCashNonMember::factory()->create([
            'full_name' => 'Carla D.',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ]);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertOk()->assertJsonPath('data.id_number', 'CD12345678');

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'full_name' => 'Carla Dizon', 'id_type' => 'UMID', 'id_number' => 'CD12345678']);
    }

    public function test_update_of_an_acknowledged_pair_does_not_warn_again_on_an_unrelated_edit(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $saved = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'carla  dizon',
            'mobile_number' => '+63 917 111 2222',
        ]);

        $this->putJson("/api/gcash/non-members/{$saved->id}", [
            'full_name' => 'carla  dizon',
            'mobile_number' => '+63 917 111 2222',
            'id_type' => null,
            'id_number' => null,
            'remarks' => 'Comes on Fridays',
        ])->assertOk()->assertJsonPath('data.remarks', 'Comes on Fridays');

        // Reformatting only (same name and number as the check compares them)
        // is not a change either.
        $this->putJson("/api/gcash/non-members/{$saved->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '0917-111-2222',
        ])->assertOk();
    }

    public function test_update_of_an_acknowledged_pair_warns_when_the_mobile_changes_to_another_match(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09173334444',
        ]);
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $saved = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);

        $this->putJson("/api/gcash/non-members/{$saved->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09173334444',
        ])->assertStatus(422)->assertJsonValidationErrors(['possible_duplicate']);
    }

    public function test_update_removing_the_id_warns_when_another_walk_in_matches(): void
    {
        GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $walkIn = GCashNonMember::factory()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ]);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => null,
            'id_number' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['possible_duplicate']);

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_number' => 'CD12345678']);
    }

    public function test_update_removing_the_id_does_not_warn_about_the_record_itself(): void
    {
        $walkIn = GCashNonMember::factory()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ]);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => null,
            'id_number' => null,
        ])->assertOk()->assertJsonPath('data.id_number', null);
    }

    public function test_a_role_without_gcash_transact_gets_403_never_the_duplicate_warning(): void
    {
        $match = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ]);
        $other = GCashNonMember::factory()->withoutId()->create([
            'full_name' => 'Carla D.',
            'mobile_number' => '09171112222',
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $store = $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertForbidden();

        $update = $this->putJson("/api/gcash/non-members/{$other->id}", [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
        ])->assertForbidden();

        foreach ([$store, $update] as $response) {
            $response->assertJsonMissingPath('errors');
            $this->assertStringNotContainsString($match->full_name, $response->getContent());
        }
        $this->assertDatabaseCount('gcash_non_members', 2);
    }

    /**
     * Rolling back would make the ID columns NOT NULL again, which needs a
     * value for every walk-in saved without one; down() refuses instead of
     * inventing an ID. It throws before any DDL, so the test's transaction
     * stays intact and undoes the row.
     */
    public function test_migration_rollback_refuses_while_a_walk_in_has_no_id(): void
    {
        GCashNonMember::factory()->withoutId()->create();
        $migration = require database_path('migrations/2026_11_02_130000_make_gcash_non_member_id_nullable.php');

        try {
            $migration->down();
            $this->fail('down() should refuse while a walk-in has no ID.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 walk-in(s) have no ID', $e->getMessage());
        }

        $columns = collect(Schema::getColumns('gcash_non_members'))->keyBy('name');
        $this->assertTrue($columns['id_type']['nullable']);
        $this->assertTrue($columns['id_number']['nullable']);
    }

    public function test_index_search_handles_walk_ins_without_an_id(): void
    {
        GCashNonMember::factory()->withoutId()->create(['full_name' => 'Ana Reyes']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz', 'id_number' => '12345678']);

        $this->getJson('/api/gcash/non-members?search=Ana')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id_number', null);

        $this->getJson('/api/gcash/non-members?search=1234-5678')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ben Cruz');
    }

    public function test_transaction_can_be_recorded_for_a_walk_in_without_an_id(): void
    {
        $walkIn = GCashNonMember::factory()->withoutId()->create();

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.non_member.full_name', $walkIn->full_name);
    }

    public function test_update_changes_the_record(): void
    {
        $walkIn = GCashNonMember::factory()->create(['full_name' => 'Old Name']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'New Name',
            'mobile_number' => '09170000000',
            'id_type' => 'Passport',
            'id_number' => 'P1234567',
        ])->assertOk()->assertJsonPath('data.full_name', 'New Name');

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'full_name' => 'New Name']);
    }

    public function test_store_rejects_an_id_already_registered_to_another_walk_in(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'cd12345678',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        $this->assertDatabaseCount('gcash_non_members', 1);
    }

    public function test_store_allows_the_same_number_under_a_different_id_type(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'Passport',
            'id_number' => 'CD12345678',
        ])->assertCreated();
    }

    public function test_a_removed_walk_in_does_not_block_its_id(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678'])->delete();

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ])->assertCreated();
    }

    public function test_update_rejects_an_id_already_registered_to_another_walk_in(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'Passport', 'id_number' => 'P1234567']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_number' => 'P1234567']);
    }

    public function test_update_keeps_its_own_id(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => 'CD12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => 'Renamed',
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => 'UMID',
            'id_number' => 'CD12345678',
        ])->assertOk()->assertJsonPath('data.full_name', 'Renamed');
    }

    public function test_store_rejects_an_id_that_differs_only_by_dashes(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '12345678']);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => '1234-5678',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        $this->assertDatabaseCount('gcash_non_members', 1);
    }

    public function test_store_rejects_an_id_that_differs_only_by_spaces_dashes_and_case(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'Passport', 'id_number' => 'AB1234']);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'Passport',
            'id_number' => 'ab 12-34',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);
    }

    public function test_store_allows_a_reformatted_number_under_a_different_id_type(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '12345678']);

        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'Passport',
            'id_number' => '1234-5678',
        ])->assertCreated();
    }

    public function test_store_keeps_the_id_number_as_entered(): void
    {
        $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Carla Dizon',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => '1234-5678 ab',
        ])->assertCreated()->assertJsonPath('data.id_number', '1234-5678 ab');

        $this->assertDatabaseHas('gcash_non_members', ['id_number' => '1234-5678 ab']);
    }

    public function test_update_may_reformat_its_own_id_number(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => 'UMID',
            'id_number' => '1234-5678',
        ])->assertOk()->assertJsonPath('data.id_number', '1234-5678');
    }

    public function test_update_rejects_another_walk_ins_id_that_differs_only_by_dashes(): void
    {
        GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '12345678']);
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '87654321']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => 'UMID',
            'id_number' => '1234-5678',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);
    }

    public function test_index_search_finds_a_dashed_id_number_typed_without_dashes(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana Reyes', 'id_number' => '1234-5678']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz', 'id_number' => 'ZZ99999999']);

        $this->getJson('/api/gcash/non-members?search=12345678')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana Reyes');
    }

    public function test_index_search_finds_an_undashed_id_number_typed_with_dashes(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana Reyes', 'id_number' => '12345678']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz', 'id_number' => 'AB87654321']);
        GCashNonMember::factory()->create(['full_name' => 'Carla Dizon', 'id_number' => 'ZZ99999999']);

        $this->getJson('/api/gcash/non-members?search=1234-5678')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana Reyes');

        $this->getJson('/api/gcash/non-members?search='.urlencode('ab-8765 4321'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ben Cruz');
    }

    public function test_index_search_of_only_dashes_does_not_list_every_walk_in(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana Reyes', 'id_number' => '12345678']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz', 'id_number' => 'AB87654321']);

        $this->getJson('/api/gcash/non-members?search=---')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_rejects_a_search_that_is_not_a_string(): void
    {
        $this->getJson('/api/gcash/non-members?search[]=x')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_index_search_matches_a_percent_sign_literally(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Promo 50% Off']);
        GCashNonMember::factory()->create(['full_name' => 'Promo 500 Off']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz']);

        $this->getJson('/api/gcash/non-members?search='.urlencode('50%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Promo 50% Off');
    }

    public function test_index_search_matches_an_underscore_literally(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana_Reyes']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz']);

        $this->getJson('/api/gcash/non-members?search=_')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana_Reyes');
    }

    public function test_index_search_matches_a_backslash_literally(): void
    {
        GCashNonMember::factory()->create(['full_name' => 'Ana \\ Reyes']);
        GCashNonMember::factory()->create(['full_name' => 'Ben Cruz']);

        $this->getJson('/api/gcash/non-members?search='.urlencode('\\'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana \\ Reyes');
    }

    public function test_store_rejects_an_id_number_of_only_dashes_or_spaces(): void
    {
        foreach (['---', ' - - '] as $idNumber) {
            $this->postJson('/api/gcash/non-members', [
                'full_name' => 'Carla Dizon',
                'mobile_number' => '09171112222',
                'id_type' => 'UMID',
                'id_number' => $idNumber,
            ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);
        }

        $this->assertDatabaseCount('gcash_non_members', 0);
    }

    public function test_update_rejects_an_id_number_of_only_dashes(): void
    {
        $walkIn = GCashNonMember::factory()->create(['id_type' => 'UMID', 'id_number' => '12345678']);

        $this->putJson("/api/gcash/non-members/{$walkIn->id}", [
            'full_name' => $walkIn->full_name,
            'mobile_number' => $walkIn->mobile_number,
            'id_type' => 'UMID',
            'id_number' => '---',
        ])->assertStatus(422)->assertJsonValidationErrors(['id_number']);

        $this->assertDatabaseHas('gcash_non_members', ['id' => $walkIn->id, 'id_number' => '12345678']);
    }

    public function test_destroy_removes_a_non_member_from_the_list(): void
    {
        $walkIn = GCashNonMember::factory()->create();

        $this->deleteJson("/api/gcash/non-members/{$walkIn->id}")->assertOk();

        $this->assertSoftDeleted('gcash_non_members', ['id' => $walkIn->id]);
        $this->getJson('/api/gcash/non-members')->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The remove dialog promises "Transactions already recorded for them are
     * kept", so removal is a soft delete: gone from the list, history intact,
     * and the transaction still names its party.
     */
    public function test_destroy_keeps_recorded_transactions_and_their_party(): void
    {
        $walkIn = GCashNonMember::factory()->create(['full_name' => 'Elena Torres']);
        GCashTransaction::factory()->forNonMember($walkIn)->create([
            'transactor_user_id' => $this->admin->id,
        ]);

        $this->deleteJson("/api/gcash/non-members/{$walkIn->id}")->assertOk();

        $this->assertSoftDeleted('gcash_non_members', ['id' => $walkIn->id]);
        $this->assertDatabaseCount('gcash_transactions', 1);

        $this->getJson('/api/gcash/non-members')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/gcash/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.non_member.full_name', 'Elena Torres');
    }

    public function test_a_removed_walk_in_cannot_be_transacted_with(): void
    {
        $walkIn = GCashNonMember::factory()->create();
        $walkIn->delete();

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['gcash_non_member_id']);
    }

    public function test_transaction_can_be_recorded_for_a_walk_in(): void
    {
        $walkIn = GCashNonMember::factory()->create();

        $response = $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.gcash_non_member_id', $walkIn->id)
            ->assertJsonPath('data.borrower_id', null)
            ->assertJsonPath('data.non_member.full_name', $walkIn->full_name);
    }

    public function test_transaction_requires_exactly_one_party(): void
    {
        $walkIn = GCashNonMember::factory()->create();

        $this->postJson('/api/gcash/transactions', [
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['borrower_id']);

        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['gcash_non_member_id']);
    }

    public function test_transaction_listing_exposes_the_walk_in_party(): void
    {
        $walkIn = GCashNonMember::factory()->create(['full_name' => 'Dina Santos']);
        GCashTransaction::factory()->forNonMember($walkIn)->create([
            'transactor_user_id' => $this->admin->id,
        ]);

        $this->getJson('/api/gcash/transactions')
            ->assertOk()
            ->assertJsonPath('data.0.non_member.full_name', 'Dina Santos');
    }

    /**
     * The duplicate guard is scoped to the party. Two different walk-ins paying
     * the same amount within the same minute is ordinary counter traffic, and
     * must not be mistaken for one customer double-tapping.
     */
    public function test_duplicate_guard_does_not_block_a_different_walk_in(): void
    {
        $first = GCashNonMember::factory()->create();
        $second = GCashNonMember::factory()->create();

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $first->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated();

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $second->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated();
    }

    public function test_duplicate_guard_still_blocks_the_same_walk_in(): void
    {
        $walkIn = GCashNonMember::factory()->create();

        $payload = [
            'gcash_non_member_id' => $walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ];

        $this->postJson('/api/gcash/transactions', $payload)->assertCreated();
        $this->postJson('/api/gcash/transactions', $payload)->assertStatus(409);
    }

    /**
     * Staff call these customers walk-ins, so no message they can read (a
     * validation error on the transaction form) says "non member".
     */
    public function test_transaction_validation_messages_call_the_party_a_walk_in(): void
    {
        $missing = $this->postJson('/api/gcash/transactions', [
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertStatus(422);

        $this->assertSame(
            ['The borrower id field is required when walk-in is not present.'],
            $missing->json('errors.borrower_id'),
        );
        $this->assertSame(
            ['The walk-in field is required when borrower id is not present.'],
            $missing->json('errors.gcash_non_member_id'),
        );

        $unknown = $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => 999999,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertStatus(422);

        $this->assertSame(['The selected walk-in is invalid.'], $unknown->json('errors.gcash_non_member_id'));
        $this->assertStringNotContainsStringIgnoringCase('non member', $missing->getContent().$unknown->getContent());
    }

    /**
     * The audit trail shows each entry's description to staff, so new entries
     * for a walk-in say "walk-in" rather than "non-member".
     */
    public function test_audit_descriptions_call_the_customer_a_walk_in(): void
    {
        $id = $this->postJson('/api/gcash/non-members', [
            'full_name' => 'Gina Lopez',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'GL12345678',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/gcash/non-members/{$id}", [
            'full_name' => 'Gina Lopez-Santos',
            'mobile_number' => '09171112222',
            'id_type' => 'UMID',
            'id_number' => 'GL12345678',
        ])->assertOk();

        $this->deleteJson("/api/gcash/non-members/{$id}")->assertOk();

        $descriptions = AuditLog::query()
            ->where('auditable_type', GCashNonMember::class)
            ->where('auditable_id', $id)
            ->orderBy('id')
            ->pluck('description')
            ->all();

        $this->assertSame([
            'GCash walk-in Gina Lopez registered',
            'GCash walk-in Gina Lopez-Santos updated',
            'GCash walk-in Gina Lopez-Santos removed',
        ], $descriptions);
    }
}
