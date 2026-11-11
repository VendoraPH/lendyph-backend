<?php

namespace Tests\Feature\Portal\Read;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\SavingsProductRate;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\LoanService;
use App\Services\Savings\SavingsLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Portal\PortalTestHelpers;

/**
 * Records for the portal read tests (slice P2), made the way staff make them:
 * loans through LoanService (create, submit, approve, release), payments
 * through the staff API, savings deposits through the ledger and the books.
 * Every figure the portal shows is then compared with the staff endpoint for
 * the same record.
 */
trait PortalReadFixtures
{
    use PortalTestHelpers;

    private ?SavingsProduct $savingsProduct = null;

    /** A released loan of the member's, starting `$monthsAgo` months back so some periods are late. */
    protected function releasedLoanFor(PortalUser $member, int $monthsAgo = 3, float $principal = 60000): Loan
    {
        $product = LoanProduct::factory()->create([
            'name' => 'Salary Loan',
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'penalty_rate' => 2.0,
            'grace_period_days' => 3,
        ]);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => $member->borrower_id,
            'loan_product_id' => $product->id,
            'principal_amount' => $principal,
            'start_date' => now()->subMonths($monthsAgo)->toDateString(),
        ], $this->admin);

        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for the portal tests');
        $loans->release($loan, $this->admin);

        return $loan->fresh();
    }

    /** A draft application of the member's (never shown in the portal). */
    protected function draftLoanFor(PortalUser $member): Loan
    {
        return app(LoanService::class)->createLoan([
            'borrower_id' => $member->borrower_id,
            'loan_product_id' => LoanProduct::factory()->create()->id,
            'principal_amount' => 20000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
    }

    /** The member as co-maker on another member's released loan. */
    protected function coMakerLoan(PortalUser $member, PortalUser $other): Loan
    {
        $loan = $this->releasedLoanFor($other);
        $coMaker = CoMaker::create([
            'borrower_id' => $other->borrower_id,
            'member_borrower_id' => $member->borrower_id,
            'first_name' => $member->borrower->first_name,
            'last_name' => $member->borrower->last_name,
            'relationship_to_borrower' => 'friend',
            'status' => 'active',
        ]);
        $loan->coMakers()->attach($coMaker->id);

        return $loan;
    }

    /** A payment recorded by staff through the API, today, in cash. */
    protected function pay(Loan $loan, float $amount): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $response = $this->actingAs($this->admin, 'web')->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        return $response;
    }

    /** A staff GET as the super admin. */
    protected function staffGet(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultHeaders = [];
        $response = $this->actingAs($this->admin, 'web')->getJson($uri);
        $this->assertSame(200, $response->status(), $uri.': '.$response->getContent());

        return $response;
    }

    protected function savingsBooks(): SavingsProduct
    {
        if ($this->savingsProduct !== null) {
            return $this->savingsProduct;
        }

        app(ChartOfAccountsSeeder::class)->seed($this->admin->id, 'cooperative');
        $this->savingsProduct = SavingsProduct::factory()->create([
            'name' => 'Regular Savings',
            'minimum_opening_balance' => '500.00',
            'maintaining_balance' => '500.00',
        ]);
        SavingsProductRate::factory()->create(['product_id' => $this->savingsProduct->id, 'annual_rate' => '3.6500', 'effective_from' => '2026-01-01']);

        return $this->savingsProduct;
    }

    /** An open savings account of the member's holding `$centavos`, deposited today. */
    protected function savingsFor(PortalUser $member, int $centavos = 0, array $overrides = []): SavingsAccount
    {
        $product = $this->savingsBooks();
        $account = SavingsAccount::factory()->create([
            'borrower_id' => $member->borrower_id,
            'product_id' => $product->id,
            'branch_id' => $member->borrower->branch_id,
            'opened_on' => now()->subDays(20)->toDateString(),
            'last_member_activity_on' => now()->subDays(20)->toDateString(),
            ...$overrides,
        ]);

        if ($centavos > 0) {
            $this->deposit($account, $centavos);
        }

        return $account->refresh();
    }

    protected function deposit(SavingsAccount $account, int $centavos): void
    {
        DB::transaction(function () use ($account, $centavos): void {
            $row = app(SavingsLedger::class)->credit($account, 'deposit', $centavos, ['method' => 'cash']);
            app(AutomaticPoster::class)->savingsTransaction($row, $this->admin->id);
        });
    }

    protected function timeDepositFor(PortalUser $member, array $overrides = []): TimeDeposit
    {
        return TimeDeposit::factory()->create([
            'borrower_id' => $member->borrower_id,
            'branch_id' => $member->borrower->branch_id,
            ...$overrides,
        ]);
    }

    protected function shareCapital(PortalUser $member, string $date, string $credit, string $debit = '0.00', string $description = 'Share capital contribution'): ShareCapitalLedger
    {
        return ShareCapitalLedger::create([
            'borrower_id' => $member->borrower_id,
            'date' => $date,
            'description' => $description,
            'reference' => 'SC-'.$date.'-'.random_int(1000, 9999),
            'credit' => $credit,
            'debit' => $debit,
            'method' => 'cash',
            'created_by' => $this->admin->id,
        ]);
    }

    /** A stored member document, shared with the member unless `$shared` is false. */
    protected function memberDocument(Borrower|Loan|CoMaker $owner, array $attributes = [], bool $shared = true): Document
    {
        $document = Document::create([
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'type' => 'other',
            'label' => 'Membership Certificate',
            'file_path' => 'documents/other/portal-test/'.uniqid().'.pdf',
            'original_filename' => 'membership.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            ...$attributes,
        ]);

        if ($shared) {
            $document->forceFill(['portal_shared_at' => now(), 'portal_shared_by' => $this->admin->id])->save();
        }

        return $document->refresh();
    }
}
