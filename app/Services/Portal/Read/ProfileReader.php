<?php

namespace App\Services\Portal\Read;

use App\Models\Borrower;
use App\Models\Document;
use App\Models\PortalUser;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\PortalSettings;
use App\Services\Reminders\MemberContact;
use App\Services\ValidIdService;
use App\Support\PhilippineMobile;

/**
 * The member's own profile, masked (portal design §1). The birthdate and the
 * mobile number verify the member when a code is redeemed, so a stolen
 * session must not hand them over:
 *
 * | Field | Sent as |
 * |---|---|
 * | name, member number and type, membership date, branch, civil status, gender, employer, barangay, city, province | in full |
 * | mobile | PhilippineMobile::mask ("63917*****67") |
 *
 * | email | MemberContact::maskEmail ("ju***@gmail.com") |
 * | birthdate | the year only (`birth_year`) |
 * | street address | "On file" |
 * | government ID numbers | type + last 4 ("UMID •••• 4821") |
 * | income, spouse, photo, co-makers, ID images, the combined address | never |
 *
 * An allow-list: only the fields below leave the server.
 */
final class ProfileReader
{
    /** What a member may ask to change (portal design §6, `profile_update`). */
    public const EDITABLE_FIELDS = [
        'street_address', 'barangay', 'city', 'province', 'contact_number', 'email', 'civil_status', 'employer_or_business',
    ];

    public const ON_FILE = 'On file';

    public function __construct(private readonly PortalSettings $settings) {}

    /**
     * @return array{fields: list<array{key: string, label: string, value: string|null, masked: bool}>, editable_fields: list<string>}
     */
    public function profile(PortalUser $portalUser): array
    {
        /** @var Borrower $member */
        $member = $portalUser->borrower()->with('branch:id,name')->firstOrFail();

        $fields = [
            $this->field('full_name', 'Full name', $member->full_name),
            $this->field('member_number', 'Member number', MemberNumber::of($member)),
            $this->field('member_type', 'Member type', MemberWords::of(MemberWords::MEMBER_TYPE, $member->member_type)),
            $this->field('membership_date', 'Member since', $member->membership_date?->toDateString()),
            $this->field('branch', 'Branch', $member->branch?->name),
            $this->field('birth_year', 'Year of birth', $member->birthdate?->format('Y'), true),
            $this->field('gender', 'Gender', MemberWords::of(MemberWords::GENDER, $member->gender)),
            $this->field('civil_status', 'Civil status', MemberWords::of(MemberWords::CIVIL_STATUS, $member->civil_status)),
            $this->field('contact_number', 'Mobile number', self::maskedMobile($member->contact_number), true),
            $this->field('email', 'Email', self::maskedEmail($member->email), true),
            $this->field('street_address', 'Street address', self::maskedStreet($member->street_address), true),
            $this->field('barangay', 'Barangay', $member->barangay),
            $this->field('city', 'City or municipality', $member->city),
            $this->field('province', 'Province', $member->province),
            $this->field('employer_or_business', 'Employer or business', $member->employer_or_business),
            ...array_map(fn (string $id) => $this->field('government_id', 'Government ID', $id, true), $this->governmentIds($member)),
        ];

        return [
            'fields' => $fields,
            'editable_fields' => $this->settings->featureOn('profile_requests') ? self::EDITABLE_FIELDS : [],
        ];
    }

    public static function maskedMobile(?string $number): ?string
    {
        return blank($number) ? null : PhilippineMobile::mask((string) $number);
    }

    public static function maskedEmail(?string $email): ?string
    {
        return blank($email) ? null : MemberContact::maskEmail((string) $email);
    }

    public static function maskedStreet(?string $street): ?string
    {
        return blank($street) ? null : self::ON_FILE;
    }

    /**
     * One line per ID the member holds (a front and back are one ID): its
     * type in words and the last 4 characters of its number.
     *
     * @return list<string>
     */
    private function governmentIds(Borrower $member): array
    {
        return Document::query()
            ->where('documentable_type', $member->getMorphClass())
            ->where('documentable_id', $member->getKey())
            ->where('type', ValidIdService::DOCUMENT_TYPE)
            ->whereNotNull('id_number')
            ->orderBy('id')
            ->get(['label', 'custom_type_name', 'id_number'])
            ->map(function (Document $id): string {
                $type = $id->label === 'others' && filled($id->custom_type_name)
                    ? (string) $id->custom_type_name
                    : (MemberWords::ID_TYPE[$id->label] ?? 'ID');
                $digits = (string) preg_replace('/[^0-9A-Za-z]/', '', (string) $id->id_number);

                return $type.' •••• '.mb_substr($digits, -4);
            })
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{key: string, label: string, value: string|null, masked: bool} */
    private function field(string $key, string $label, ?string $value, bool $masked = false): array
    {
        return ['key' => $key, 'label' => $label, 'value' => blank($value) ? null : $value, 'masked' => $masked];
    }
}
