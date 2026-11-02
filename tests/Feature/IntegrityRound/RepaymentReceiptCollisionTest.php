<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 9: the next receipt number collides on `repayments_receipt_number_unique`.
 *
 * Repayment::booted() reads the newest row's receipt with no lock and adds one.
 * Two payments on different loans hold different loan locks, so both can read
 * RCP-000041 and both insert RCP-000042; the second gets a duplicate-key error,
 * and no LoanWriteTransaction caller names that index, so it answers 500.
 *
 * A real race needs two connections. These tests force the same collision
 * deterministically: the newest row is given a receipt BELOW an existing one,
 * so the next number the hook computes is already taken, exactly what the
 * losing side of the race sees.
 *
 * Pinned (plan decision 2):
 *  - a write that collides answers 409, never 500, and leaves nothing behind
 *    (LoanWriteTransaction::run() names the index, as createLoan() does for
 *    `loans_application_number_unique`);
 *  - the next receipt is read with a locking read, as Loan::booted() does;
 *  - previews (payment preview, extension preview on `pay`) never allocate a
 *    real receipt number, so they neither collide nor wait on that lock.
 */
class RepaymentReceiptCollisionTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->travelTo('2026-11-02 10:00:00');
    }

    private function pay(Loan $loan, float $amount): void
    {
        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated();
    }

    /**
     * Two payments on a loan of their own, then the newer one renumbered two
     * below the older, so the receipt the hook computes next is the older
     * one's.
     */
    private function forceNextReceiptCollision(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan, 100);
        $this->pay($loan, 100);

        $older = Repayment::where('loan_id', $loan->id)->orderBy('id')->firstOrFail();
        $newest = Repayment::query()->orderByDesc('id')->firstOrFail();

        $number = (int) substr($older->receipt_number, 4);
        DB::table('repayments')->where('id', $newest->id)->update([
            'receipt_number' => 'RCP-'.str_pad((string) ($number - 1), 6, '0', STR_PAD_LEFT),
        ]);
    }

    private function uponMaturityLoan(): Loan
    {
        return $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'principal_amount' => 60000,
            'start_date' => '2026-10-20',
        ]);
    }

    public function test_a_payment_whose_receipt_number_collides_answers_409_and_writes_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->forceNextReceiptCollision();
        $before = Repayment::count();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 500,
            'method' => 'cash',
        ])->assertStatus(409);

        $this->assertSame($before, Repayment::count());
    }

    public function test_an_extension_that_collects_interest_answers_409_on_a_receipt_collision(): void
    {
        $loan = $this->uponMaturityLoan();
        $this->forceNextReceiptCollision();

        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])
            ->assertStatus(409);

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_the_payment_preview_does_not_depend_on_the_next_receipt_number(): void
    {
        $loan = $this->createReleasedLoan();
        $this->forceNextReceiptCollision();

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 500,
        ])->assertOk();
    }

    public function test_the_extension_preview_on_pay_does_not_depend_on_the_next_receipt_number(): void
    {
        $loan = $this->uponMaturityLoan();
        $this->forceNextReceiptCollision();

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertOk();
    }

    /**
     * The mechanism, since the race itself needs two connections: the read of
     * the newest receipt is a locking read inside the payment's transaction.
     */
    public function test_recording_a_payment_reads_the_newest_receipt_with_a_lock(): void
    {
        $loan = $this->createReleasedLoan();

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            if (preg_match('/from `repayments` order by `id` desc limit 1/i', $query->sql)) {
                $reads[] = $query->sql;
            }
        });

        $this->pay($loan, 500);

        $this->assertNotEmpty($reads, 'the receipt hook should read the newest repayment');
        foreach ($reads as $sql) {
            $this->assertStringContainsStringIgnoringCase('for update', $sql);
        }
    }
}
