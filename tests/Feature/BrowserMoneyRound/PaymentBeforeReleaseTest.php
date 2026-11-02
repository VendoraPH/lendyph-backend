<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use App\Models\Repayment;
use Illuminate\Support\Carbon;
use Tests\Feature\BrowserMoneyRound\Concerns\BuildsReleasedLoans;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Decision m5-4: a payment dated before the loan's release date is refused,
 * on POST /loans/{loan}/repayments and on its preview, with a 422 on
 * `payment_date`. Dates are compared in the app timezone (Asia/Manila), the
 * zone `released_at` is stored in.
 */
class PaymentBeforeReleaseTest extends TestCase
{
    use BuildsReleasedLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_payment_dated_the_day_before_release_is_refused_on_post_and_preview(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-02-20');
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));

        $message = "The payment date can't be before the loan's release date (2026-03-01).";

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-02-28'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => $message])
            ->assertJsonMissingValidationErrors(['amount_paid']);

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-02-28'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => $message]);

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());
    }

    public function test_a_payment_dated_on_the_release_day_is_accepted(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-02-20');
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-03-01'])
            ->assertOk();

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-01'])
            ->assertCreated()
            ->assertJsonPath('data.payment_date', '2026-03-01');
    }

    public function test_a_release_just_after_midnight_is_dated_in_the_app_timezone(): void
    {
        // 00:20 in Manila on the 15th is still the 14th in UTC (16:20). The
        // release day is the 15th, so the 14th is refused.
        $this->travelTo(Carbon::parse('2026-03-15 00:20', 'Asia/Manila'));
        $loan = $this->releasedLoanFor(startDate: '2026-03-15');
        $this->assertSame('2026-03-14', $loan->released_at->copy()->utc()->toDateString(), 'setup: the release is still the 14th in UTC');

        $message = "The payment date can't be before the loan's release date (2026-03-15).";

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-03-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => $message]);
        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-14'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => $message]);

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-15'])
            ->assertCreated();
    }

    public function test_a_release_just_before_midnight_accepts_a_payment_that_same_day(): void
    {
        $this->travelTo(Carbon::parse('2026-03-14 23:50', 'Asia/Manila'));
        $loan = $this->releasedLoanFor(startDate: '2026-03-14');
        $this->travelTo(Carbon::parse('2026-03-15 00:10', 'Asia/Manila'));

        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-14'])
            ->assertCreated();
        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-13'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => "The payment date can't be before the loan's release date (2026-03-14)."]);
    }

    public function test_a_loan_with_no_release_date_skips_the_rule(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-03-01');
        Loan::whereKey($loan->id)->update(['released_at' => null]);

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => '2026-02-01'])
            ->assertOk();
    }

    /**
     * The repayment is stored with the input's own calendar date, offset
     * included, so that is the date the rule compares. 16:30 UTC on the 28th
     * is already the 1st in Manila, but it would be stored as the 28th, before
     * the release on the 1st.
     */
    public function test_an_offset_timestamp_that_would_be_stored_before_release_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-03-01 09:00'));
        $loan = $this->releasedLoanFor(startDate: '2026-02-20');
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));

        $message = "The payment date can't be before the loan's release date (2026-03-01).";

        foreach (['2026-02-28T16:30:00Z', '2026-02-28T20:00:00-05:00'] as $paymentDate) {
            $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => $paymentDate])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_date' => $message]);
            $this->postJson("/api/loans/{$loan->id}/repayments/preview", ['amount_paid' => 1000, 'payment_date' => $paymentDate])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['payment_date' => $message]);
        }

        $this->assertSame(0, Repayment::where('loan_id', $loan->id)->count());

        // An offset timestamp whose own date is the release day is accepted
        // and stored with that date.
        $this->postJson("/api/loans/{$loan->id}/repayments", ['amount_paid' => 1000, 'payment_date' => '2026-03-01T01:00:00+08:00'])
            ->assertCreated()
            ->assertJsonPath('data.payment_date', '2026-03-01');
    }
}
