<?php

namespace App\Http\Requests\Repayment;

use App\Http\Requests\Concerns\DateInputMessages;
use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Models\Loan;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreRepaymentRequest extends FormRequest
{
    use ReferenceNumberMessages;

    public function authorize(): bool
    {
        return $this->user()->can('payments:create');
    }

    public function rules(): array
    {
        $loan = $this->route('loan');

        return [
            'payment_date' => ['required', 'date', 'date_format:Y-m-d', 'before_or_equal:today', self::notBeforeRelease($loan instanceof Loan ? $loan : null)],
            'amount_paid' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['sometimes', 'in:cash,gcash,maya,bank_transfer,online'],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_unless:method,cash'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Refuse a `payment_date` before the day the loan was released.
     *
     * A payment date is a calendar day written year-month-day (the field's
     * `date_format:Y-m-d`), so the payment's day is the value itself, read
     * strictly in that one form: a number, bare digits or a time are refused by
     * that rule, and this one never reads them another way. The release day is
     * `released_at` (a Manila time) read in the app timezone.
     *
     * For the requests that take a payment date from the client: recording a
     * repayment and its preview (RepaymentController::preview()). Server-dated
     * paths (auto-pay, extension) don't go through it. A loan with no
     * `released_at` passes, since a payment on it is refused anyway, and so
     * does a value the date rules already refuse.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function notBeforeRelease(?Loan $loan): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($loan): void {
            if ($loan?->released_at === null || ! is_string($value)) {
                return;
            }

            $paymentDay = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($paymentDay === false || $paymentDay->format('Y-m-d') !== $value) {
                return;
            }

            $releaseDay = $loan->released_at->copy()->setTimezone(config('app.timezone'))->toDateString();

            if ($value < $releaseDay) {
                $fail("The payment date can't be before the loan's release date ({$releaseDay}).");
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->referenceNumberMessages(), ...DateInputMessages::calendar(['payment_date' => 'payment date'])];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'cash']);
        }
    }
}
