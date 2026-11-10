<?php

namespace App\Services\Savings;

use App\Models\SavingsTaxRule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The withholding tax on deposit interest in force for a holder type on a
 * date (design §1): the latest `effective_from` on or before it, ties to the
 * highest id. Fails closed (422) when no rule is in force, rather than
 * withholding nothing by default.
 */
class TaxBook
{
    /** @throws ValidationException */
    public function rateOn(string $holderType, string $date): string
    {
        $rate = SavingsTaxRule::query()
            ->where('holder_type', $holderType)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('rate');

        if ($rate === null) {
            $who = $holderType === SavingsTaxRule::HOLDER_MEMBER ? 'members' : 'walk-ins';

            throw ValidationException::withMessages(['tax' => [
                "No withholding tax rule is in force for {$who} on ".CarbonImmutable::parse($date)->format('M j, Y').'. Add one in Savings → Settings.',
            ]]);
        }

        return SavingsMath::rateString(SavingsMath::rateUnits((string) $rate));
    }

    /**
     * The rate in force today for each holder type.
     *
     * @return array<string, string|null>
     */
    public function current(?string $date = null): array
    {
        $date ??= now()->toDateString();
        $rates = [];

        foreach (SavingsTaxRule::HOLDER_TYPES as $holderType) {
            $rate = SavingsTaxRule::query()
                ->where('holder_type', $holderType)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->value('rate');
            $rates[$holderType] = $rate === null ? null : SavingsMath::rateString(SavingsMath::rateUnits((string) $rate));
        }

        return $rates;
    }
}
