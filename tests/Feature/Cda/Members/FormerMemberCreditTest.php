<?php

namespace Tests\Feature\Cda\Members;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * No new credit to a former member (C1 review, H1 and M1; decision: standard).
 *
 * - Withdrawing counts every loan not yet closed as open: a draft, one for
 *   approval and an approved one, as well as released, ongoing and
 *   defaulted loans.
 * - Creating, editing, restructuring and releasing a loan refuse a withdrawn
 *   or deceased member (422 in words), as principal or co-maker.
 * - Share capital entries and pledge changes refuse a withdrawn member; a
 *   deceased member's stay allowed, so the estate can be settled.
 */
class FormerMemberCreditTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function member(array $attributes = []): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $this->branch->id, ...$attributes]);
    }

    private function end(Borrower $member, string $status): void
    {
        DB::table('borrowers')->where('id', $member->id)->update(['status' => $status, 'membership_ended_on' => '2026-10-01', 'membership_end_reason' => 'Test']);
    }

    public function test_withdrawing_counts_draft_for_approval_and_approved_loans_as_open(): void
    {
        $product = LoanProduct::factory()->create();

        foreach (['draft', 'for_review', 'approved'] as $status) {
            $member = $this->member();
            $loan = Loan::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'status' => $status]);

            $response = $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'withdrawn', 'ended_on' => now()->toDateString(), 'reason' => 'Leaving'])
                ->assertUnprocessable()->assertJsonValidationErrors('status');

            $this->assertStringContainsString("1 loan application not yet released ({$loan->application_number})", $response->json('errors.status.0'), $status);
            $this->assertSame('active', $member->fresh()->status);
        }

        $closed = $this->member();
        Loan::factory()->create(['borrower_id' => $closed->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'status' => 'completed']);
        Loan::factory()->create(['borrower_id' => $closed->id, 'branch_id' => $this->branch->id, 'loan_product_id' => $product->id, 'status' => 'rejected']);
        $this->patchJson("/api/borrowers/{$closed->id}/end-membership", ['status' => 'withdrawn', 'ended_on' => now()->toDateString(), 'reason' => 'Leaving'])->assertOk();
    }

    public function test_a_loan_is_not_created_for_a_former_member_or_with_one_as_co_maker(): void
    {
        $product = LoanProduct::factory()->create();
        $withdrawn = $this->member();
        $deceased = $this->member();
        $current = $this->member();
        $this->end($withdrawn, 'withdrawn');
        $this->end($deceased, 'deceased');
        $body = fn (int $borrowerId, array $coMakers = []) => [
            'borrower_id' => $borrowerId, 'loan_product_id' => $product->id, 'principal_amount' => 10000,
            'start_date' => now()->toDateString(), 'co_maker_ids' => $coMakers,
        ];

        foreach ([$withdrawn, $deceased] as $former) {
            $response = $this->postJson('/api/loans', $body($former->id))->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
            $this->assertStringContainsString('membership ended', $response->json('errors.borrower_id.0'));

            $response = $this->postJson('/api/loans', $body($current->id, [$former->id]))->assertUnprocessable()->assertJsonValidationErrors('co_maker_ids.0');
            $this->assertStringContainsString('membership ended', $response->json('errors')['co_maker_ids.0'][0]);
        }

        $this->assertSame(0, Loan::query()->whereIn('borrower_id', [$withdrawn->id, $deceased->id])->count());
    }

    public function test_a_former_members_loan_is_not_edited_restructured_or_released(): void
    {
        $service = app(LoanService::class);
        $product = LoanProduct::factory()->create(['interest_rate' => 3, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly']);

        $draftOwner = $this->member();
        $draft = $service->createLoan(['borrower_id' => $draftOwner->id, 'loan_product_id' => $product->id, 'principal_amount' => 10000, 'start_date' => now()->toDateString()], $this->admin);
        $this->end($draftOwner, 'deceased');
        $response = $this->putJson("/api/loans/{$draft->id}", ['purpose' => 'Edited'])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->assertStringContainsString('membership ended', $response->json('errors.borrower_id.0'));

        $approvedOwner = $this->member();
        $approved = $service->createLoan(['borrower_id' => $approvedOwner->id, 'loan_product_id' => $product->id, 'principal_amount' => 10000, 'start_date' => now()->toDateString()], $this->admin);
        $service->submitForReview($approved);
        $service->approve($approved->fresh(), $this->admin, 'ok');
        $this->end($approvedOwner, 'deceased');
        $response = $this->patchJson("/api/loans/{$approved->id}/release", ['method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->assertStringContainsString('membership ended', $response->json('errors.borrower_id.0'));
        $this->assertSame('approved', $approved->fresh()->status);

        $released = $this->createReleasedLoan();
        $this->end($released->borrower, 'deceased');
        $this->postJson("/api/loans/{$released->id}/restructure", [
            'borrower_id' => $released->borrower_id, 'loan_product_id' => $released->loan_product_id,
            'principal_amount' => 10000, 'start_date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
    }

    public function test_share_capital_refuses_a_withdrawn_member_and_allows_a_deceased_ones_estate(): void
    {
        $withdrawn = $this->member();
        $deceased = $this->member();
        $this->end($withdrawn, 'withdrawn');
        $this->end($deceased, 'deceased');
        $entry = fn (Borrower $member) => ['borrower_id' => $member->id, 'date' => now()->toDateString(), 'description' => 'Settlement', 'type' => 'debit', 'amount' => 100];

        $response = $this->postJson('/api/share-capital/ledger', $entry($withdrawn))->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->assertStringContainsString('withdrew', $response->json('errors.borrower_id.0'));
        $this->postJson('/api/share-capital/ledger', $entry($deceased))->assertJsonMissingValidationErrors('borrower_id');

        $pledge = $withdrawn->shareCapitalPledge;
        $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 500])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');
        $this->postJson("/api/pledges/{$pledge->id}/entries", ['amount' => 100, 'type' => 'credit', 'date' => now()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('borrower_id');

        $this->putJson("/api/pledges/{$deceased->shareCapitalPledge->id}", ['amount' => 500])->assertJsonMissingValidationErrors('borrower_id');
    }
}
