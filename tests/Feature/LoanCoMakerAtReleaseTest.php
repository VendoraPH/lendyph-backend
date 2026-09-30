<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /api/loans/{loan}/co-makers — the release dialog's "Add Co-Maker".
 *
 * That button used to call POST /borrowers/{id}/co-makers, which created a
 * co-maker on the BORROWER and never linked it to the loan, so the person
 * named at the counter was not on the loan at all. Owner decision: whoever
 * holds `loans:release` may add and link co-makers while the loan is awaiting
 * release (`approved`); once it is released, co-makers cannot be added
 * through the app.
 *
 * Every link also records who made it (`co_maker_loan.added_by`) and when (the
 * pivot's `created_at`), whichever write made it — so the second half of this
 * file pins `added_by` on POST /loans, restructure and update as well.
 */
class LoanCoMakerAtReleaseTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * A fresh loan walked forward to $status through the real service path,
     * as createReleasedLoan() does.
     */
    private function loanAt(string $status): Loan
    {
        $loanService = app(LoanService::class);

        $loan = $loanService->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);

        if ($status !== 'draft') {
            $loanService->submitForReview($loan);
        }

        if ($status === 'approved') {
            $loanService->approve($loan, $this->admin, 'Approved for testing');
        }

        return $loan->fresh();
    }

    private function userWithRole(string $role): User
    {
        return tap(
            User::factory()->create(['branch_id' => $this->branch->id]),
            fn (User $user) => $user->assignRole(Role::where('name', $role)->firstOrFail()),
        );
    }

    private function member(): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * The `added_by` recorded on the link between $loan and $coMakerId.
     */
    private function addedBy(Loan $loan, int $coMakerId): ?int
    {
        return DB::table('co_maker_loan')
            ->where('loan_id', $loan->id)
            ->where('co_maker_id', $coMakerId)
            ->value('added_by');
    }

    // ---------------------------------------------------------------------
    // POST /loans/{loan}/co-makers
    // ---------------------------------------------------------------------

    public function test_it_creates_and_links_a_new_co_maker_on_an_approved_loan(): void
    {
        $loan = $this->loanAt('approved');

        $response = $this->postJson("/api/loans/{$loan->id}/co-makers", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'contact_number' => '09181234567',
            'relationship_to_borrower' => 'Spouse',
        ])->assertCreated()
            ->assertJsonPath('message', 'Co-maker added to the loan.')
            ->assertJsonPath('data.first_name', 'Maria')
            ->assertJsonPath('data.last_name', 'Santos')
            ->assertJsonPath('data.borrower_id', $loan->borrower_id)
            ->assertJsonPath('data.added_by', $this->admin->id);

        $coMakerId = $response->json('data.id');
        $this->assertNotNull($response->json('data.added_at'));
        $this->assertNotNull($response->json('data.co_maker_code'));

        // On the loan, with who added it and when.
        $onLoan = collect($this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.co_makers'));
        $this->assertSame([$coMakerId], $onLoan->pluck('id')->all());
        $this->assertSame($this->admin->id, $onLoan->first()['added_by']);
        $this->assertNotNull($onLoan->first()['added_at']);

        // And on the borrower, like any Co-makers-tab entry — where the pivot
        // fields do not appear, because there is no one loan to speak of.
        $this->getJson("/api/borrowers/{$loan->borrower_id}/co-makers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $coMakerId)
            ->assertJsonMissingPath('data.0.added_by')
            ->assertJsonMissingPath('data.0.added_at');

        $this->assertSame($this->admin->id, $this->addedBy($loan, $coMakerId));
        $this->assertNotNull(
            DB::table('co_maker_loan')->where('loan_id', $loan->id)->where('co_maker_id', $coMakerId)->value('created_at'),
        );
    }

    public function test_it_links_an_existing_co_maker_of_the_borrower(): void
    {
        $loan = $this->loanAt('approved');
        $existing = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);
        $coMakersBefore = CoMaker::count();

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $existing->id])
            ->assertCreated()
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.added_by', $this->admin->id);

        $this->assertSame([$existing->id], $loan->coMakers()->pluck('co_makers.id')->all());
        $this->assertSame($this->admin->id, $this->addedBy($loan, $existing->id));
        $this->assertSame($coMakersBefore, CoMaker::count(), 'linking an existing co-maker must not create another');
    }

    public function test_it_refuses_a_released_loan_and_changes_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $existing = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);
        $coMakersBefore = CoMaker::count();

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status'])
            ->assertJsonPath('errors.status.0', 'Co-makers can only be added while the loan is awaiting release.');

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $existing->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(0, $loan->coMakers()->count());
        $this->assertSame($coMakersBefore, CoMaker::count(), 'a refused request must not leave a co-maker on the borrower');
        $this->assertFalse(AuditLog::where('action', 'co_maker_added')->exists());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statusesBeforeApproval(): array
    {
        return [
            'draft' => ['draft'],
            'for_review' => ['for_review'],
        ];
    }

    #[DataProvider('statusesBeforeApproval')]
    public function test_it_refuses_a_loan_that_is_not_yet_approved(string $status): void
    {
        $loan = $this->loanAt($status);
        $this->assertSame($status, $loan->status);
        $coMakersBefore = CoMaker::count();

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(0, $loan->coMakers()->count());
        $this->assertSame($coMakersBefore, CoMaker::count());
    }

    public function test_it_forbids_a_role_without_loans_release_even_with_borrowers_create(): void
    {
        $loan = $this->loanAt('approved');

        // super_admin short-circuits the Gate, so this needs a lesser role: one
        // that could create the co-maker through the Co-makers tab, but cannot
        // release the loan.
        $role = Role::create(['name' => 'spec:no-release', 'guard_name' => 'web']);
        $role->syncPermissions(['loans:view', 'borrowers:view', 'borrowers:create']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        $this->actingAs($user);

        $this->assertTrue($user->can('borrowers:create'));
        $this->assertFalse($user->can('loans:release'));

        $coMakersBefore = CoMaker::count();

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertForbidden();

        $this->assertSame(0, $loan->coMakers()->count());
        $this->assertSame($coMakersBefore, CoMaker::count());
    }

    public function test_a_cashier_who_releases_loans_can_add_one(): void
    {
        $loan = $this->loanAt('approved');
        $cashier = $this->userWithRole('cashier');
        $this->actingAs($cashier);

        // The cashier cannot create co-makers anywhere else; the release
        // dialog is the one place they can.
        $this->assertFalse($cashier->can('borrowers:create'));

        $coMakerId = $this->postJson("/api/loans/{$loan->id}/co-makers", ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertCreated()
            ->assertJsonPath('data.added_by', $cashier->id)
            ->json('data.id');

        $this->assertSame($cashier->id, $this->addedBy($loan, $coMakerId));
    }

    public function test_it_refuses_a_co_maker_of_a_different_borrower(): void
    {
        $loan = $this->loanAt('approved');
        $someoneElses = CoMaker::factory()->create(['borrower_id' => $this->member()->id]);

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $someoneElses->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['co_maker_id']);

        $this->assertSame(0, $loan->coMakers()->count());
    }

    public function test_it_refuses_a_co_maker_already_on_the_loan(): void
    {
        $loan = $this->loanAt('approved');
        $existing = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $existing->id])->assertCreated();

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $existing->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['co_maker_id'])
            ->assertJsonPath('errors.co_maker_id.0', 'This co-maker is already on the loan.');

        $this->assertSame(1, $loan->coMakers()->count());
        $this->assertSame(1, AuditLog::where('action', 'co_maker_added')->count());
    }

    public function test_it_refuses_an_inactive_co_maker(): void
    {
        // Linking makes the person jointly liable, so a co-maker someone has
        // deactivated is refused rather than quietly brought back onto a loan.
        $loan = $this->loanAt('approved');
        $inactive = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id, 'status' => 'inactive']);

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['co_maker_id'])
            ->assertJsonPath(
                'errors.co_maker_id.0',
                "This co-maker is inactive. Reactivate them on the borrower's Co-makers tab before adding them to a loan.",
            );

        $this->assertSame(0, $loan->coMakers()->count());
        $this->assertSame('inactive', $inactive->fresh()->status);
        $this->assertFalse(AuditLog::where('action', 'co_maker_added')->exists());

        // Reactivated, the same co-maker links normally.
        $inactive->update(['status' => 'active']);

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $inactive->id])->assertCreated();
        $this->assertSame([$inactive->id], $loan->coMakers()->pluck('co_makers.id')->all());
    }

    public function test_it_refuses_an_existing_co_maker_and_new_details_in_one_request(): void
    {
        $loan = $this->loanAt('approved');
        $existing = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);
        $coMakersBefore = CoMaker::count();

        $this->postJson("/api/loans/{$loan->id}/co-makers", [
            'co_maker_id' => $existing->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['co_maker_id']);

        $this->assertSame(0, $loan->coMakers()->count());
        $this->assertSame($coMakersBefore, CoMaker::count());
    }

    public function test_a_new_co_maker_needs_a_first_and_last_name(): void
    {
        $loan = $this->loanAt('approved');

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['contact_number' => '09181234567'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name']);

        $this->assertSame(0, $loan->coMakers()->count());
    }

    public function test_it_writes_an_audit_entry_against_the_loan(): void
    {
        $loan = $this->loanAt('approved');

        $coMakerId = $this->postJson("/api/loans/{$loan->id}/co-makers", ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertCreated()
            ->json('data.id');
        $coMaker = CoMaker::findOrFail($coMakerId);

        $audit = AuditLog::where('action', 'co_maker_added')->sole();

        $this->assertSame(Loan::class, $audit->auditable_type);
        $this->assertSame($loan->id, $audit->auditable_id);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($coMaker->id, $audit->new_values['co_maker_id']);
        $this->assertSame($coMaker->co_maker_code, $audit->new_values['co_maker_code']);
        $this->assertSame('Maria Santos', $audit->new_values['full_name']);
        $this->assertTrue($audit->new_values['co_maker_created']);
    }

    public function test_the_audit_entry_says_when_an_existing_co_maker_was_linked(): void
    {
        $loan = $this->loanAt('approved');
        $existing = CoMaker::factory()->create(['borrower_id' => $loan->borrower_id]);

        $this->postJson("/api/loans/{$loan->id}/co-makers", ['co_maker_id' => $existing->id])->assertCreated();

        $audit = AuditLog::where('action', 'co_maker_added')->sole();

        $this->assertSame($existing->id, $audit->new_values['co_maker_id']);
        $this->assertFalse($audit->new_values['co_maker_created']);
    }

    // ---------------------------------------------------------------------
    // added_by on every other write that links a co-maker
    // ---------------------------------------------------------------------

    public function test_create_loan_records_who_added_each_co_maker(): void
    {
        $response = $this->postJson('/api/loans', [
            'borrower_id' => $this->member()->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$this->member()->id, $this->member()->id],
        ])->assertCreated()
            ->assertJsonPath('data.co_makers.0.added_by', $this->admin->id)
            ->assertJsonPath('data.co_makers.1.added_by', $this->admin->id);

        $this->assertNotNull($response->json('data.co_makers.0.added_at'));

        $loan = Loan::findOrFail($response->json('data.id'));
        $this->assertSame(
            [$this->admin->id, $this->admin->id],
            DB::table('co_maker_loan')->where('loan_id', $loan->id)->pluck('added_by')->all(),
        );
    }

    public function test_restructure_records_the_restructurer_on_inherited_co_makers(): void
    {
        $source = $this->createReleasedLoan();
        $coMaker = CoMaker::factory()->create(['borrower_id' => $source->borrower_id]);
        // A link from before `added_by` existed.
        $source->coMakers()->attach($coMaker->id);

        $newLoanId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800.00,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $newLoan = Loan::findOrFail($newLoanId);
        $this->assertSame([$coMaker->id], $newLoan->coMakers()->pluck('co_makers.id')->all());
        $this->assertSame($this->admin->id, $this->addedBy($newLoan, $coMaker->id));
        // The source's own link is not rewritten by the copy.
        $this->assertNull($this->addedBy($source, $coMaker->id));
    }

    public function test_restructure_records_the_restructurer_on_chosen_co_makers(): void
    {
        $source = $this->createReleasedLoan();
        $member = $this->member();

        $newLoanId = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => 70800.00,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$member->id],
        ])->assertCreated()->json('data.id');

        $newLoan = Loan::findOrFail($newLoanId);
        $coMaker = CoMaker::where('member_borrower_id', $member->id)->sole();
        $this->assertSame($this->admin->id, $this->addedBy($newLoan, $coMaker->id));
    }

    public function test_update_stamps_new_links_and_leaves_existing_ones_alone(): void
    {
        $first = $this->member();
        $loanId = $this->postJson('/api/loans', [
            'borrower_id' => $this->member()->id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$first->id],
        ])->assertCreated()->json('data.id');
        $loan = Loan::findOrFail($loanId);
        $firstRecord = CoMaker::where('member_borrower_id', $first->id)->sole();
        $firstAddedAt = DB::table('co_maker_loan')->where('co_maker_id', $firstRecord->id)->value('created_at');

        // A different person edits the loan and adds a second co-maker,
        // re-sending the first.
        $editor = $this->userWithRole('admin');
        $this->actingAs($editor);
        $this->travel(1)->hours();

        $second = $this->member();
        $this->patchJson("/api/loans/{$loan->id}", ['co_maker_ids' => [$first->id, $second->id]])->assertOk();
        $secondRecord = CoMaker::where('member_borrower_id', $second->id)->sole();

        $this->assertSame($this->admin->id, $this->addedBy($loan, $firstRecord->id), 'an existing link keeps who added it');
        $this->assertSame($firstAddedAt, DB::table('co_maker_loan')->where('co_maker_id', $firstRecord->id)->value('created_at'));
        $this->assertSame($editor->id, $this->addedBy($loan, $secondRecord->id));

        $coMakers = collect($this->getJson("/api/loans/{$loan->id}")->assertOk()->json('data.co_makers'))->keyBy('id');
        $this->assertSame($this->admin->id, $coMakers[$firstRecord->id]['added_by']);
        $this->assertSame($editor->id, $coMakers[$secondRecord->id]['added_by']);
    }
}
