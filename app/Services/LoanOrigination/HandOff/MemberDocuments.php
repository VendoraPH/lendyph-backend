<?php

namespace App\Services\LoanOrigination\HandOff;

use App\Models\OriginationDocument;
use App\Services\Amla\Cdd\IdTypes;
use App\Services\ValidIdService;

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
