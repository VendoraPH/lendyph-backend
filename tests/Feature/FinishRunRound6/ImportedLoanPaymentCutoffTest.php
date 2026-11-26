<?php

namespace Tests\Feature\FinishRunRound6;

use App\Models\LatePenaltySetting;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Services\RepaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 6: an imported loan's file already counts its payments up
 * to the import's as-of date (`loans.imported_arrears_baseline`). A new
 * payment dated on or before it is refused in words, on every path that
 * records a dated repayment; the day after is accepted; a loan with no as-of
 * date is unchanged. The loan sends the date as `payments_counted_until`.
 */
class ImportedLoanPaymentCutoffTest extends TestCase
{
    use SetupLendyPH;

    private const MESSAGE = "This loan's imported file already counts payments up to Jun 30, 2026. Record only payments made after that date.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** Released Jun 1, 2026; today is Jul 10, 2026; `$asOf` is the import's as-of date. */
    private function importedLoan(?string $asOf = '2026-06-30'): Loan
    {
        $this->travelTo(Carbon::parse('2026-06-01 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-06-01']);
        $loan->update(['imported_arrears_baseline' => $asOf, 'external_loan_no' => $asOf === null ? null : 'OLD-0041']);
        $this->travelTo(Carbon::parse('2026-07-10 09:00'));

        return $loan->fresh();
    }

    public function test_a_payment_on_the_as_of_date_is_refused_on_record_and_preview(): void
    {
        $loan = $this->importedLoan();

        foreach (['2026-06-30', '2026-06-15'] as $day) {
            $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => $day])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_date' => self::MESSAGE])
                ->assertJsonMissingValidationErrors(['amount_paid']);

