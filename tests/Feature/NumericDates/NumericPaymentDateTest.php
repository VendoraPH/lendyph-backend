<?php

namespace Tests\Feature\NumericDates;

use App\Models\AccountingJournal;
use App\Models\Repayment;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A payment date sent as a number (20261124) or as bare digits ("20261124")
 * passes Laravel's `date` rule, which reads it as 2026-11-24. The repayment
 * and its journal then take whatever the parsing path makes of it, and the
 * "not before release" rule steps aside for anything that isn't a string, so
 * a JSON number skips it. A payment date is written year-month-day.
 */
class NumericPaymentDateTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return array<string, array{int|float|string}>
     */
    public static function numericPaymentDates(): array
    {
        return [
            'a whole number' => [20261124],
            'a decimal' => [20261124.0],
            'digits as text' => ['20261124'],
        ];
    }

    #[DataProvider('numericPaymentDates')]
    public function test_a_payment_dated_with_a_number_is_refused_and_nothing_is_saved(int|float|string $paymentDate): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-11-01');
        $this->travelTo(Carbon::parse('2026-11-25 09:00'));
        $journals = AccountingJournal::count();

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => $paymentDate])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date')
            ->assertJsonMissingValidationErrors('amount_paid');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count(), 'no repayment is saved');
        $this->assertSame($journals, AccountingJournal::count(), 'no journal is posted');
    }

    #[DataProvider('numericPaymentDates')]
    public function test_a_payment_preview_dated_with_a_number_is_refused(int|float|string $paymentDate): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-11-01');
        $this->travelTo(Carbon::parse('2026-11-25 09:00'));

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => $paymentDate])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    /**
     * The release-day rule returns early for a value that isn't a string, so
     * a JSON number dated before the release is accepted today.
     */
    public function test_a_payment_dated_before_release_with_a_json_number_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-02-20');
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $journals = AccountingJournal::count();

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => 20260228])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => 20260228])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_date');

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count(), 'no repayment is saved');
        $this->assertSame($journals, AccountingJournal::count(), 'no journal is posted');
    }

    /** The written form keeps working: the fix must not refuse it. */
    public function test_a_year_month_day_payment_date_is_still_accepted(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-11-01');
        $this->travelTo(Carbon::parse('2026-11-25 09:00'));

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-11-24'])
            ->assertCreated()
            ->assertJsonPath('data.payment_date', '2026-11-24');
    }
}
