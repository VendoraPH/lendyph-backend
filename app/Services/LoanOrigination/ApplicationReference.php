<?php

namespace App\Services\LoanOrigination;

use App\Models\OriginationApplication;
use App\Models\OriginationSetting;
use App\Services\GeneratedNumberWrite;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An application's reference number, `OA-YYYY-NNNNNN` (origination decision
 * 18): the settings' prefix, the submission year in Manila, then a number
 * that starts again at 1 each year for each prefix. Given at submit, once.
 *
 * Numbering is "the highest so far, plus one" under a lock, and the reference
 * column is unique, so call assign() inside
 * GeneratedNumberWrite::run(..., self::CONFLICT): two submits at the same
 * moment retry instead of failing (GeneratedNumberWrite::ORIGINATION_REFERENCE_INDEX).
 */
final class ApplicationReference
{
    public const CONFLICT = 'Another application was submitted at the same moment. Submit again.';

    public const PAD_LENGTH = 6;

    /**
     * Prefixes other Lendy records already use. An application prefix never
     * takes one, so a reference can't be mistaken for a loan or a receipt.
     */
    public const RESERVED_PREFIXES = ['LA', 'LN', 'RCP', 'ADJ', 'BRW', 'CMK', 'SC', 'SCV', 'MC', 'REQ', 'JE', 'TD', 'SA', 'SOA'];

    /**
     * The application's reference: its own if it has one, else the next one,
     * saved on the row.
     */
    public function assign(OriginationApplication $application): string
    {
        if ($application->reference_number !== null) {
            return $application->reference_number;
        }

        if (! $application->exists) {
            throw new LogicException('An application gets its reference number once it is saved.');
        }

        $prefix = sprintf(
            '%s-%d-',
            OriginationSetting::current()->reference_prefix,
            Carbon::parse($application->submitted_at ?? now())->setTimezone('Asia/Manila')->year,
        );

        $last = OriginationApplication::query()
            ->where('reference_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('reference_number')
            ->map(fn (string $number): int => (int) substr($number, strlen($prefix)))
            ->max() ?? 0;

        $application->reference_number = $prefix.str_pad((string) ($last + 1), self::PAD_LENGTH, '0', STR_PAD_LEFT);
        $application->save();

        return $application->reference_number;
    }
}