            $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => $day])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_date' => self::MESSAGE]);
        }

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_a_payment_the_day_after_the_as_of_date_is_accepted(): void
    {
        $loan = $this->importedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-07-01'])
            ->assertOk();

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-07-01'])
            ->assertCreated()
            ->assertJsonPath('data.payment_date', '2026-07-01');
    }

    public function test_a_loan_with_no_as_of_date_is_unchanged(): void
    {
        $loan = $this->importedLoan(asOf: null);

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-06-30'])
            ->assertOk();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-06-30'])
            ->assertCreated();
    }

    /** The service refuses it too, under the lock, whatever the caller. */
    public function test_the_service_refuses_it_for_any_caller(): void
    {
        $loan = $this->importedLoan();

        try {
            app(RepaymentService::class)->processRepayment($loan, 1000, '2026-06-30', $this->admin);
            $this->fail('A payment on the as-of date was recorded.');
        } catch (ValidationException $e) {
            $this->assertSame(['payment_date' => [self::MESSAGE]], $e->errors());
        }

        try {
            app(RepaymentService::class)->previewAllocation($loan, 1000, '2026-06-30', $this->admin);
            $this->fail('A preview on the as-of date was quoted.');
        } catch (ValidationException $e) {
            $this->assertSame(['payment_date' => [self::MESSAGE]], $e->errors());
        }

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame('1000.00', (string) app(RepaymentService::class)
            ->processRepayment($loan->fresh(), 1000, '2026-07-01', $this->admin)->amount_paid);
    }

    /** Existing payments stay, and a void of one is untouched by the rule. */
    public function test_existing_payments_and_their_voids_are_untouched(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-06-01']);
        $this->travelTo(Carbon::parse('2026-06-20 09:00'));
        $paid = app(RepaymentService::class)->processRepayment($loan->fresh(), 1000, '2026-06-20', $this->admin);
        $loan->update(['imported_arrears_baseline' => '2026-06-30']);
        $this->travelTo(Carbon::parse('2026-07-10 09:00'));

        $this->patchJson("/api/repayments/{$paid->id}/void", ['void_reason' => 'Entered twice'])->assertOk();
        $this->assertSame('voided', $paid->fresh()->status);
    }

    public function test_the_loan_sends_payments_counted_until(): void
    {
        $loan = $this->importedLoan();
        $plain = $this->importedLoan(asOf: null);

        $this->getJson("/api/loans/{$loan->id}")->assertOk()->assertJsonPath('data.payments_counted_until', '2026-06-30');
        $this->getJson("/api/loans/{$loan->id}/summary")->assertOk()->assertJsonPath('data.payments_counted_until', '2026-06-30');
        $this->getJson("/api/loans/{$plain->id}")->assertOk()->assertJsonPath('data.payments_counted_until', null);
        $this->getJson("/api/loans/{$plain->id}/summary")->assertOk()->assertJsonPath('data.payments_counted_until', null);

        $row = collect($this->getJson('/api/loans?view=summary&per_page=100')->assertOk()->json('data'))->firstWhere('id', $loan->id);
        $this->assertSame('2026-06-30', $row['payments_counted_until']);
    }

    /** A one-month upon-maturity loan released Apr 27, 2026, extended on May 20, 2026. */
    private function oneMonthLoan(?string $asOf): Loan
    {
        LatePenaltySetting::query()->create(['monthly_from' => '2026-01-01']);
        $this->travelTo('2026-04-27 09:00:00');
        $loan = $this->createReleasedLoan([
            'product' => ['interest_method' => 'upon_maturity', 'term' => 1, 'frequency' => 'monthly', 'interest_rate' => 3.0],
            'start_date' => '2026-04-27',
        ]);
        $loan->update(['imported_arrears_baseline' => $asOf]);
        $this->travelTo('2026-05-20 10:00:00');

        return $loan->fresh();
    }

    public function test_an_extension_that_pays_today_is_refused_on_or_before_the_as_of_date(): void
    {
        $loan = $this->oneMonthLoan('2026-05-20');
        $message = "This loan's imported file already counts payments up to May 20, 2026. Record only payments made after that date.";

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interest_option' => $message]);
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['interest_option' => $message]);

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame(0, LoanAdjustment::where('loan_id', $loan->id)->count());

        // "defer" records no payment, so it goes ahead.
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'defer'])->assertOk();
    }

    public function test_an_extension_that_pays_after_the_as_of_date_goes_ahead(): void
    {
        $loan = $this->oneMonthLoan('2026-05-19');

        $this->getJson("/api/loans/{$loan->id}/extension-preview?interest_option=pay")->assertOk();
        $this->postJson("/api/loans/{$loan->id}/extend", ['interest_option' => 'pay'])->assertOk();

        $this->assertSame(1, Repayment::where('loan_id', $loan->id)->count());
    }

    /** Auto-pay runs today: a loan whose file counts today is skipped with the reason, never charged. */
    public function test_auto_pay_skips_a_loan_whose_file_counts_the_run_date(): void
    {
        $this->travelTo(Carbon::parse('2026-06-10 09:00'));
        $loan = $this->createReleasedLoan(['start_date' => '2026-06-10']);
        $other = $this->createReleasedLoan(['start_date' => '2026-06-10']);
        foreach ([$loan, $other] as $i => $each) {
            $each->update(['auto_pay' => true, 'cbs_reference' => 'CBS-2026-0000'.$i, 'auto_pay_enabled_at' => now(), 'auto_pay_enabled_by' => $this->admin->id]);
        }
        $loan->update(['imported_arrears_baseline' => '2026-07-10']);
        $other->update(['imported_arrears_baseline' => '2026-07-09']);
        $this->travelTo(Carbon::parse('2026-07-10 09:00'));

        $range = ['date_from' => '2026-07-10', 'date_to' => '2026-07-10'];
        $reason = "This loan's imported file already counts payments up to Jul 10, 2026. Record only payments made after that date.";

        $preview = $this->getJson('/api/auto-pay/preview?'.http_build_query($range))->assertOk()->json('data');
        $this->assertContains(['loan_id' => $loan->id, 'loan_account_number' => $loan->loan_account_number, 'reason' => $reason], $preview['skips']);
        $this->assertNotContains($loan->id, array_column($preview['loans'], 'loan_id'));

        $run = $this->postJson('/api/auto-pay/process', $range)->assertCreated()->json('data');
        $this->assertSame(1, $run['processed']);
        $this->assertSame(0, $run['failed']);
        $this->assertContains(['loan_id' => $loan->id, 'loan_account_number' => $loan->loan_account_number, 'reason' => $reason], $run['skips']);
        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
        $this->assertSame(1, Repayment::where('loan_id', $other->id)->count());
    }
}
