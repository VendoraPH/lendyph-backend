<?php

namespace App\Services\Cda\Exports;

use App\Models\CdaReportSnapshot;

/**
 * Reads a finalized report's snapshot payload (CDA design §5) for the
 * exports and the PESOS trend. The payload is C2's canonical JSON; this is the
 * one place C4 reads it, so a change of shape is fixed here.
 *
 * - `values`: a list of `{field_key, value, …}`, or a map of field_key →
 *   value (or → `{value, …}`).
 * - `profile`: RegulatoryProfileView::present() as frozen at finalize:
 *   `stored` (the CDA profile fields) and `pulled` (`name`, `address`,
 *   `contact`, … each `{value, source}`).
 * - `template`: optionally `{version, definition}`; otherwise the snapshot's
 *   `template_id` names the template, which is immutable once active.
 *
 * Nothing here falls back to live data: a figure the snapshot doesn't hold
 * reads as missing, so a later member or Branding change never shows in a
 * finalized export.
 */
final class SnapshotReader
{
    /** @return array<string, mixed> */
    public static function payload(CdaReportSnapshot $snapshot): array
    {
        $payload = json_decode((string) $snapshot->getAttribute('payload'), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> field_key → value
     */
    public static function values(array $payload): array
    {
        $values = $payload['values'] ?? [];

        if (! is_array($values)) {
            return [];
        }

        $out = [];

        if (array_is_list($values)) {
            foreach ($values as $row) {
                if (is_array($row) && isset($row['field_key'])) {
                    $out[(string) $row['field_key']] = $row['value'] ?? null;
                }
            }

            return $out;
        }

        foreach ($values as $key => $value) {
            $out[(string) $key] = is_array($value) && ! array_is_list($value) && array_key_exists('value', $value) ? $value['value'] : $value;
        }

        return $out;
    }

    /**
     * The stored CDA profile fields (cda_registration_no, tin, …).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function profile(array $payload): array
    {
        $stored = $payload['profile']['stored'] ?? [];

        return is_array($stored) ? $stored : [];
    }

    /**
     * A pulled profile value (name, address, contact) as frozen.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function pulled(array $payload, string $name): mixed
    {
        $pulled = $payload['profile']['pulled'][$name] ?? null;

        return is_array($pulled) ? ($pulled['value'] ?? null) : $pulled;
    }

    /**
     * The template definition frozen in the payload, if it holds one.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function definition(array $payload): ?array
    {
        $definition = $payload['template']['definition'] ?? null;

        return is_array($definition) ? $definition : null;
    }
}
