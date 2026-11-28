<?php

namespace App\Services\Cic\Records;

use App\Models\Borrower;

/**
 * The ID (Individual) record of a member: a member with a reportable loan,
 * or a member who is a co-maker on one, always read from the member record.
 * Follows notes/cic/mapping/cic-to-lendy-mapping.csv field by field; fields
 * the CSV marks "Optional – Not Available" or "Not Applicable" stay empty.
 *
 * - Address 1 is the residence on file (MI). When its city and province are
 *   blank, the old one-line address goes as FullAddress (ID33); otherwise the
 *   street line (street_address, else the old address column), barangay, city
 *   and province go in their own fields.
 * - A non-MFI provider must report Address 2 (AI); it repeats Address 1 (Q3).
 * - Identifications (ID54–ID59) and IDs (ID60–ID77) come from the valid-ID
 *   documents (CicIdentifications). None on file is a warning (plan decision 4).
 */
final class SubjectRecordBuilder
{
    public const TYPE = 'ID';

    /** Identification blocks: type and number fields. */
    private const IDENTIFICATION_FIELDS = [[54, 55], [56, 57], [58, 59]];

    /** ID blocks: type and number fields (each block is 6 fields wide). */
    private const ID_FIELDS = [[60, 61], [66, 67], [72, 73]];

    private const MOBILE_PATTERN = '/^(09|\+639)\d{9}$/';

    public function __construct(private readonly CicBuildContext $context) {}

    /**
     * @param  array{identifications: list<array{code: string, number: string}>, ids: list<array{code: string, number: string}>}  $identifications
     */
    public function build(Borrower $member, array $identifications): CicRecord
    {
        $v = [
            'ID1' => self::TYPE,
            'ID2' => $this->context->providerCode,
            'ID3' => $this->context->branchCode($member->branch_id),
            'ID4' => $this->context->referenceDate,
            'ID5' => CicFormat::text($member->borrower_code),
            'ID7' => CicFormat::text($member->first_name),
            'ID8' => CicFormat::text($member->last_name),
            'ID9' => CicFormat::text($member->middle_name),
            'ID10' => CicFormat::text($member->suffix),
            'ID13' => match ($member->gender) {
                'male' => 'M',
                'female' => 'F',
                default => '',
            },
            'ID14' => CicFormat::date($member->birthdate),
            'ID19' => match ($member->civil_status) {
                'single' => '1',
                'married' => '2',
                'separated', 'divorced' => '3',
                'widowed' => '4',
                default => '',
            },
            'ID22' => CicFormat::text($member->spouse_first_name),
            'ID23' => CicFormat::text($member->spouse_last_name),
            'ID24' => CicFormat::text($member->spouse_middle_name),
            ...$this->addresses($member),
            ...$this->contacts($member),
            'ID82' => CicFormat::text($member->employer_or_business),
            'ID90' => CicFormat::date($member->date_hired),
        ];

        if ($member->monthly_income !== null) {
            $v['ID86'] = CicFormat::amount((string) $member->monthly_income);
            $v['ID87'] = 'M';
            $v['ID88'] = 'PHP';
        }

        foreach ($identifications['identifications'] as $index => $identification) {
            [$typeField, $numberField] = self::IDENTIFICATION_FIELDS[$index];
            $v["ID{$typeField}"] = $identification['code'];
            $v["ID{$numberField}"] = $identification['number'];
        }

        foreach ($identifications['ids'] as $index => $id) {
            [$typeField, $numberField] = self::ID_FIELDS[$index];
            $v["ID{$typeField}"] = $id['code'];
            $v["ID{$numberField}"] = $id['number'];
        }

        return new CicRecord(self::TYPE, CicFormat::text($member->borrower_code), CicFormat::compact($v), borrowerId: $member->id);
    }

    /** @return array<string, string> */
    private function addresses(Borrower $member): array
    {
        $street = CicFormat::text($member->street_address);
        $street = $street !== '' ? $street : CicFormat::text($member->address);
        $city = CicFormat::text($member->city);
        $province = CicFormat::text($member->province);
        $oneLine = $city === '' && $province === '';

        $address = ['type' => 'MI'];

        if ($oneLine) {
            $address['full'] = CicFormat::text($member->address);
        } else {
            $address += [
                'street' => $street,
                'barangay' => CicFormat::text($member->barangay),
                'city' => $city,
                'province' => $province,
            ];
        }

        $values = $this->addressBlock(32, $address);

        if (! $this->context->mfi) {
            $values += $this->addressBlock(43, ['type' => 'AI'] + $address);
        }

        return $values;
    }

    /**
     * An address block's fields from its base position: type, full, street,
     * postal, subdivision, barangay, city, province, country.
     *
     * @param  array<string, string>  $address
     * @return array<string, string>
     */
    private function addressBlock(int $base, array $address): array
    {
        $offsets = ['type' => 0, 'full' => 1, 'street' => 2, 'barangay' => 5, 'city' => 6, 'province' => 7];
        $values = [];

        foreach ($address as $part => $value) {
            $values['ID'.($base + $offsets[$part])] = $value;
        }

        return $values;
    }

    /** @return array<string, string> */
    private function contacts(Borrower $member): array
    {
        $values = [];
        $phone = CicFormat::text($member->contact_number);

        if ($phone !== '') {
            $values['ID78'] = preg_match(self::MOBILE_PATTERN, $phone) === 1 ? '3' : '1';
            $values['ID79'] = $phone;
        }

        $email = CicFormat::text($member->email);

        if ($email !== '') {
            $values['ID80'] = '7';
            $values['ID81'] = $email;
        }

        return $values;
    }
}
