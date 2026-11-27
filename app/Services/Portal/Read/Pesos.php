<?php

namespace App\Services\Portal\Read;

use App\Services\Accounting\Money;
use App\Services\LoanService;
use App\Services\Savings\SavingsMath;

/**
 * Money as the portal sends it (portal design §0): a two-decimal string of a
 * figure an existing service already worked out, never a figure of the
 * portal's own. Lending services hand back float pesos, savings services
 * two-decimal strings; both become whole centavos first, the way those
 * services round them, so the string is exactly the staff screen's figure.
 */
final class Pesos
{
    /** The amount in whole centavos: a float or int read as pesos, a string parsed digit by digit. */
    public static function centavos(float|int|string|null $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        if (is_string($amount)) {
            return SavingsMath::signedCentavos($amount);
        }

        return LoanService::toCentavos((float) $amount);
    }

    public static function of(float|int|string|null $amount): ?string
    {
        return $amount === null ? null : SavingsMath::pesos(self::centavos($amount));
    }

    public static function fromCentavos(int $centavos): string
    {
        return SavingsMath::pesos($centavos);
    }

    /** "₱1,500.00" for the printed statements. */
    public static function printed(float|int|string|null $amount): string
    {
        return $amount === null ? '—' : Money::format(self::centavos($amount));
    }
}
