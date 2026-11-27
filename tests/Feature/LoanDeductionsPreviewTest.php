<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\LoanService;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans/{loan}/deductions-preview: the deductions, total and net proceeds
 * a release of this application would produce, for anyone who can see the loan.
 *
 * At draft and under approval the stored Net Proceeds leave out catalog fees,
 * so the approval page read ₱19,400.00 on a ₱20,000.00 loan that the form and
 * the release put at ₱18,600.00.
 */
class LoanDeductionsPreviewTest extends TestCase
{
    use SetupLendyPH;

    private LoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 3,
            'service_fee' => 0,
            'notarial_fee' => 0,
        ]);

        Fee::create([
            'name' => 'Documentary Fee',
            'type' => 'fixed',
            'value' => 800,
            'applicable_product_ids' => [$this->product->id],
        ]);
    }

    public function test_a_draft_shows_the_catalog_fees_the_stored_figures_leave_out(): void
    {
        $loan = $this->draftLoan();

        $this->assertSame('19400.00', $loan->net_proceeds);

        $data = $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk()->json('data');

        $this->assertSame('1400.00', $data['total_deductions']);
        $this->assertSame('18600.00', $data['net_proceeds']);
        $this->assertTrue($data['provisional']);
        $this->assertSame(
            ['Processing Fee', 'Documentary Fee'],
            array_column($data['deductions'], 'name'),
        );
    }

    public function test_it_reads_as_provisional_until_the_loan_is_approved(): void
    {
        $loan = $this->draftLoan();
        $this->assertTrue($this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk()->json('data.provisional'));

        app(LoanService::class)->submitForReview($loan);
        $this->assertSame('for_review', $loan->fresh()->status);
        $this->assertTrue($this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk()->json('data.provisional'));

        app(LoanService::class)->approve($loan->fresh(), $this->admin, 'ok');
        $this->assertFalse($this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk()->json('data.provisional'));
    }

    public function test_it_quotes_exactly_what_the_release_preview_and_the_release_do(): void
    {
        $loan = $this->draftLoan();
        app(LoanService::class)->submitForReview($loan);
        app(LoanService::class)->approve($loan->fresh(), $this->admin, 'ok');

        $preview = $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk()->json('data');
        $release = $this->getJson("/api/loans/{$loan->id}/release-preview")->assertOk()->json('data');

        $this->assertSame($release['deductions'], $preview['deductions']);
        $this->assertSame($release['total_deductions'], $preview['total_deductions']);
        $this->assertSame($release['net_proceeds'], $preview['net_proceeds']);

        $released = app(LoanService::class)->release($loan->fresh(), $this->admin);

        $this->assertSame($preview['net_proceeds'], $released->fresh()->net_proceeds);
        $this->assertSame($preview['total_deductions'], $released->fresh()->total_deductions);
    }

    public function test_it_carries_only_the_agreed_keys_and_no_fee_fingerprint(): void
    {
        $data = $this->getJson("/api/loans/{$this->draftLoan()->id}/deductions-preview")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(
            ['deductions', 'total_deductions', 'net_proceeds', 'provisional'],
            array_keys($data),
        );
    }

    public function test_it_writes_nothing(): void
    {
        $loan = $this->draftLoan();
        $before = $loan->fresh()->getAttributes();

        $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk();

        $this->assertEquals($before, $loan->fresh()->getAttributes());
    }

    public function test_loans_view_is_enough_and_release_preview_still_needs_loans_release(): void
    {
        $loan = $this->draftLoan();
        app(LoanService::class)->submitForReview($loan);
        app(LoanService::class)->approve($loan->fresh(), $this->admin, 'ok');

        $this->actingAs($this->userWith(['loans:view']));

        $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertOk();
        $this->getJson("/api/loans/{$loan->id}/release-preview")->assertForbidden();
    }

    public function test_it_needs_loans_view(): void
    {
        $loan = $this->draftLoan();

        $this->actingAs($this->userWith([]));

        $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertForbidden();
    }

    public function test_another_branchs_loan_answers_404(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $loan = $this->draftLoan();
        $loan->update(['branch_id' => $far->id]);

        $this->actingAs($this->userWith(['loans:view']));

        $this->getJson("/api/loans/{$loan->id}/deductions-preview")->assertNotFound();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function closedStatuses(): array
    {
        return [
            'rejected' => ['rejected'],
            'void' => ['void'],
            'released' => ['released'],
            'ongoing' => ['ongoing'],
            'completed' => ['completed'],
            'defaulted' => ['defaulted'],
            'restructured' => ['restructured'],
        ];
    }

    #[DataProvider('closedStatuses')]
    public function test_any_other_status_answers_422(string $status): void
    {
        $loan = Loan::factory()->create([
            'status' => $status,
            'branch_id' => $this->branch->id,
            'loan_product_id' => $this->product->id,
        ]);

        $this->getJson("/api/loans/{$loan->id}/deductions-preview")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_fees_above_the_principal_answer_the_release_previews_own_422(): void
    {
        Fee::create([
            'name' => 'Oversized Fee',
            'type' => 'fixed',
            'value' => 50000,
            'applicable_product_ids' => [$this->product->id],
        ]);

        $this->getJson("/api/loans/{$this->draftLoan()->id}/deductions-preview")
            ->assertStatus(422)
            ->assertJsonValidationErrors('fees');
    }

    private function draftLoan(): Loan
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => $borrower->id,
            'loan_product_id' => $this->product->id,
            'principal_amount' => 20000,
            'start_date' => now()->toDateString(),
        ], $this->admin)->fresh();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->inBranches($this->branch)->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findByName($permission));
        }

        return $user;
    }
}
