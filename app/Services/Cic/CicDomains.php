<?php

namespace App\Services\Cic;

use InvalidArgumentException;

/**
 * CIC's code lists (resources/cic/2016/domains.json, from CIC's
 * `Fields in Excel.xlsx` as encoded in frontend PR #699), each code with its
 * label (null where the workbook has none). Read-only: the official codes are
 * never edited here; Lendy values are mapped to them (CicCodeMapping).
 * Contract type and status lists differ per record, so their names carry the
 * record ("CI:ContractTypeDomain"), as a layout field's `domain` does. PSIC
 * and PSOC aren't bundled.
 */
final class CicDomains
{
    public const PATH = 'cic/2016/domains.json';

    /** @var array<string, list<array{code: string, label: string|null}>>|null */
    private static ?array $domains = null;

    /** @return array<string, list<array{code: string, label: string|null}>> */
    public static function all(): array
    {
        if (self::$domains === null) {
            /** @var array<string, list<array{code: string, label: string|null}>> $domains */
            $domains = json_decode((string) file_get_contents(resource_path(self::PATH)), true, flags: JSON_THROW_ON_ERROR);
            self::$domains = $domains;
        }

        return self::$domains;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $domain): bool
    {
        return isset(self::all()[$domain]);
    }

    /** @return list<array{code: string, label: string|null}> */
    public static function codes(string $domain): array
    {
        return self::all()[$domain] ?? throw new InvalidArgumentException("There is no CIC domain {$domain}.");
    }

    /** Whether a code is in the domain (exact, case included). */
    public static function contains(string $domain, string $code): bool
    {
        return in_array($code, array_column(self::codes($domain), 'code'), true);
    }

    /** A code's label, or null when the code has none or isn't in the domain. */
    public static function label(string $domain, string $code): ?string
    {
        foreach (self::codes($domain) as $entry) {
            if ($entry['code'] === $code) {
                return $entry['label'];
            }
        }

        return null;
    }
}
