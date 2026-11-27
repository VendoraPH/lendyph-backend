<?php

namespace App\Services\Portal\Read;

/**
 * Stored keys the portal shows a member in words: the profile's values and
 * the printed statements. An unknown key reads "Other", never the raw key.
 */
final class MemberWords
{
    public const CIVIL_STATUS = [
        'single' => 'Single',
        'married' => 'Married',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
        'divorced' => 'Divorced',
        'annulled' => 'Annulled',
    ];

    public const GENDER = ['male' => 'Male', 'female' => 'Female'];

    public const MEMBER_TYPE = ['regular' => 'Regular member', 'associate' => 'Associate member'];

    /** The valid ID types the member forms offer (frontend VALID_ID_OPTIONS). */
    public const ID_TYPE = [
        'philippine_id' => 'Philippine National ID (PhilSys)',
        'drivers_license' => 'Driver\'s License',
        'passport' => 'Passport',
        'sss' => 'SSS ID',
        'umid' => 'UMID',
        'voters_id' => 'Voter\'s ID',
        'postal_id' => 'Postal ID',
        'prc_id' => 'PRC ID',
        'tin_id' => 'TIN ID',
    ];

    public const SAVINGS_TYPE = [
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'interest' => 'Interest',
        'withholding_tax' => 'Withholding tax',
        'closing' => 'Account closing',
        'reversal' => 'Correction',
        'fee' => 'Excess-withdrawal fee',
        'share_capital_transfer' => 'Transfer to share capital',
    ];

    public const MATURITY_INSTRUCTION = [
        'payout' => 'Pay out at maturity',
        'rollover_principal' => 'Roll over the principal',
        'rollover_all' => 'Roll over principal and interest',
    ];

    public const PAYMENT_FREQUENCY = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'bi_weekly' => 'Every two weeks',
        'semi_monthly' => 'Twice a month',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'semi_annual' => 'Twice a year',
        'annual' => 'Yearly',
        'upon_maturity' => 'Upon maturity',
        'lump_sum' => 'Upon maturity',
    ];

    /**
     * @param  array<string, string>  $map
     */
    public static function of(array $map, ?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $map[$key] ?? 'Other';
    }

    /** A document's type in words ("Contract", "Other document"). */
    public static function documentType(?string $type): string
    {
        $words = trim(str_replace('_', ' ', (string) $type));

        return $words === '' || $words === 'other' ? 'Document' : ucfirst($words);
    }
}
