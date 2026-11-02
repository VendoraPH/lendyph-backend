<?php

namespace App\Http\Requests\Repayment;

use App\Models\Loan;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('payments:create');
    }

    public function rules(): array
    {
        $loan = $this->route('loan');

        return [
            'payment_date' => ['required', 'date', 'before_or_equal:today', self::notBeforeRelease($loan instanceof Loan ? $loan : null)],
            'amount_paid' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['sometimes', 'in:cash,gcash,maya,bank_transfer,online'],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Refuse a `payment_date` before the day the loan was released.
     *
     * The payment's day is the date the repayment will be stored with:
     * RepaymentService parses the input as it is (Carbon::parse(), a bare date
     * read in the app timezone) and the `payment_date` column keeps that
     * Carbon's own calendar date, so an input with an offset keeps its own
     * date, never the Manila one. The release day is `released_at` (a Manila
     * time) read in the app timezone.
     *
     * For the requests that take a payment date from the client: recording a
     * repayment and its preview (RepaymentController::preview()). Server-dated
     * paths (auto-pay, extension) don't go through it. A loan with no
     * `released_at` passes, since a payment on it is refused anyway, and so
     * does a date the `date` rule already refuses.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function notBeforeRelease(?Loan $loan): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($loan): void {
            if ($loan?->released_at === null || ! is_string($value)) {
                return;
            }

            try {
                $paymentDay = Carbon::parse($value)->toDateString();
            } catch (InvalidFormatException) {
                return;
            }

            $releaseDay = $loan->released_at->copy()->setTimezone(config('app.timezone'))->toDateString();

            if ($paymentDay < $releaseDay) {
                $fail("The payment date can't be before the loan's release date ({$releaseDay}).");
            }
        };
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }
}
