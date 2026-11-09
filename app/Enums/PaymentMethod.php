<?php

namespace App\Enums;

/**
 * How money moved for a payment or a release (`repayments.method`,
 * `loans.release_method`), in words as the Statement of Account prints it:
 * "GCash", never "Gcash" from title-casing the stored key.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case GCash = 'gcash';
    case Maya = 'maya';
    case BankTransfer = 'bank_transfer';
    case Online = 'online';
    case AutoPay = 'auto_pay';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::GCash => 'GCash',
            self::Maya => 'Maya',
            self::BankTransfer => 'Bank Transfer',
            self::Online => 'Online',
            self::AutoPay => 'Auto-pay',
        };
    }

    /**
     * A stored method in words; a key this list doesn't know reads with its
     * underscores as spaces, title-cased. Null for no method.
     */
    public static function labelFor(?string $method): ?string
    {
        if ($method === null || $method === '') {
            return null;
        }

        return self::tryFrom($method)?->label() ?? ucwords(str_replace('_', ' ', $method));
    }
}
