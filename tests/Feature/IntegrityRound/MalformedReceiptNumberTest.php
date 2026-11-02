<?php

namespace Tests\Feature\IntegrityRound;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Item 9. The next receipt number is parsed with SequenceCode, which refuses
 * to derive a number from a malformed code. A malformed newest receipt must
 * never stop a payment: the number comes from the highest well-formed RCP-
 * receipt instead (by its number, not its text), or RCP-000001 when there is
 * none.
 */
class MalformedReceiptNumberTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function pay(Loan $loan, float $amount = 100): Repayment
    {
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => $amount,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        return Repayment::findOrFail($id);
    }

    private function renumber(Repayment $repayment, string $receipt): void
    {
        DB::table('repayments')->where('id', $repayment->id)->update(['receipt_number' => $receipt]);
    }

    public function test_a_malformed_newest_receipt_numbers_on_from_the_highest_well_formed_one(): void
    {
        $loan = $this->createReleasedLoan();
        $this->renumber($this->pay($loan), 'RCP-999999');
        // Higher by number though lower as text: compared as numbers.
        $this->renumber($this->pay($loan), 'RCP-1000004');
        $this->renumber($this->pay($loan), 'LEGACY 17');

        $this->assertSame('RCP-1000005', $this->pay($loan)->receipt_number);
    }

    public function test_with_no_well_formed_receipt_the_first_number_is_used(): void
    {
        $loan = $this->createReleasedLoan();
        Repayment::query()->get()->each(fn (Repayment $r) => $this->renumber($r, "OLD-{$r->id}"));
        $this->renumber($this->pay($loan), 'RCP-12A');

        $this->assertSame('RCP-000001', $this->pay($loan)->receipt_number);
    }

    public function test_a_well_formed_newest_receipt_still_numbers_on_from_itself(): void
    {
        $loan = $this->createReleasedLoan();
        $this->renumber($this->pay($loan), 'RCP-000041');

        $this->assertSame('RCP-000042', $this->pay($loan)->receipt_number);
    }
}
