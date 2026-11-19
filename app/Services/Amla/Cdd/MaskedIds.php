<?php

namespace App\Services\Amla\Cdd;

use App\Models\Borrower;
use App\Models\Document;
use App\Models\GCashNonMember;
use App\Rules\UniqueWalkInIdNumber;

/**
 * A subject's IDs as the CDD ID picker shows them (AMLA-B5, from the AMLA-F3a
 * report): a fixed type and the last four characters only. A member's valid
 * IDs come from their documents, each ID once (front and back are one ID: the
 * same type and number, the lowest document id kept); a walk-in's is the ID on
 * its record. The full number never leaves the server.
 */
final class MaskedIds
{
    /** @return list<array{document_id: int|null, id_type: string, id_type_words: string, id_number_masked: string|null}> */
    public static function of(Borrower|GCashNonMember $subject): array
    {
        if ($subject instanceof GCashNonMember) {
            if (blank($subject->id_type) || blank($subject->id_number)) {
                return [];
            }

            $type = IdTypes::fromWalkIn((string) $subject->id_type);

            return [self::row(null, $type, (string) $subject->id_number)];
        }

        $rows = [];
        $seen = [];

        foreach ($subject->documents()->where('type', 'valid_id')->orderBy('id')->get(['id', 'label', 'custom_type_name', 'id_number']) as $document) {
            /** @var Document $document */
            $type = IdTypes::fromDocument($document->label, $document->custom_type_name);
            $key = $document->label.'|'.UniqueWalkInIdNumber::normalise((string) $document->id_number);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = self::row((int) $document->id, $type, (string) $document->id_number);
        }

        return $rows;
    }

    /** @return array{document_id: int|null, id_type: string, id_type_words: string, id_number_masked: string|null} */
    private static function row(?int $documentId, string $type, string $number): array
    {
        $normalised = UniqueWalkInIdNumber::normalise($number);

        return [
            'document_id' => $documentId,
            'id_type' => $type,
            'id_type_words' => IdTypes::words($type),
            'id_number_masked' => $normalised === '' ? null : '****'.mb_substr($normalised, -4),
        ];
    }
}
