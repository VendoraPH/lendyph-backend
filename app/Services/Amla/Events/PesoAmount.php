<?php

namespace App\Services\Amla\Events;

use InvalidArgumentException;

/**
 * Peso amounts as the money tables store them (decimal strings), compared in
 * whole centavos with no float arithmetic, so an event's amount is the source
 * row's amount to the centavo.
 */
final class PesoAmount
{
    public static function centavos(string $amount): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $parts) !== 1) {
            throw new InvalidArgumentException("\"{$amount}\" isn't a peso amount.");
        }

        $centavos = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '0', 2, '0');

        return $parts[1] === '-' ? -$centavos : $centavos;
    }

    /** The amount with two decimals, as stored ("1020" reads "1020.00"). */
    public static function normal(string $amount): string
    {
        return self::fromCentavos(self::centavos($amount));
    }

    public static function fromCentavos(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return $sign.intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "₱1,020.00", for refusals in words. */
    public static function words(string $amount): string
    {
        $centavos = self::centavos($amount);

        return ($centavos < 0 ? '-' : '').'₱'.number_format(intdiv(abs($centavos), 100)).'.'.str_pad((string) (abs($centavos) % 100), 2, '0', STR_PAD_LEFT);
    }
}
