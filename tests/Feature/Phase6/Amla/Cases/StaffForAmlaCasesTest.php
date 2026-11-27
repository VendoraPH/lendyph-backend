<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\Branch;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The case assignee picker (AMLA-B8, AMLA-F4 flag 2): `GET /staff?for=amla_cases`
 * answers a caller holding `amla:cases` with head-office access, and lists
 * names only of the active staff a case can be assigned to: holders of
 * `amla:cases` with `branches:view_all`, through an active role. Without
 * `for=amla_cases` the loan rule is unchanged.
 */
class StaffForAmlaCasesTest extends TestCase
{
    use AmlaTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** @return list<int> */
    private function ids(User $caller, string $query = ''): array
    {
        return array_column($this->actingAs($caller)->getJson('/api/staff?for=amla_cases&per_page=100'.$query)->assertOk()->json('data'), 'id');
    }

    public function test_it_lists_names_only_of_active_head_office_case_holders(): void
    {
        $caller = $this->amlaUser(['amla:cases', 'branches:view_all']);
        $colleague = $this->amlaUser(['amla:cases', 'branches:view_all']);
        // A made-up surname no factory name can contain, so the search below finds her alone.
        $colleague->forceFill(['first_name' => 'Rosa', 'last_name' => 'Xyzzavelle', 'email' => 'rosa@example.test'])->save();
        $elsewhere = $this->amlaUser(['amla:cases', 'branches:view_all']);
        $elsewhere->forceFill(['branch_id' => Branch::factory()->create()->id])->save();
        $branchOnly = $this->amlaUser(['amla:cases']);
        $noCases = $this->amlaUser(['amla:alerts', 'branches:view_all']);
        $inactive = $this->amlaUser(['amla:cases', 'branches:view_all']);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $offRole = $this->amlaUser(['amla:cases', 'branches:view_all']);
        $offRole->roles()->first()->forceFill(['is_active' => false])->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->actingAs($caller)->getJson('/api/staff?for=amla_cases&per_page=100')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        foreach ([$caller, $colleague, $elsewhere, $this->admin] as $listed) {
            $this->assertContains($listed->id, $ids);
        }
        foreach ([$branchOnly, $noCases, $inactive, $offRole] as $left) {
            $this->assertNotContains($left->id, $ids);
        }
        foreach ($response->json('data') as $row) {
            $this->assertSame(['id', 'full_name'], array_keys($row));
        }
        $this->assertStringNotContainsString('rosa@example.test', $response->getContent());
        $this->assertSame([$colleague->id], $this->ids($caller, '&search=Xyzzavelle'));
        $this->assertSame([$colleague->id], $this->ids($caller, '&search=Rosa Xyzzavelle'));
    }

    public function test_only_a_head_office_case_holder_may_ask_and_without_it_the_loan_rule_stands(): void
    {
        $this->actingAs($this->amlaUser(['amla:cases']))->getJson('/api/staff?for=amla_cases')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:alerts', 'branches:view_all']))->getJson('/api/staff?for=amla_cases')->assertForbidden();
        $this->actingAs($this->amlaUser(['loans:create']))->getJson('/api/staff?for=amla_cases')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:cases', 'branches:view_all']))->getJson('/api/staff')->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:cases', 'branches:view_all']))->getJson('/api/staff?for=savings')->assertForbidden();
    }
}
