<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingJournal;
use App\Models\User;
use Tests\TestCase;

/**
 * The entry belongs to the whole organisation, so every route also needs
 * `branches:view_all`. Editing needs `accounting:settings`; finalising also
 * needs `journals:post`.
 */
class OpeningBalancePermissionsTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOpeningBalances();
    }

    /** @param  list<string>  $permissions */
    private function userHolding(array $permissions): User
    {
        $user = $this->userWithNoRole();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_view_needs_accounting_view(): void
    {
        $this->actingAs($this->userHolding(['branches:view_all']));

        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_every_route_needs_branches_view_all(): void
    {
        $this->actingAs($this->userHolding(['accounting:view', 'accounting:settings', 'journals:post']));

        $this->getJson(self::URL)->assertForbidden();
        $this->save($this->balancedLines())->assertForbidden();
        $this->finalise(0)->assertForbidden();
    }

    public function test_save_needs_accounting_settings(): void
    {
        $this->actingAs($this->userHolding(['accounting:view', 'branches:view_all', 'journals:post']));

        $this->save($this->balancedLines())->assertForbidden();
        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.can_edit', false);
    }

    public function test_a_bookkeeper_with_accounting_settings_can_save_but_not_finalise(): void
    {
        $bookkeeper = $this->userWithRole('general_bookkeeper');
        $bookkeeper->givePermissionTo('accounting:settings');
        $this->actingAs($bookkeeper);

        $version = $this->saveBalancedDraft();

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_finalise', false);
        $this->finalise($version)->assertForbidden();
        $this->assertSame(0, AccountingJournal::query()->count());
    }

    public function test_finalise_needs_journals_post_and_accounting_settings(): void
    {
        $this->actingAs($this->userHolding(['accounting:view', 'branches:view_all', 'accounting:settings']));
        $version = $this->saveBalancedDraft();

        $this->finalise($version)->assertForbidden();

        $this->actingAs($this->userHolding(['accounting:view', 'branches:view_all', 'journals:post']));
        $this->finalise($version)->assertForbidden();

        $this->actingAs($this->userHolding(['accounting:view', 'branches:view_all', 'journals:post', 'accounting:settings']));
        $this->finalise($version)->assertOk();
    }
}
