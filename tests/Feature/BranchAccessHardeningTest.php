<?php

namespace Tests\Feature;

use App\Models\AccountingExpense;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\LoanProduct;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\SignedFileLink;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Branch access, decision 5b: the security review's findings on #211.
 * H1-H3, M1-M4 and the Lows, one or more specs each. The route-by-route 404
 * sweep is BranchAccessRouteSweepTest.
 */
class BranchAccessHardeningTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
    }

    // H1 ---------------------------------------------------------------

    public function test_h1_another_branchs_repayment_and_loan_adjustment_answer_404(): void
    {
        $loan = $this->loanIn($this->far);
        $repayment = Repayment::factory()->create(['loan_id' => $loan->id, 'received_by' => $this->admin->id]);
        $adjustment = LoanAdjustment::factory()->create(['loan_id' => $loan->id, 'adjusted_by' => $this->admin->id]);

        $this->actingAs($this->branchUser(['payments:void', 'loan_adjustments:approve']));

        $this->getJson("/api/repayments/{$repayment->id}")->assertNotFound();
        $this->patchJson("/api/repayments/{$repayment->id}/void", ['reason' => 'probe'])->assertNotFound();
        $this->getJson("/api/loan-adjustments/{$adjustment->id}")->assertNotFound();
        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/approve")->assertNotFound();
        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/reject", ['reason' => 'probe'])->assertNotFound();
        $this->patchJson("/api/loan-adjustments/{$adjustment->id}/apply")->assertNotFound();

        $this->assertSame('posted', $repayment->fresh()->status);
        $this->assertSame('pending', $adjustment->fresh()->status);
    }

    public function test_h1_own_branch_repayment_still_reads(): void
    {
        $repayment = Repayment::factory()->create([
            'loan_id' => $this->loanIn($this->branch)->id,
            'received_by' => $this->admin->id,
        ]);

        $this->actingAs($this->branchUser());

        $this->getJson("/api/repayments/{$repayment->id}")->assertOk();
    }

    // H2 ---------------------------------------------------------------

    public function test_h2_another_branchs_co_maker_and_documents_answer_404(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->far->id]);
        $coMaker = CoMaker::factory()->create(['borrower_id' => $borrower->id]);
        $loan = $this->loanIn($this->far);

        $documents = [
            $this->documentFor(Borrower::class, $borrower->id),
            $this->documentFor(CoMaker::class, $coMaker->id),
            $this->documentFor(Loan::class, $loan->id),
        ];

        $this->actingAs($this->branchUser(['borrowers:delete']));

        $this->getJson("/api/co-makers/{$coMaker->id}")->assertNotFound();
        $this->getJson("/api/co-makers/{$coMaker->id}/documents")->assertNotFound();
        $this->getJson("/api/loans/{$loan->id}/documents")->assertNotFound();

        foreach ($documents as $document) {
            $this->getJson("/api/documents/{$document->id}")->assertNotFound();
            $this->deleteJson("/api/documents/{$document->id}")->assertNotFound();
        }

        $this->assertSame(3, Document::whereKey(array_map(fn ($d) => $d->id, $documents))->count());
    }

    public function test_h2_a_signed_file_link_still_works_without_a_signed_in_user(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->far->id]);
        $document = $this->documentFor(Borrower::class, $borrower->id);
        Storage::fake('private');
        Storage::disk('private')->put($document->file_path, 'id-bytes');

        $url = SignedFileLink::to('files.document', ['document' => $document->id]);

        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk();
    }

    // H3 ---------------------------------------------------------------

    public function test_h3_co_makers_must_be_visible_members_on_store_update_and_restructure(): void
    {
        $farMember = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create();
        $draft = $this->loanIn($this->branch, ['borrower_id' => $here->id, 'loan_product_id' => $product->id]);
        $released = $this->createReleasedLoan();

        $this->actingAs($this->branchUser());

        $this->postJson('/api/loans', [
            'borrower_id' => $here->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
            'co_maker_ids' => [$farMember->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('co_maker_ids.0');

        $this->putJson("/api/loans/{$draft->id}", ['co_maker_ids' => [$farMember->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('co_maker_ids.0');

        $this->postJson("/api/loans/{$released->id}/restructure", [
            'borrower_id' => $farMember->id,
            'co_maker_ids' => [$farMember->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['borrower_id', 'co_maker_ids.0']);
    }

    public function test_h3_the_loan_preview_refuses_another_branchs_borrower_and_loans(): void
    {
        $farMember = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        $farLoan = $this->loanIn($this->far);

        $this->actingAs($this->branchUser());

        $this->postJson('/api/loans/preview', ['borrower_id' => $farMember->id])
            ->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->postJson('/api/loans/preview', ['loan_id' => $farLoan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('loan_id');
        $this->postJson('/api/loans/preview', ['source_loan_id' => $farLoan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('source_loan_id');
    }

    // M1 ---------------------------------------------------------------

    public function test_m1_another_branchs_user_answers_404_on_show_update_and_actions(): void
    {
        $elsewhere = User::factory()->inBranches($this->far)->create();
        $elsewhere->assignRole('viewer');

        $this->actingAs($this->branchManager());

        $this->getJson("/api/users/{$elsewhere->id}")->assertNotFound();
        $this->putJson("/api/users/{$elsewhere->id}", ['first_name' => 'Probe'])->assertNotFound();
        $this->patchJson("/api/users/{$elsewhere->id}/deactivate")->assertNotFound();
        $this->postJson("/api/users/{$elsewhere->id}/reset-password")->assertNotFound();

        $this->assertNotSame('Probe', $elsewhere->fresh()->first_name);
        $this->assertSame('active', $elsewhere->fresh()->status);
    }

    public function test_m1_view_all_still_reaches_every_user_and_a_caller_always_reaches_themselves(): void
    {
        $elsewhere = User::factory()->inBranches($this->far)->create();
        $elsewhere->assignRole('viewer');

        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->assignRole('manager');
        $manager->givePermissionTo('users:view');

        $this->actingAs($manager);
        $this->getJson("/api/users/{$elsewhere->id}")->assertOk();

        $nobody = User::factory()->create(['branch_id' => null]);
        $nobody->assignRole('viewer');
        $nobody->givePermissionTo('users:view');

        $this->actingAs($nobody);
        $this->getJson("/api/users/{$nobody->id}")->assertOk();
        $this->getJson("/api/users/{$elsewhere->id}")->assertNotFound();
    }

    public function test_m1_branches_can_only_be_assigned_inside_the_callers_own(): void
    {
        $colleague = User::factory()->inBranches($this->branch)->create();
        $colleague->assignRole('viewer');

        $this->actingAs($this->branchManager());

        $this->postJson('/api/users', [
            'first_name' => 'New', 'last_name' => 'Hire', 'username' => 'new_hire', 'email' => 'new.hire@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'viewer',
            'branch_ids' => [$this->far->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids.0');

        $this->putJson("/api/users/{$colleague->id}", ['branch_ids' => [$this->branch->id, $this->far->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_ids.1');

        $this->assertSame([$this->branch->id], $colleague->branches()->pluck('branches.id')->all());
    }

    public function test_m1_a_caller_without_view_all_cannot_change_their_own_branches(): void
    {
        $second = Branch::factory()->create(['code' => 'SEC']);
        $manager = $this->branchManager([$this->branch, $second]);

        $this->actingAs($manager);

        $this->putJson("/api/users/{$manager->id}", ['branch_ids' => [$this->branch->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_ids' => 'You cannot change your own branches']);
    }

    public function test_m1_a_user_who_also_works_in_another_branch_is_out_of_reach(): void
    {
        $second = Branch::factory()->create(['code' => 'SEC']);
        $bridging = User::factory()->inBranches($this->branch, $this->far)->create();
        $bridging->assignRole('viewer');

        $this->actingAs($this->branchManager([$this->branch, $second]));

        // Visible (they share Main Branch), but not manageable: their reach
        // goes beyond the caller's.
        $this->getJson("/api/users/{$bridging->id}")->assertOk();
        $this->putJson("/api/users/{$bridging->id}", ['branch_ids' => [$this->branch->id, $second->id]])->assertNotFound();
        $this->postJson("/api/users/{$bridging->id}/reset-password", ['password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])
            ->assertNotFound();

        $this->assertEqualsCanonicalizing([$this->branch->id, $this->far->id], $bridging->branches()->pluck('branches.id')->all());
    }

    public function test_m1_a_user_holding_a_permission_the_caller_lacks_is_out_of_reach(): void
    {
        $officer = User::factory()->inBranches($this->branch)->create();
        $officer->assignRole('loan_officer');
        $overseer = User::factory()->inBranches($this->branch)->create();
        $overseer->assignRole('manager');
        $peer = User::factory()->inBranches($this->branch)->create();
        $peer->assignRole('viewer');

        $this->actingAs($this->branchManager());

        $reset = ['password' => 'newpassword1', 'password_confirmation' => 'newpassword1'];

        foreach ([$officer, $overseer] as $target) {
            $this->postJson("/api/users/{$target->id}/reset-password", $reset)->assertNotFound();
            $this->putJson("/api/users/{$target->id}", ['first_name' => 'Probe'])->assertNotFound();
            $this->assertNotSame('Probe', $target->fresh()->first_name);
        }

        // A peer whose permissions and branches sit inside the caller's is
        // still manageable.
        $this->putJson("/api/users/{$peer->id}", ['first_name' => 'Renamed'])->assertOk();
        $this->postJson("/api/users/{$peer->id}/reset-password", $reset)->assertSuccessful();
    }

    public function test_m1_deactivate_and_reactivate_refuse_a_user_beyond_the_callers_reach(): void
    {
        $overseer = User::factory()->inBranches($this->branch)->create();
        $overseer->assignRole('manager');
        $inactiveOverseer = User::factory()->inBranches($this->branch)->create(['status' => 'inactive']);
        $inactiveOverseer->assignRole('manager');
        $bridging = User::factory()->inBranches($this->branch, $this->far)->create();
        $bridging->assignRole('viewer');
        $peer = User::factory()->inBranches($this->branch)->create();
        $peer->assignRole('viewer');

        $caller = $this->branchManager();
        $caller->givePermissionTo('users:delete');
        $this->actingAs($caller);

        $this->patchJson("/api/users/{$overseer->id}/deactivate")->assertNotFound();
        $this->patchJson("/api/users/{$bridging->id}/deactivate")->assertNotFound();
        $this->patchJson("/api/users/{$inactiveOverseer->id}/reactivate")->assertNotFound();

        $this->assertSame('active', $overseer->fresh()->status);
        $this->assertSame('active', $bridging->fresh()->status);
        $this->assertSame('inactive', $inactiveOverseer->fresh()->status);

        // A peer inside the caller's reach is still managed as before.
        $this->patchJson("/api/users/{$peer->id}/deactivate")->assertOk();
        $this->assertSame('inactive', $peer->fresh()->status);
        $this->patchJson("/api/users/{$peer->id}/reactivate")->assertOk();
        $this->assertSame('active', $peer->fresh()->status);
    }

    // M2 ---------------------------------------------------------------

    public function test_m2_auto_credit_status_and_run_cover_only_the_callers_members(): void
    {
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $there = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        ShareCapitalPledge::whereIn('borrower_id', [$here->id, $there->id])->update(['auto_credit' => true, 'amount' => 500]);
        $herePledge = ShareCapitalPledge::where('borrower_id', $here->id)->firstOrFail();
        $therePledge = ShareCapitalPledge::where('borrower_id', $there->id)->firstOrFail();

        $this->actingAs($this->branchUser());

        $active = array_column($this->getJson('/api/auto-credit/status')->assertOk()->json('data.active_members'), 'id');
        $this->assertContains($herePledge->id, $active);
        $this->assertNotContains($therePledge->id, $active);

        $this->postJson('/api/auto-credit/process')->assertCreated();

        $this->assertSame(1, ShareCapitalLedger::where('borrower_id', $here->id)->count());
        $this->assertSame(0, ShareCapitalLedger::where('borrower_id', $there->id)->count());
    }

    public function test_m2_the_last_auto_credit_runs_totals_are_hidden_from_callers_without_view_all(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        ShareCapitalPledge::where('borrower_id', $member->id)->update(['auto_credit' => true, 'amount' => 750]);
        $this->postJson('/api/auto-credit/process')->assertCreated();

        $whole = $this->getJson('/api/auto-credit/status')->assertOk()->json('data.last_run');
        $this->assertNotNull($whole['total_amount']);
        $this->assertNotNull($whole['member_count']);

        $this->actingAs($this->branchUser());
        $scoped = $this->getJson('/api/auto-credit/status')->assertOk()->json('data.last_run');

        $this->assertSame($whole['processed_at'], $scoped['processed_at']);
        $this->assertNull($scoped['total_amount']);
        $this->assertNull($scoped['member_count']);
    }

    public function test_m2_bulk_writes_refuse_another_branchs_pledges_and_members(): void
    {
        $there = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        $pledge = ShareCapitalPledge::where('borrower_id', $there->id)->firstOrFail();

        $this->actingAs($this->branchUser(['borrowers:update']));

        $this->postJson('/api/pledges/bulk-entries', [
            'entries' => [['pledge_id' => $pledge->id, 'amount' => 100, 'type' => 'credit', 'date' => now()->toDateString()]],
        ])->assertUnprocessable()->assertJsonValidationErrors('entries.0.pledge_id');

        $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [$there->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('ids.0');

        $this->assertSame(0, ShareCapitalLedger::where('borrower_id', $there->id)->count());
        $this->assertSame('active', $there->fresh()->status);
    }

    public function test_m2_auto_pay_preview_and_run_cover_only_the_callers_loans(): void
    {
        $here = $this->createReleasedLoan();
        $there = $this->createReleasedLoan();
        $there->borrower->update(['branch_id' => $this->far->id]);
        $there->update(['branch_id' => $this->far->id]);
        Loan::whereKey([$here->id, $there->id])->update(['auto_pay' => true]);

        $range = ['date_from' => now()->toDateString(), 'date_to' => now()->addMonths(2)->toDateString()];

        $this->actingAs($this->branchUser(['auto_pay:process']));

        $preview = $this->getJson('/api/auto-pay/preview?'.http_build_query($range))->assertOk();
        $seen = array_merge(
            array_column($preview->json('data.loans'), 'loan_id'),
            array_column($preview->json('data.skips'), 'loan_id'),
        );
        $this->assertContains($here->id, $seen);
        $this->assertNotContains($there->id, $seen);

        $this->postJson('/api/auto-pay/process', $range)->assertCreated();

        $this->assertSame(0, Repayment::where('loan_id', $there->id)->count());
    }

    public function test_m2_gcash_member_transactions_follow_the_members_branch_and_walk_ins_stay_visible(): void
    {
        $here = GCashTransaction::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'transactor_user_id' => $this->admin->id,
        ]);
        $there = GCashTransaction::factory()->create([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->far->id])->id,
            'transactor_user_id' => $this->admin->id,
            'status' => 'pending',
        ]);
        $walkIn = GCashTransaction::factory()->forNonMember()->create(['transactor_user_id' => $this->admin->id]);

        $this->actingAs($this->branchUser(['gcash:transact']));

        $ids = array_column($this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($here->id, $ids);
        $this->assertContains($walkIn->id, $ids);
        $this->assertNotContains($there->id, $ids);

        $this->patchJson("/api/gcash/transactions/{$there->id}/paid")->assertNotFound();
        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $there->borrower_id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }

    // M3 ---------------------------------------------------------------

    public function test_m3_a_duplicate_in_another_branch_is_reported_without_its_details(): void
    {
        Borrower::factory()->create([
            'branch_id' => $this->far->id,
            'first_name' => 'Rosalinda', 'middle_name' => null, 'last_name' => 'Magbanua',
            'birthdate' => '1980-05-05',
        ]);

        $this->actingAs($this->branchUser());

        $response = $this->postJson('/api/borrowers', [
            'first_name' => 'Rosalinda', 'last_name' => 'Magbanua', 'birthdate' => '1980-05-05',
            'branch_id' => $this->branch->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name' => 'A similar member already exists. Please contact your branch']);

        $this->assertStringNotContainsString('BRW-', json_encode($response->json('errors.first_name')));
    }

    public function test_m3_a_duplicate_in_the_callers_branch_still_names_the_member(): void
    {
        $match = Borrower::factory()->create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Rosalinda', 'middle_name' => null, 'last_name' => 'Magbanua',
            'birthdate' => '1980-05-05',
        ]);

        $this->actingAs($this->branchUser());

        $this->postJson('/api/borrowers', [
            'first_name' => 'Rosalinda', 'last_name' => 'Magbanua', 'birthdate' => '1980-05-05',
            'branch_id' => $this->branch->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name' => $match->borrower_code]);
    }

    // M4 ---------------------------------------------------------------

    public function test_m4_a_members_branch_must_be_one_of_the_callers(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchUser());

        $this->postJson('/api/borrowers', ['first_name' => 'Ana', 'last_name' => 'Reyes', 'branch_id' => $this->far->id])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->far->id])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    public function test_m4_a_member_with_a_loan_application_in_progress_cannot_change_branch(): void
    {
        $second = Branch::factory()->create(['code' => 'SEC']);

        foreach (['draft', 'for_review', 'approved'] as $status) {
            $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
            $this->loanIn($this->branch, ['borrower_id' => $member->id, 'status' => $status]);

            $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $second->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['branch_id' => "Close or move this member's open loans first."]);
        }

        $closed = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->loanIn($this->branch, ['borrower_id' => $closed->id, 'status' => 'rejected']);
        $this->putJson("/api/borrowers/{$closed->id}", ['branch_id' => $second->id])->assertOk();
    }

    public function test_m4_a_member_with_collectible_loans_cannot_change_branch(): void
    {
        $second = Branch::factory()->create(['code' => 'SEC']);
        $loan = $this->createReleasedLoan();

        $this->actingAs($this->admin);

        $this->putJson("/api/borrowers/{$loan->borrower_id}", ['branch_id' => $second->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => "Close or move this member's open loans first."]);

        $idle = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->putJson("/api/borrowers/{$idle->id}", ['branch_id' => $second->id])->assertOk();
        $this->assertSame($second->id, (int) $idle->fresh()->branch_id);
    }

    // L1 ---------------------------------------------------------------

    public function test_l1_a_missing_id_and_another_branchs_id_get_the_same_answer(): void
    {
        $farMember = Borrower::factory()->create(['branch_id' => $this->far->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create();

        $this->actingAs($this->branchUser());

        $payload = fn (int $borrowerId) => [
            'borrower_id' => $borrowerId,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
        ];

        $foreign = $this->postJson('/api/loans', $payload($farMember->id))->assertUnprocessable()->json('errors.borrower_id');
        $missing = $this->postJson('/api/loans', $payload(99999999))->assertUnprocessable()->json('errors.borrower_id');

        $this->assertSame($missing, $foreign);
    }

    // L2 ---------------------------------------------------------------

    public function test_l2_journal_and_expense_branches_stay_inside_the_callers_and_default_to_their_primary(): void
    {
        $this->seedChartOfAccounts();

        $this->actingAs($this->branchUser(['journals:create', 'journals:view', 'expenses:create', 'expenses:view']));

        $lines = [
            ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
            ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
        ];

        $this->postJson('/api/accounting/journals', ['date' => '2026-09-15', 'description' => 'Far', 'branch_id' => $this->far->id, 'lines' => $lines])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $journalId = $this->postJson('/api/accounting/journals', ['date' => '2026-09-15', 'description' => 'Defaulted', 'lines' => $lines])
            ->assertCreated()->json('data.id');
        $this->assertSame($this->branch->id, (int) AccountingJournal::findOrFail($journalId)->branch_id);

        $expense = [
            'date' => '2026-09-18', 'payee' => 'Meralco', 'expense_account_id' => $this->account('5030'),
            'amount' => 150000, 'payment_account_id' => $this->account('1010'),
        ];

        $this->postJson('/api/accounting/expenses', $expense + ['branch_id' => $this->far->id])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $expenseId = $this->postJson('/api/accounting/expenses', $expense)->assertCreated()->json('data.id');
        $this->assertSame($this->branch->id, (int) AccountingExpense::findOrFail($expenseId)->branch_id);
    }

    public function test_l2_view_all_keeps_writing_organisation_wide_rows(): void
    {
        $this->seedChartOfAccounts();

        $id = $this->postJson('/api/accounting/journals', [
            'date' => '2026-09-15', 'description' => 'Head office',
            'lines' => [
                ['account_id' => $this->account('5030'), 'debit' => 10000, 'credit' => 0],
                ['account_id' => $this->account('1010'), 'debit' => 0, 'credit' => 10000],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertNull(AccountingJournal::findOrFail($id)->branch_id);
    }

    // L4 ---------------------------------------------------------------

    public function test_l4_only_real_board_role_keys_get_view_all(): void
    {
        $board = ['bod_chairwoman', 'bod8', 'bod_treasurer'];
        $notBoard = ['bodega_staff', 'BOD_chair', 'bod', 'bod8x', 'it_head'];

        foreach (array_merge($board, $notBoard) as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web']);
        }

        (require database_path('migrations/2026_11_02_210000_add_branches_view_all_permission.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($board as $name) {
            $this->assertTrue(Role::findByName($name, 'web')->hasPermissionTo(BranchAccess::VIEW_ALL), "{$name} should hold view_all.");
        }

        foreach ($notBoard as $name) {
            $this->assertFalse(Role::findByName($name, 'web')->hasPermissionTo(BranchAccess::VIEW_ALL), "{$name} must not hold view_all.");
        }
    }

    // L5 ---------------------------------------------------------------

    public function test_l5_the_account_officer_must_be_a_user_the_caller_can_see(): void
    {
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $product = LoanProduct::factory()->create();
        $farOfficer = User::factory()->inBranches($this->far)->create();
        $farOfficer->assignRole('loan_officer');
        $draft = $this->loanIn($this->branch, ['borrower_id' => $here->id]);

        $this->actingAs($this->branchUser());

        $this->postJson('/api/loans', [
            'borrower_id' => $here->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 10000,
            'start_date' => now()->toDateString(),
            'account_officer_id' => $farOfficer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_officer_id');

        $this->patchJson("/api/loans/{$draft->id}/account-officer", ['account_officer_id' => $farOfficer->id])
            ->assertUnprocessable()->assertJsonValidationErrors('account_officer_id');
    }

    // -----------------------------------------------------------------

    /**
     * A loan officer in Main Branch only, plus any extra permissions a spec
     * needs, and never `branches:view_all`.
     *
     * @param  list<string>  $extra
     */
    private function branchUser(array $extra = []): User
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole('loan_officer');

        if ($extra !== []) {
            $user->givePermissionTo($extra);
        }

        return $user;
    }

    /**
     * A user manager without `branches:view_all`: every `users:*` permission,
     * assigned to the given branches.
     *
     * @param  list<Branch>|null  $branches
     */
    private function branchManager(?array $branches = null): User
    {
        $user = User::factory()->inBranches(...($branches ?? [$this->branch]))->create();
        $user->assignRole('viewer');
        $user->givePermissionTo(['users:view', 'users:create', 'users:update', 'users:reset_password']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function loanIn(Branch $branch, array $attributes = []): Loan
    {
        return Loan::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'borrower_id' => Borrower::factory()->create(['branch_id' => $branch->id]),
            'loan_product_id' => LoanProduct::factory(),
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function documentFor(string $type, int $id): Document
    {
        return Document::create([
            'documentable_type' => $type,
            'documentable_id' => $id,
            'type' => 'valid_id',
            'file_path' => "documents/valid_id/probe/{$id}/id.jpg",
            'original_filename' => 'id.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 8,
        ]);
    }
}
