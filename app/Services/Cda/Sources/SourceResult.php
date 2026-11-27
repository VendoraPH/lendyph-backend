<?php

namespace App\Services\Cda\Sources;

use Illuminate\Support\Carbon;

/**
 * What a source gives back (CDA design §2): the value, a label saying where
 * it came from ("Members · as of Dec 31, 2026"), its unit, the date it is
 * as of (null for a figure that has no date, like the cooperative's name),
 * whether it may still change, and anything else a resolver wants to keep
 * with it (`meta`, stored with the value and in snapshots).
 */
final class SourceResult
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public readonly mixed $value,
        public readonly string $label,
        public readonly string $unit,
        public readonly ?string $asOf = null,
        public readonly bool $provisional = false,
        public readonly array $meta = [],
    ) {}

    /** "Members · as of Dec 31, 2026", or the label alone without a date. */
    public static function labelFor(string $what, ?string $asOf): string
    {
        return $asOf === null ? $what : $what.' · as of '.Carbon::parse($asOf)->format('M j, Y');
    }

    /** @return array{value: mixed, label: string, unit: string, as_of: string|null, provisional: bool, meta: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'unit' => $this->unit,
            'as_of' => $this->asOf,
            'provisional' => $this->provisional,
            'meta' => $this->meta,
        ];
    }
}
