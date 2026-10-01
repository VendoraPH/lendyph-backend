<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
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

    public function test_store_requires_identifying_fields(): void
    {
        $this->postJson('/api/gcash/non-members', ['full_name' => 'No ID'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile_number', 'id_type', 'id_number']);
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
}
