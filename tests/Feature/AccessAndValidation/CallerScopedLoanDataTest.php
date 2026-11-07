<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * What a caller reads about a loan's people and neighbours follows their own
 * permissions and branches.
 */
class CallerScopedLoanDataTest extends TestCase
{
    use SetupLendyPH;

    private Branch $far;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->loan = $this->createReleasedLoan();
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

    // ── M1: the CBS reference ──

    public function test_the_releases_report_sends_the_cbs_reference_only_with_an_auto_pay_permission(): void
    {
        $this->patchJson("/api/loans/{$this->loan->id}/auto-pay", ['enabled' => true, 'cbs_reference' => 'CBS-77'])->assertOk();

        $this->actingAs($this->userWith(['reports:view', 'auto_pay:view']));
        $row = collect($this->getJson('/api/reports/releases')->assertOk()->json('data'))->firstWhere('id', $this->loan->id);
        $this->assertSame('CBS-77', $row['auto_pay_cbs_reference']);

        $this->actingAs($this->userWith(['reports:view']));
        $row = collect($this->getJson('/api/reports/releases')->assertOk()->json('data'))->firstWhere('id', $this->loan->id);
        $this->assertArrayHasKey('auto_pay_cbs_reference', $row);
        $this->assertNull($row['auto_pay_cbs_reference']);
        $this->assertTrue($row['auto_pay_enabled']);
    }

    // ── M3: the member's branch ──

    public function test_the_full_member_on_a_loan_row_also_needs_the_members_branch(): void
    {
        Borrower::whereKey($this->loan->borrower_id)->update(['branch_id' => $this->far->id]);
        $coMaker = CoMaker::factory()->create(['borrower_id' => $this->loan->borrower_id, 'contact_number' => '09179998888']);
        $this->loan->coMakers()->attach($coMaker->id);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('cashier');
        $this->actingAs($user);

        $data = $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['id', 'full_name', 'borrower_code'], array_keys($data['borrower']));
        $this->assertArrayNotHasKey('contact_number', $data['co_makers'][0]);

        $row = collect($this->getJson('/api/loans')->assertOk()->json('data'))->firstWhere('id', $this->loan->id);
        $this->assertEqualsCanonicalizing(['id', 'full_name', 'borrower_code'], array_keys($row['borrower']));

        $this->actingAs($this->admin);
        $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->assertJsonStructure(['data' => ['borrower' => ['contact_number', 'birthdate']]]);
    }

    // ── L3: the co-maker answer and the two reports ──

    public function test_adding_a_co_maker_answers_the_slim_shape_without_borrowers_view(): void
    {
        $loan = $this->approvedLoan();
        $payload = fn (string $first): array => [
            'first_name' => $first,
            'last_name' => 'Comaker',
            'address' => '9 Rizal Ave',
            'contact_number' => '09179998888',
        ];

        $this->actingAs($this->userWith(['loans:view', 'loans:release']));
        $slim = $this->postJson("/api/loans/{$loan->id}/co-makers", $payload('Slim'))->assertCreated()->json('data');

        $this->assertSame('Slim Comaker', $slim['full_name']);
        foreach (['address', 'contact_number', 'occupation', 'employer', 'monthly_income'] as $field) {
            $this->assertArrayNotHasKey($field, $slim);
        }

        $this->actingAs($this->userWith(['loans:view', 'loans:release', 'borrowers:view']));
        $this->postJson("/api/loans/{$loan->id}/co-makers", $payload('Whole'))
            ->assertCreated()
            ->assertJsonPath('data.contact_number', '09179998888');
    }

    public function test_the_member_reports_drop_the_contact_number_without_borrowers_view(): void
    {
        $member = $this->loan->borrower_id;
        $number = Borrower::find($member)->contact_number;

        foreach (["/api/reports/subsidiary-ledger/{$member}", "/api/reports/share-capital-statement/{$member}"] as $url) {
            $this->actingAs($this->userWith(['reports:view']));
            $slim = $this->getJson($url)->assertOk()->json('data.borrower');
            $this->assertArrayNotHasKey('contact_number', $slim, $url);
            $this->assertNotEmpty($slim['full_name'], $url);

            $this->actingAs($this->userWith(['reports:view', 'borrowers:view']));
            $this->assertSame($number, $this->getJson($url)->assertOk()->json('data.borrower.contact_number'), $url);
        }
    }

    // ── L4: other branches' loans ──

    public function test_the_co_maker_list_shows_only_loans_the_caller_can_see(): void
    {
        $coMaker = CoMaker::factory()->create(['borrower_id' => $this->loan->borrower_id]);
        $elsewhere = $this->createReleasedLoan();
        $elsewhere->update(['branch_id' => $this->far->id]);
        $this->loan->coMakers()->attach($coMaker->id);
        $elsewhere->coMakers()->attach($coMaker->id);

        $this->actingAs($this->branchOfficer());

        $listed = $this->getJson("/api/borrowers/{$this->loan->borrower_id}/co-makers")->assertOk()->json('data.0.loans');

        $this->assertSame([$this->loan->id], array_column($listed, 'id'));
    }

    public function test_a_collateral_hides_the_account_number_of_a_loan_the_caller_cant_see(): void
    {
        $collateral = Collateral::factory()->create(['borrower_id' => $this->loan->borrower_id]);
        $elsewhere = $this->createReleasedLoan();
        $elsewhere->update(['branch_id' => $this->far->id]);
        foreach ([$this->loan, $elsewhere] as $holder) {
            $holder->collaterals()->attach($collateral->id, ['snapshot_value' => 1000, 'attached_at' => now()]);
        }

        $this->actingAs($this->branchOfficer());

        $active = collect($this->getJson("/api/collaterals/{$collateral->id}")->assertOk()->json('data.active_loans'))->keyBy('id');

        $this->assertSame($this->loan->loan_account_number, $active[$this->loan->id]['loan_account_number']);
        $this->assertArrayHasKey($elsewhere->id, $active->all());
        $this->assertNull($active[$elsewhere->id]['loan_account_number']);
    }

    // ── L5: the member's loan summary ──

    public function test_the_loan_summary_also_needs_loans_view(): void
    {
        $member = $this->loan->borrower_id;

        $this->actingAs($this->userWith(['borrowers:view']));
        $this->getJson("/api/borrowers/{$member}/loan-summary")->assertForbidden();

        $this->actingAs($this->userWith(['borrowers:view', 'loans:view']));
        $this->getJson("/api/borrowers/{$member}/loan-summary")->assertOk();
    }

    private function branchOfficer(): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');

        return $user;
    }

    private function approvedLoan(): Loan
    {
        $loan = Loan::factory()->create([
            'status' => 'approved',
            'branch_id' => $this->branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ]);

        return $loan;
    }
}
