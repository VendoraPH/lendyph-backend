<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\Borrower;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSetting;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The officers register (CDA design §6): a linked member supplies the name
 * and sex (nothing is copied, nothing else of the member is shown); an
 * unlinked officer stores their own. Each row carries its training
 * compliance against the position's required trainings. Delete is soft.
 */
class CdaOfficersTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_linked_officer_copies_nothing_and_shows_only_the_members_name_code_and_sex(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Rosa', 'middle_name' => null, 'suffix' => null, 'last_name' => 'Lim', 'gender' => 'female', 'status' => 'active']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $data = $this->postJson('/api/cda/officers', [
            'borrower_id' => $member->id,
            'name' => 'Someone Else',
            'sex' => 'male',
            'position' => 'chairperson',
            'term_start' => '2025-03-01',
            'term_end' => '2027-02-28',
            'election_date' => '2025-02-15',
        ])->assertCreated()->json('data');

        $stored = CdaOfficer::query()->findOrFail($data['id']);
        $this->assertNull($stored->name);
        $this->assertNull($stored->sex);
        $this->assertSame('Rosa Lim', $data['name']);
        $this->assertSame('female', $data['sex']);
        $this->assertSame(['id' => $member->id, 'name' => 'Rosa Lim', 'code' => $member->borrower_code], $data['member']);
        $this->assertSame('Chairperson', $data['position_label']);
        $this->assertSame('2025-02-15', $data['election_date']);
        $this->assertArrayNotHasKey('contact_number', $data['member']);

        $member->update(['first_name' => 'Rosalinda']);
        $this->assertSame('Rosalinda Lim', $this->getJson('/api/cda/officers')->assertOk()->json('data.0.name'));
    }

    public function test_an_unlinked_officer_needs_a_name_and_sex_and_refusals_read_in_words(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $errors = $this->postJson('/api/cda/officers', ['position' => 'general_manager', 'term_start' => '2026-01-01', 'term_end' => '2025-12-31'])
            ->assertUnprocessable()->json('errors');

        $this->assertSame(['Give the officer\'s name, or link the officer to a member.'], $errors['name']);
        $this->assertSame(['Give the officer\'s sex, or link the officer to a member.'], $errors['sex']);
        $this->assertSame(['The term can\'t end before it starts.'], $errors['term_end']);

        $this->postJson('/api/cda/officers', ['name' => 'Pedro Cruz', 'sex' => 'male', 'position' => 'general_manager', 'term_start' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('data.name', 'Pedro Cruz')->assertJsonPath('data.member', null);

        $this->postJson('/api/cda/officers', ['name' => 'X', 'sex' => 'male', 'position' => 'emperor', 'term_start' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonPath('errors.position.0', 'Choose a position from the list.');
    }

    public function test_an_applicant_who_isnt_a_member_cant_be_linked(): void
    {
        $applicant = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $this->postJson('/api/cda/officers', ['borrower_id' => $applicant->id, 'position' => 'treasurer', 'term_start' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', 'Link the officer to a member of the cooperative.');
    }

    public function test_linking_an_unlinked_officer_later_clears_the_stored_name_and_sex(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id, 'gender' => 'male', 'status' => 'active']);
        $officer = CdaOfficer::factory()->create(['name' => 'Typed Name', 'sex' => 'female', 'position' => 'secretary']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $this->putJson("/api/cda/officers/{$officer->id}", ['borrower_id' => $member->id])->assertOk()->assertJsonPath('data.sex', 'male');

        $officer->refresh();
        $this->assertNull($officer->name);
        $this->assertNull($officer->sex);
        $this->assertSame('secretary', $officer->position);
    }

    public function test_each_row_reads_its_training_compliance_against_the_positions_required_trainings(): void
    {
        CdaSetting::current()->update(['required_trainings' => ['chairperson' => ['fundamentals', 'governance']]]);
        $chair = CdaOfficer::factory()->create(['position' => 'chairperson', 'term_start' => '2025-01-01']);
        $manager = CdaOfficer::factory()->create(['position' => 'manager', 'term_start' => '2025-01-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'fundamentals', 'status' => 'completed', 'completed_on' => '2025-05-01', 'hours' => '8.00']);
        CdaOfficerTraining::factory()->create(['officer_id' => $chair->id, 'requirement_key' => 'governance', 'status' => 'scheduled', 'completed_on' => null, 'hours' => '0.00']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));

        $rows = collect($this->getJson('/api/cda/officers')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['required' => 2, 'completed' => 1, 'missing' => ['governance'], 'status' => 'incomplete'], $rows[$chair->id]['trainings']);
        $this->assertSame(['required' => 0, 'completed' => 0, 'missing' => [], 'status' => 'not_required'], $rows[$manager->id]['trainings']);
    }

    public function test_the_list_filters_by_year_and_status_and_delete_is_soft(): void
    {
        $old = CdaOfficer::factory()->create(['term_start' => '2020-01-01', 'term_end' => '2022-12-31']);
        $current = CdaOfficer::factory()->create(['term_start' => '2025-01-01', 'term_end' => null]);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));

        $this->assertSame([$current->id], array_column($this->getJson('/api/cda/officers?year=2026')->assertOk()->json('data'), 'id'));
        $this->assertSame(2, $this->getJson('/api/cda/officers')->json('meta.total'));

        $this->deleteJson("/api/cda/officers/{$old->id}")->assertOk()->assertJsonPath('message', 'Officer removed from the register.');
        $this->assertSoftDeleted('cda_officers', ['id' => $old->id]);
        $this->assertSame([$current->id], array_column($this->getJson('/api/cda/officers')->json('data'), 'id'));
    }

    public function test_every_officer_route_needs_its_permission_and_view_all(): void
    {
        $officer = CdaOfficer::factory()->create();
        $body = ['name' => 'Ana Reyes', 'sex' => 'female', 'position' => 'board_member', 'term_start' => '2026-01-01'];

        $this->actingAs($this->userWith([CdaAccess::VIEW, CdaAccess::MANAGE_OFFICERS]));
        $this->getJson('/api/cda/officers')->assertForbidden();
        $this->postJson('/api/cda/officers', $body)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_OFFICERS]));
        $this->getJson('/api/cda/officers')->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $this->getJson('/api/cda/officers')->assertOk();
        $this->postJson('/api/cda/officers', $body)->assertForbidden();
        $this->putJson("/api/cda/officers/{$officer->id}", ['remarks' => 'x'])->assertForbidden();
        $this->deleteJson("/api/cda/officers/{$officer->id}")->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_OFFICERS]));
        $this->postJson('/api/cda/officers', $body)->assertCreated();
        $this->putJson("/api/cda/officers/{$officer->id}", ['remarks' => 'x'])->assertOk();
        $this->deleteJson("/api/cda/officers/{$officer->id}")->assertOk();
    }
}
