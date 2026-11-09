<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A member's and a co-maker's address on reports and printables follow the
 * round fz personal-data rule, as the contact number does: `borrowers:view`
 * and the member's own branch. Without them the address reads null; the rest
 * of the document is unchanged.
 */
class ReportAddressNeedsMemberAccessTest extends TestCase
{
    use SetupLendyPH;

    private const MEMBER_ADDRESS = '12 Mabini St, Butuan City';

    private const CO_MAKER_ADDRESS = '9 Rizal Ave, Cabadbaran';

    private Branch $far;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->loan = $this->createReleasedLoan();
        $this->loan->borrower->update(['address' => self::MEMBER_ADDRESS]);

        $coMaker = CoMaker::factory()->create([
            'borrower_id' => $this->loan->borrower_id,
            'address' => self::CO_MAKER_ADDRESS,
        ]);
        $this->loan->coMakers()->attach($coMaker->id);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'probe_'.uniqid(), 'guard_name' => 'web']);
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);

        return $user;
    }

    private function moveMemberToTheFarBranch(): void
    {
        Borrower::whereKey($this->loan->borrower_id)->update(['branch_id' => $this->far->id]);
    }

    // ── Statement of Account (also the demand letter) ──

    public function test_the_statement_of_account_leaves_out_the_address_without_borrowers_view(): void
    {
        $this->actingAs($this->userWith(['reports:view']));
        $data = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('member_address', $data['loan']);
        $this->assertNull($data['loan']['member_address']);
        $this->assertArrayHasKey('address', $data['borrower']);
        $this->assertNull($data['borrower']['address']);
        $this->assertSame($this->loan->borrower->full_name, $data['borrower']['full_name']);
        $this->assertStringNotContainsString(self::MEMBER_ADDRESS, json_encode($data));
    }

    public function test_the_statement_of_account_leaves_out_the_address_of_a_member_in_another_branch(): void
    {
        $this->moveMemberToTheFarBranch();

        $this->actingAs($this->userWith(['reports:view', 'borrowers:view']));
        $data = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');

        $this->assertNull($data['loan']['member_address']);
        $this->assertNull($data['borrower']['address']);
    }

    public function test_the_statement_of_account_keeps_the_address_for_a_caller_with_member_access(): void
    {
        $this->actingAs($this->userWith(['reports:view', 'borrowers:view']));
        $data = $this->getJson("/api/reports/statement-of-account/{$this->loan->id}")->assertOk()->json('data');

        $this->assertSame(self::MEMBER_ADDRESS, $data['loan']['member_address']);
        $this->assertSame(self::MEMBER_ADDRESS, $data['borrower']['address']);
    }

    // ── Subsidiary Ledger and Share Capital Statement ──

    public function test_the_member_reports_leave_out_the_address_without_borrowers_view(): void
    {
        $member = $this->loan->borrower_id;

        foreach (["/api/reports/subsidiary-ledger/{$member}", "/api/reports/share-capital-statement/{$member}"] as $url) {
            $this->actingAs($this->userWith(['reports:view']));
            $slim = $this->getJson($url)->assertOk()->json('data.borrower');
            $this->assertArrayHasKey('address', $slim, $url);
            $this->assertNull($slim['address'], $url);
            $this->assertNotEmpty($slim['full_name'], $url);

            $this->actingAs($this->userWith(['reports:view', 'borrowers:view']));
            $this->assertSame(self::MEMBER_ADDRESS, $this->getJson($url)->assertOk()->json('data.borrower.address'), $url);
        }
    }

    // ── Promissory note ──

    public function test_the_promissory_note_leaves_out_both_addresses_without_borrowers_view(): void
    {
        $this->actingAs($this->userWith(['loans:view']));
        $data = $this->getJson("/api/loans/{$this->loan->id}/promissory-note")->assertOk()->json('data');

        $this->assertArrayHasKey('address', $data['borrower']);
        $this->assertNull($data['borrower']['address']);
        $this->assertArrayHasKey('address', $data['co_makers'][0]);
        $this->assertNull($data['co_makers'][0]['address']);
        $this->assertSame($this->loan->borrower->full_name, $data['borrower']['full_name']);
        $this->assertNotEmpty($data['co_makers'][0]['full_name']);
    }

    public function test_the_promissory_note_leaves_out_both_addresses_of_a_member_in_another_branch(): void
    {
        $this->moveMemberToTheFarBranch();

        $this->actingAs($this->userWith(['loans:view', 'borrowers:view']));
        $data = $this->getJson("/api/loans/{$this->loan->id}/promissory-note")->assertOk()->json('data');

        $this->assertNull($data['borrower']['address']);
        $this->assertNull($data['co_makers'][0]['address']);
    }

    public function test_the_promissory_note_keeps_both_addresses_for_a_caller_with_member_access(): void
    {
        $this->actingAs($this->userWith(['loans:view', 'borrowers:view']));
        $data = $this->getJson("/api/loans/{$this->loan->id}/promissory-note")->assertOk()->json('data');

        $this->assertSame(self::MEMBER_ADDRESS, $data['borrower']['address']);
        $this->assertSame(self::CO_MAKER_ADDRESS, $data['co_makers'][0]['address']);
    }
}
