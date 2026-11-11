<?php

namespace Tests\Feature\Portal\Notices;

use App\Models\Borrower;
use App\Models\LoanProduct;
use App\Models\PortalUser;
use App\Models\SavingsProduct;
use App\Models\ShareCapitalLedger;
use App\Services\LoanService;
use App\Services\Portal\PortalNotices;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\Portal\Requests\PortalRequestsTestHelpers;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The member's money notices (portal design §7): savings deposits,
 * withdrawals and interest, share capital credits, and loan releases and
 * payments, each written after commit as an in-app notice with the server's
 * ₱ amount, only to a member with an active portal account. A notice that
 * fails is logged and never fails the money write.
 */
class MoneyNoticesTest extends TestCase
{
    use PortalRequestsTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPortalRequests();
    }

    /** @return list<array{category: string, title: string, body: string}> */
    private function notices(PortalUser $portalUser): array
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $portalUser->getMorphClass())
            ->where('notifiable_id', $portalUser->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (DatabaseNotification $n) => ['category' => $n->data['category'], 'title' => $n->data['title'], 'body' => $n->data['body']])
            ->all();
    }

    public function test_a_savings_deposit_tells_the_member_with_the_amount_and_no_internal_id(): void
    {
        $account = $this->account();
        $this->fund($account, 150_050);

        $notices = $this->notices($this->portalUser);
        $this->assertCount(1, $notices);
        $this->assertSame(['savings', 'Deposit posted'], [$notices[0]['category'], $notices[0]['title']]);
        $this->assertStringContainsString('₱1,500.50', $notices[0]['body']);
        $this->assertStringContainsString($account->account_number, $notices[0]['body']);
    }

    public function test_share_capital_and_a_loan_payment_tell_the_member(): void
    {
        ShareCapitalLedger::create([
            'borrower_id' => $this->member->id, 'date' => '2026-11-10', 'description' => 'Contribution',
            'reference' => 'SC-20261110-000001', 'debit' => 0, 'credit' => '250.00', 'created_by' => $this->admin->id,
        ]);
        $this->assertSame('Share capital credited', $this->notices($this->portalUser)[0]['title']);
        $this->assertStringContainsString('₱250.00', $this->notices($this->portalUser)[0]['body']);

        $loans = app(LoanService::class);
        $loan = $loans->createLoan([
            'borrower_id' => $this->member->id,
            'loan_product_id' => LoanProduct::factory()->create(['interest_rate' => 3.0, 'interest_method' => 'straight', 'term' => 6, 'frequency' => 'monthly'])->id,
            'principal_amount' => 60000,
            'start_date' => now()->toDateString(),
        ], $this->admin);
        $loans->submitForReview($loan);
        $loans->approve($loan, $this->admin, 'Approved for testing');
        $loans->release($loan, $this->admin);
        $this->assertContains('Loan released', array_column($this->notices($this->portalUser), 'title'));

        $this->freshRequest();
        $this->actingAs($this->admin)->postJson("/api/loans/{$loan->id}/repayments", [
            'amount_paid' => 500, 'payment_date' => now()->toDateString(),
        ])->assertCreated();
        $payment = collect($this->notices($this->portalUser))->firstWhere('title', 'Payment received');
        $this->assertNotNull($payment);
        $this->assertStringContainsString('₱500.00', $payment['body']);
    }

    public function test_a_member_without_an_active_portal_account_gets_nothing_and_a_rolled_back_write_tells_nobody(): void
    {
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $account = $this->account(['borrower_id' => $other->id]);
        $this->fund($account, 10_000);
        $this->assertSame(0, DatabaseNotification::query()->count());

        $mine = $this->account(['product_id' => SavingsProduct::factory()->create()->id]);
        try {
            DB::transaction(function () use ($mine): void {
                $this->fund($mine, 10_000);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->notices($this->portalUser));

        $this->portalUser->forceFill(['status' => PortalUser::STATUS_DISABLED])->save();
        $this->fund($mine, 10_000);
        $this->assertSame([], $this->notices($this->portalUser));
    }

    public function test_a_failing_notice_never_fails_the_money_write(): void
    {
        Log::spy();
        $this->app->bind(PortalNotices::class, fn () => throw new RuntimeException('bell broken'));

        $account = $this->account();
        $this->fund($account, 10_000);

        $this->assertSame('100.00', $this->balanceOf($account));
        $this->assertSame(0, DatabaseNotification::query()->where('type', 'portal_savings')->count());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }
}
