<?php

namespace Tests\Feature\Savings\Servicing;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Savings\Accounts\SavingsAccountsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The account-officer picker on the savings opening form (gap round S4):
 * `GET /staff?for=savings` also answers a caller holding `savings:create`,
 * with the same names-only rows of active staff in the caller's branches.
 * Without `for=savings` the loan rule is unchanged.
 */
class StaffForSavingsTest extends TestCase
{
    use SavingsAccountsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSavings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_savings_teller_gets_names_only_with_for_savings(): void
    {
        $teller = $this->userWith(['savings:view', 'savings:create', 'savings:transact']);
        $colleague = User::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Rosa', 'last_name' => 'Reyes', 'email' => 'rosa@example.test']);
        $elsewhere = User::factory()->create(['branch_id' => Branch::factory()->create()->id, 'first_name' => 'Far', 'last_name' => 'Away']);
        $inactive = User::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Gone', 'last_name' => 'Staff', 'status' => 'inactive']);

        $response = $this->actingAs($teller)->getJson('/api/staff?for=savings&per_page=100')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($colleague->id, $ids);
        $this->assertNotContains($elsewhere->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
        foreach ($response->json('data') as $row) {
            $this->assertSame(['id', 'full_name'], array_keys($row));
        }
        $this->assertStringNotContainsString('rosa@example.test', $response->getContent());

        $this->getJson('/api/staff?for=savings&search=Rosa')->assertOk()->assertJsonPath('data.0.full_name', 'Rosa Reyes');
    }

    public function test_without_for_savings_the_loan_rule_is_unchanged(): void
    {
        $this->actingAs($this->userWith(['savings:view', 'savings:create']));
        $this->getJson('/api/staff')->assertForbidden();
        $this->getJson('/api/staff?for=loans')->assertForbidden();

        $this->actingAs($this->userWith(['savings:view', 'savings:transact']));
        $this->getJson('/api/staff?for=savings')->assertForbidden();

        $this->actingAs($this->userWith(['loans:create']));
        $this->getJson('/api/staff')->assertOk();
        $this->getJson('/api/staff?for=savings')->assertOk();
        $this->getJson('/api/staff?for=loans')->assertUnprocessable()->assertJsonPath('errors.for.0', 'The staff list can be asked for the savings opening form only (for=savings), or without it.');
    }
}
