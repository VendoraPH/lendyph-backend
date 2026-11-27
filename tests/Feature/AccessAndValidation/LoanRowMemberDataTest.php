<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Loan rows and the member's personal data.
 *
 * `manager` and the board roles hold `loans:view` without `borrowers:view`, so
 * they see only who a loan belongs to: the member's id, name and code, and each
 * co-maker's name and relationship. The screens and printables that read a loan
 * row use nothing else (see LoanResource::memberOnRow()).
 */
class LoanRowMemberDataTest extends TestCase
{
    use SetupLendyPH;

    private const PERSONAL_FIELDS = [
        'birthdate', 'civil_status', 'gender', 'address', 'street_address', 'barangay', 'city', 'province',
        'contact_number', 'phone', 'email', 'employer_or_business', 'date_hired', 'monthly_income',
        'spouse_first_name', 'spouse_last_name', 'spouse_contact_number', 'photo_url', 'photo',
    ];

    private const CO_MAKER_PERSONAL_FIELDS = ['address', 'contact_number', 'occupation', 'employer', 'monthly_income'];

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->loan = $this->createReleasedLoan();
        $this->loan->borrower->update(['email' => 'member@example.test', 'contact_number' => '09171234567']);

        $coMaker = CoMaker::factory()->create([
            'borrower_id' => $this->loan->borrower_id,
            'address' => '12 Private St',
            'contact_number' => '09179998888',
            'monthly_income' => 25000,
            'relationship_to_borrower' => 'Sister',
        ]);
        $this->loan->coMakers()->attach($coMaker->id);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    public function test_a_caller_without_borrowers_view_sees_only_the_members_id_name_and_code(): void
    {
        foreach (['manager', 'bod1'] as $role) {
            $this->actingAsRole($role);

            $show = $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->json('data');
            $row = collect($this->getJson('/api/loans')->assertOk()->json('data'))->firstWhere('id', $this->loan->id);

            foreach ([$show, $row] as $payload) {
                $this->assertEqualsCanonicalizing(['id', 'full_name', 'borrower_code'], array_keys($payload['borrower']));
                $this->assertSame($this->loan->borrower->full_name, $payload['borrower']['full_name']);
                $this->assertSame($this->loan->borrower->borrower_code, $payload['borrower']['borrower_code']);
                $this->assertSame($this->loan->borrower->full_name, $payload['borrower_name']);
            }
        }
    }

    public function test_a_caller_without_borrowers_view_sees_a_co_makers_name_but_not_their_details(): void
    {
        $this->actingAsRole('manager');

        $coMaker = $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->json('data.co_makers.0');

        $this->assertNotEmpty($coMaker['full_name']);
        $this->assertSame('Sister', $coMaker['relationship_to_borrower']);

        foreach (self::CO_MAKER_PERSONAL_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $coMaker);
        }
    }

    public function test_a_caller_with_borrowers_view_still_gets_the_whole_member(): void
    {
        $this->actingAsRole('cashier');

        $data = $this->getJson("/api/loans/{$this->loan->id}")->assertOk()->json('data');

        foreach (self::PERSONAL_FIELDS as $field) {
            $this->assertArrayHasKey($field, $data['borrower']);
        }

        $this->assertSame('member@example.test', $data['borrower']['email']);
        $this->assertSame('09179998888', $data['co_makers'][0]['contact_number']);
    }

    /**
     * The release voucher and the amortization schedule print from
     * GET /loans/{id}. They read the name and the code, and nothing else of the
     * member, so both still print for an approver.
     */
    public function test_what_the_loan_printables_read_is_still_sent(): void
    {
        $this->actingAsRole('manager');

        $this->getJson("/api/loans/{$this->loan->id}")
            ->assertOk()
            ->assertJsonPath('data.borrower.full_name', $this->loan->borrower->full_name)
            ->assertJsonPath('data.borrower.borrower_code', $this->loan->borrower->borrower_code)
            ->assertJsonStructure(['data' => ['amortization_schedules', 'deductions', 'net_proceeds']]);
    }
}
