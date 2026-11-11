<?php

namespace App\Services\Portal\Requests;

use App\Http\Requests\Borrower\Concerns\HasBorrowerRules;
use App\Models\Borrower;
use App\Services\Reminders\MemberContact;
use App\Support\PhilippineMobile;

/**
 * The member fields a portal profile change may ask for (portal design §6),
 * checked by the same rules staff edits go through (HasBorrowerRules: the
 * formats, a unique email, and a required field that can't be cleared), and
 * shown to the member masked as the portal profile masks them (§1): mobile
 * and email masked, the street line "On file".
 */
final class ProfileFields
{
    use HasBorrowerRules;

    /** Field => label, in the order they are shown. */
    public const FIELDS = [
        'street_address' => 'Street address',
        'barangay' => 'Barangay',
        'city' => 'City or municipality',
        'province' => 'Province',
        'contact_number' => 'Mobile number',
        'email' => 'Email',
        'civil_status' => 'Civil status',
        'employer_or_business' => 'Employer or business',
    ];

    /** Changes that are security-relevant: the member is told when they're applied. */
    public const CONTACT_FIELDS = ['contact_number', 'email'];

    public const ON_FILE = 'On file';

    /**
     * The member update rules for these fields, for this member.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(Borrower $member): array
    {
        $rules = $this->requireMemberFields($this->sharedBorrowerRules($member->id), creating: false);

        return array_intersect_key($rules, self::FIELDS);
    }

    /** @return array<string, string> */
    public function messages(string $prefix = ''): array
    {
        $messages = [
            'street_address.required_without' => 'The street address can\'t be left empty.',
            'contact_number.regex' => 'The mobile number must be a valid phone number, like 09171234567.',
            'email.unique' => 'This email can\'t be used. Please contact your branch.',
            'email.email' => 'Enter a valid email address.',
            'civil_status.in' => 'Choose single, married, widowed, separated or divorced.',
        ];

        foreach (['city', 'province', 'contact_number', 'civil_status'] as $field) {
            $messages["{$field}.required"] = 'The '.strtolower(self::FIELDS[$field]).' can\'t be left empty.';
        }

        $prefixed = [];

        foreach ($messages as $key => $message) {
            $prefixed[$prefix.$key] = $message;
        }

        return $prefixed;
    }

    /** A value as the member sees it: the §1 masking. */
    public function masked(string $field, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($field) {
            'contact_number' => PhilippineMobile::mask((string) $value),
            'email' => MemberContact::maskEmail((string) $value),
            'street_address' => self::ON_FILE,
            default => (string) $value,
        };
    }

    /**
     * The changes as rows, in the fields' order.
     *
     * @param  array<string, mixed>  $requested
     * @param  array<string, mixed>  $current
     * @return list<array{field: string, label: string, current: string|null, requested: string|null}>
     */
    public function changes(array $requested, array $current, bool $full): array
    {
        $rows = [];

        foreach (self::FIELDS as $field => $label) {
            if (! array_key_exists($field, $requested)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'label' => $label,
                'current' => $full ? $this->plain($current[$field] ?? null) : $this->masked($field, $current[$field] ?? null),
                'requested' => $full ? $this->plain($requested[$field]) : $this->masked($field, $requested[$field]),
            ];
        }

        return $rows;
    }

    private function plain(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
