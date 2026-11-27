<?php

namespace App\Services\ReceiptShareLinks;

use App\Enums\PaymentMethod;
use App\Http\Resources\RepaymentResource;
use App\Models\BrandingSetting;
use App\Models\Repayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * One payment receipt as a share link shows it to anyone holding the link
 * (finish run round 2, decision 6). Built field by field from an allow-list,
 * never by trimming the staff resource, so nothing new can leak by default:
 *
 * - the cooperative's name and logo;
 * - the receipt number, payment date, amount paid and its split, as 2-decimal
 *   strings: the split reads the staff receipt's own figures, including its
 *   build-up and overpayment rule (RepaymentResource);
 * - the member as initials and surname (MaskedName);
 * - the method in words, and only the last 4 characters of its reference (none
 *   when the reference is 4 characters or fewer, so a whole reference is never
 *   shown);
 * - VOIDED, with the void date, when the payment was voided.
 *
 * It never carries contact details, the member's code, the loan or its account
 * number or product, balances, the next due date, other loans, who recorded or
 * voided the payment, the void reason or the remarks.
 */
final class PublicReceipt
{
    /** The split, in the staff receipt's own field names, so the screens read one rule. */
    private const SPLIT = ['principal_applied', 'interest_applied', 'penalty_applied', 'insurance_applied', 'overpayment'];

    /**
     * @return array{
     *     cooperative: array{name: string, logo_url: string|null},
     *     receipt_number: string,
     *     payment_date: string|null,
     *     member_name: string,
     *     method: string|null,
     *     reference_last4: string|null,
     *     amount_paid: string,
     *     principal_applied: string,
     *     interest_applied: string,
     *     penalty_applied: string,
     *     insurance_applied: string,
     *     overpayment: string,
     *     build_up_credited: string|null,
     *     overpayment_kept: string|null,
     *     status: string,
     *     voided_on: string|null,
     * }
     */
    public function present(Repayment $repayment, Request $request): array
    {
        $repayment->loadMissing(['loan.borrower', 'shareCapitalLedgerEntries']);
        $member = $repayment->loan?->borrower;
        $staffReceipt = (new RepaymentResource($repayment))->resolve($request);
        $voided = $repayment->status === 'voided';

        return [
            'cooperative' => $this->cooperative(),
            'receipt_number' => (string) $repayment->receipt_number,
            'payment_date' => $repayment->payment_date?->toDateString(),
            'member_name' => MaskedName::of($member?->first_name, $member?->middle_name, $member?->last_name, $member?->suffix),
            'method' => PaymentMethod::labelFor($repayment->method),
            'reference_last4' => self::lastFour($repayment->reference_number),
            'amount_paid' => self::pesos($repayment->amount_paid),
            ...array_combine(self::SPLIT, array_map(fn (string $field): string => self::pesos($repayment->{$field}), self::SPLIT)),
            'build_up_credited' => $staffReceipt['build_up_credited'],
            'overpayment_kept' => $staffReceipt['overpayment_kept'],
            'status' => $voided ? 'voided' : 'completed',
            'voided_on' => $voided ? $repayment->voided_at?->toDateString() : null,
        ];
    }

    /**
     * The cooperative's name (the app's name when none is set) and its logo's
     * API path, `/branding/logo`, only while a logo file is stored: the page
     * reads it through its own same-origin proxy, so the image can be saved.
     *
     * @return array{name: string, logo_url: string|null}
     */
    private function cooperative(): array
    {
        $branding = BrandingSetting::current();
        $logo = $branding->logo_path;

        return [
            'name' => trim((string) $branding->organization_name) !== '' ? trim((string) $branding->organization_name) : (string) config('app.name'),
            'logo_url' => $logo && Storage::disk('public')->exists($logo) ? '/branding/logo' : null,
        ];
    }

    private static function lastFour(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return mb_strlen($reference, 'UTF-8') > 4 ? mb_substr($reference, -4, null, 'UTF-8') : null;
    }

    /** A stored figure as its `decimal:2` cast reads it ("1500.00"). Nothing is worked out. */
    private static function pesos(?string $value): string
    {
        return $value ?? '0.00';
    }
}
