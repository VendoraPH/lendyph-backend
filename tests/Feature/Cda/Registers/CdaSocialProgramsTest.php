<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaOfficer;
use App\Models\CdaSocialProgram;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Social programs (CDA design §6): one period's programs, money in and out as
 * two-decimal strings (computed in centavos), with the list's totals from the
 * server so the screen never adds money.
 */
class CdaSocialProgramsTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        $this->period();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_program_is_saved_with_money_as_two_decimal_strings(): void
    {
        $officer = CdaOfficer::factory()->create(['name' => 'Ana Reyes']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_SOCIAL]));

        $data = $this->postJson('/api/cda/periods/2026/social-programs', [
            'name' => 'Tree planting',
            'category' => 'environment',
            'held_on' => '2026-06-05',
            'actual_beneficiaries' => 120,
            'budget' => '15000',
            'actual_expense' => 12500.5,
            'funding_source' => 'cdf',
            'cdf_amount' => '10000.25',
            'status' => 'completed',
            'responsible_officer_id' => $officer->id,
        ])->assertCreated()->json('data');

        $this->assertSame('15000.00', $data['budget']);
        $this->assertSame('12500.50', $data['actual_expense']);
        $this->assertSame('10000.25', $data['cdf_amount']);
        $this->assertSame('Environment', $data['category_label']);
        $this->assertSame(2026, $data['year']);
        $this->assertSame(['id' => $officer->id, 'name' => 'Ana Reyes'], $data['responsible_officer']);
    }

    public function test_refusals_read_in_words(): void
    {
        $this->actingAs($this->cdaUser([CdaAccess::VIEW, CdaAccess::MANAGE_SOCIAL]));

        $errors = $this->postJson('/api/cda/periods/2026/social-programs', [
            'name' => 'Feeding',
            'category' => 'health',
            'held_on' => '2025-12-01',
            'actual_expense' => '100.123',
            'cdf_amount' => '-1',
        ])->assertUnprocessable()->json('errors');

        $this->assertSame(['The program must be held within 2026.'], $errors['held_on']);
        $this->assertSame(['The amount spent is in pesos with at most two decimals.'], $errors['actual_expense']);
        $this->assertSame(['The amount from the CDF can\'t be below ₱0.00.'], $errors['cdf_amount']);

        $this->postJson('/api/cda/periods/2026/social-programs', ['name' => 'Feeding', 'category' => 'health', 'actual_expense' => '100.00', 'cdf_amount' => '100.01'])
            ->assertUnprocessable()->assertJsonPath('errors.cdf_amount.0', 'The amount from the CDF can\'t be more than the amount spent.');

        $this->postJson('/api/cda/periods/2031/social-programs', ['name' => 'Feeding', 'category' => 'health'])->assertNotFound();
    }

    public function test_the_list_holds_one_periods_programs_and_the_servers_totals(): void
    {
        $period = $this->period();
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'health', 'actual_beneficiaries' => 10, 'actual_expense' => '100.10', 'cdf_amount' => '50.05', 'budget' => '200.00']);
        CdaSocialProgram::factory()->create(['period_id' => $period->id, 'category' => 'education', 'actual_beneficiaries' => 5, 'actual_expense' => '0.20', 'cdf_amount' => '0.00', 'budget' => null]);
        CdaSocialProgram::factory()->create(['period_id' => $this->period(2025)->id, 'actual_expense' => '999.99']);
        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));

        $response = $this->getJson('/api/cda/periods/2026/social-programs')->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(['count' => 2, 'beneficiaries' => 15, 'budget' => '200.00', 'actual_expense' => '100.30', 'cdf_amount' => '50.05'], $response->json('meta.totals'));
        $this->assertCount(1, $this->getJson('/api/cda/periods/2026/social-programs?category=health')->json('data'));
    }

    public function test_every_social_program_route_needs_its_permission_and_view_all(): void
    {
        $program = CdaSocialProgram::factory()->create(['period_id' => $this->period()->id]);
        $body = ['name' => 'Clean-up drive', 'category' => 'community'];

        $this->actingAs($this->userWith([CdaAccess::VIEW, CdaAccess::MANAGE_SOCIAL]));
        $this->getJson('/api/cda/periods/2026/social-programs')->assertForbidden();
        $this->postJson('/api/cda/periods/2026/social-programs', $body)->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::VIEW]));
        $this->getJson('/api/cda/periods/2026/social-programs')->assertOk();
        $this->postJson('/api/cda/periods/2026/social-programs', $body)->assertForbidden();
        $this->putJson("/api/cda/social-programs/{$program->id}", ['location' => 'Plaza'])->assertForbidden();
        $this->deleteJson("/api/cda/social-programs/{$program->id}")->assertForbidden();

        $this->actingAs($this->cdaUser([CdaAccess::MANAGE_SOCIAL]));
        $this->getJson('/api/cda/periods/2026/social-programs')->assertForbidden();
        $this->postJson('/api/cda/periods/2026/social-programs', $body)->assertCreated();
        $this->putJson("/api/cda/social-programs/{$program->id}", ['location' => 'Plaza'])->assertOk()->assertJsonPath('data.location', 'Plaza');
        $this->deleteJson("/api/cda/social-programs/{$program->id}")->assertOk();
        $this->assertDatabaseMissing('cda_social_programs', ['id' => $program->id]);
    }
}
