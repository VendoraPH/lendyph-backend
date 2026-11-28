<?php

namespace App\Services\Cic\Submissions;

use App\Models\CicCapture;
use App\Models\CicCaptureRecord;

/**
 * The records a correction changes (prompt §14.3): each record of the
 * correction capture whose checksum differs from the corrected file's capture,
 * or that the corrected file's capture doesn't hold. Both captures are frozen,
 * so the list never changes. Record types and keys only.
 */
final class CicCorrectionDiff
{
    public const CHANGED = 'changed';

    public const ADDED = 'added';

    /** @return list<array{record_type: string, record_key: string, change: string}> */
    public static function affected(CicCapture $correction, CicCapture $original): array
    {
        $before = [];

        foreach (self::rows($original) as $row) {
            $before[$row->record_type."\0".$row->record_key] = $row->checksum;
        }

        $affected = [];

        foreach (self::rows($correction) as $row) {
            $was = $before[$row->record_type."\0".$row->record_key] ?? null;

            if ($was === $row->checksum) {
                continue;
            }

            $affected[] = [
                'record_type' => $row->record_type,
                'record_key' => $row->record_key,
                'change' => $was === null ? self::ADDED : self::CHANGED,
            ];
        }

        return $affected;
    }

    /** @return iterable<object{record_type: string, record_key: string, checksum: string}> */
    private static function rows(CicCapture $capture): iterable
    {
        $order = array_flip(CicCapture::RECORD_TYPES);
        $rows = CicCaptureRecord::query()->toBase()
            ->where('capture_id', $capture->id)
            ->orderBy('record_key')
            ->get(['record_type', 'record_key', 'checksum'])
            ->all();

        usort($rows, fn (object $a, object $b) => ($order[$a->record_type] ?? 99) <=> ($order[$b->record_type] ?? 99));

        return $rows;
    }
}
