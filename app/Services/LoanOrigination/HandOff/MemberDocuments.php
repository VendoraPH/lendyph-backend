<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Models\OriginationDocument;
use App\Services\Amla\Cdd\IdTypes;
use App\Services\ValidIdService;
use Illuminate\Validation\Rule;

/**
 * How an online application's documents become the member's at the hand-off
 * (origination decision 11): each application document type is filed under
 * the member document type it maps to.
 *
 * - An applicant's ID (`government_id`) becomes the member's valid ID
 *   (`valid_id`, ValidIdService), which approving their registration needs.
 *   It carries the ID type and number staff recorded when they verified it:
 *   the type as one of the member valid ID type keys (IdTypes, the frontend's
 *   VALID_ID_OPTIONS), its name for "others", and the number, which a
 *   member's valid ID always has (round fz).
 * - Every other type keeps its own name, as a member document type.
 */
final class MemberDocuments
{
    /** Application document type => member document type, where they differ. */
    public const MEMBER_TYPES = [
        'government_id' => ValidIdService::DOCUMENT_TYPE,
    ];

    public static function memberType(string $applicationType): string
    {
        return self::MEMBER_TYPES[$applicationType] ?? $applicationType;
    }

    /** Whether documents of this application type become the member's valid ID. */
    public static function isId(string $applicationType): bool
    {
        return self::memberType($applicationType) === ValidIdService::DOCUMENT_TYPE;
    }

    /**
     * What verifying an ID takes (LO-B3's verify): the ID type, one of the
     * member valid ID type keys, its name for "others", and the number, as a
     * member's valid ID upload takes them (ValidIdService).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'id_type' => ['required', 'string', Rule::in(array_keys(IdTypes::TYPES))],
            'custom_type_name' => ['nullable', 'string', 'max:100', 'required_if:id_type,'.IdTypes::OTHERS],
            'id_number' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'id_type.required' => 'Choose the ID type.',
            'id_type.string' => 'Choose the ID type from the list.',
            'id_type.in' => 'Choose the ID type from the list.',
            'custom_type_name.required_if' => 'Enter the ID type\'s name.',
            'custom_type_name.string' => 'The ID type\'s name must be text.',
            'custom_type_name.max' => 'The ID type\'s name can be at most 100 characters.',
            'id_number.required' => 'Enter the ID number.',
            'id_number.string' => 'The ID number must be text.',
            'id_number.max' => 'The ID number can be at most 100 characters.',
        ];
    }

    /**
     * The ID details to record from input validated by rules(), trimmed;
     * the type's name is kept for "others" only.
     *
     * @param  array<string, mixed>  $validated
     * @return array{type: string, custom_type_name: ?string, id_number: string}
     */
    public static function fromInput(array $validated): array
    {
        $type = (string) $validated['id_type'];

        return [
            'type' => $type,
            'custom_type_name' => $type === IdTypes::OTHERS ? trim((string) ($validated['custom_type_name'] ?? '')) : null,
            'id_number' => trim((string) $validated['id_number']),
        ];
    }

    /**
     * The ID type and number an ID document recorded when it was verified,
     * or null when either is missing.
     *
     * @return array{type: string, custom_type_name: ?string, id_number: string}|null
     */
    public static function idDetails(OriginationDocument $document): ?array
    {
        $details = is_array($document->id_details) ? $document->id_details : [];
        $type = trim((string) ($details['type'] ?? ''));
        $custom = trim((string) ($details['custom_type_name'] ?? ''));
        $number = trim((string) ($details['id_number'] ?? ''));

        if (! array_key_exists($type, IdTypes::TYPES) || $number === '' || ($type === IdTypes::OTHERS && $custom === '')) {
            return null;
        }

        return ['type' => $type, 'custom_type_name' => $type === IdTypes::OTHERS ? $custom : null, 'id_number' => $number];
    }

    /**
     * The refusal for a verified ID document that recorded no ID type and
     * number, by its label.
     */
    public static function unrecordedIdMessage(string $label): string
    {
        return "{$label} was verified without its ID type and number. Request a replacement and verify the new file with them.";
    }
}
