<?php

namespace App\Services\Cic\Settings;

use App\Models\CicCodeMapping;
use App\Models\CollateralType;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Cic\CicDomains;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Lendy values mapped to CIC codes (prompt §3.2), two domains:
 *
 * - `collateral_type`: each collateral type, by id, to a GuaranteesDomain code;
 * - `id_kind`: each valid-ID kind (CicIdKinds) to an IDTypeDomain or
 *   IdentificationTypeDomain code. Their codes overlap, so each mapping keeps
 *   its code list in `cic_domain`.
 *
 * The listing proposes defaults (`suggested_code`) for unmapped values; a
 * suggestion is never saved by itself. A save with a null code removes the
 * mapping. Each real change writes one audit row in words.
 */
final class CicCodeMappingService
{
    public const GUARANTEES = 'GuaranteesDomain';

    public const ID_TYPES = 'IDTypeDomain';

    public const IDENTIFICATION_TYPES = 'IdentificationTypeDomain';

    /** @var array<string, list<string>> the code lists each domain may map to */
    public const CODE_LISTS = [
        CicCodeMapping::DOMAIN_COLLATERAL_TYPE => [self::GUARANTEES],
        CicCodeMapping::DOMAIN_ID_KIND => [self::ID_TYPES, self::IDENTIFICATION_TYPES],
    ];

    /** Proposed guarantee codes, by the seeded collateral type's name (lower case). */
    private const SUGGESTED_COLLATERAL = [
        'land title' => '201',
        'chattel' => '202',
        'share capital' => '234',
        'stock certificate' => '217',
    ];

    /** @var array<string, array{0: string, 1: string}> proposed code list and code, by ID kind */
    private const SUGGESTED_ID_KIND = [
        'tin_id' => [self::IDENTIFICATION_TYPES, '10'],
        'sss' => [self::IDENTIFICATION_TYPES, '11'],
        'umid' => [self::IDENTIFICATION_TYPES, '15'],
        'philippine_id' => [self::ID_TYPES, '32'],
        'drivers_license' => [self::ID_TYPES, '10'],
        'passport' => [self::ID_TYPES, '12'],
        'prc_id' => [self::ID_TYPES, '13'],
        'postal_id' => [self::ID_TYPES, '16'],
        'voters_id' => [self::ID_TYPES, '11'],
    ];

    private const DOMAIN_WORDS = [
        CicCodeMapping::DOMAIN_COLLATERAL_TYPE => 'collateral type',
        CicCodeMapping::DOMAIN_ID_KIND => 'ID kind',
    ];

    public const BUSY = 'Someone else saved CIC code mappings at the same moment. Reload the page and try again.';

    /** @return array<string, mixed> */
    public function listing(): array
    {
        $saved = CicCodeMapping::query()->get()->groupBy('domain');

        return [
            CicCodeMapping::DOMAIN_COLLATERAL_TYPE => $this->rows(CicCodeMapping::DOMAIN_COLLATERAL_TYPE, $saved->get(CicCodeMapping::DOMAIN_COLLATERAL_TYPE, collect())->keyBy('lendy_value')->all()),
            CicCodeMapping::DOMAIN_ID_KIND => $this->rows(CicCodeMapping::DOMAIN_ID_KIND, $saved->get(CicCodeMapping::DOMAIN_ID_KIND, collect())->keyBy('lendy_value')->all()),
            'code_lists' => [
                self::GUARANTEES => CicDomains::codes(self::GUARANTEES),
                self::ID_TYPES => CicDomains::codes(self::ID_TYPES),
                self::IDENTIFICATION_TYPES => CicDomains::codes(self::IDENTIFICATION_TYPES),
            ],
        ];
    }

    /**
     * Each Lendy value of a domain, by value, with its name (the values a
     * mapping may name).
     *
     * @return array<string, string>
     */
    public function values(string $domain): array
    {
        if ($domain === CicCodeMapping::DOMAIN_COLLATERAL_TYPE) {
            return CollateralType::query()->orderBy('display_order')->orderBy('id')->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])->all();
        }

        $values = [];

        foreach (CicIdKinds::all() as $kind) {
            $values[$kind['value']] = $kind['name'];
        }

        // A saved mapping whose "others" name no document carries now stays
        // listed, so it can still be cleared.
        foreach (CicCodeMapping::query()->where('domain', CicCodeMapping::DOMAIN_ID_KIND)->pluck('lendy_value') as $value) {
            $values[$value] ??= $value;
        }

        return $values;
    }

    /**
     * Saves the listed mappings; a null code removes one.
     *
     * @param  list<array{domain: string, lendy_value: string, cic_domain?: string|null, cic_code: string|null}>  $mappings  validated
     */
    public function save(array $mappings, User $user): void
    {
        $names = [
            CicCodeMapping::DOMAIN_COLLATERAL_TYPE => $this->values(CicCodeMapping::DOMAIN_COLLATERAL_TYPE),
            CicCodeMapping::DOMAIN_ID_KIND => $this->values(CicCodeMapping::DOMAIN_ID_KIND),
        ];

        try {
            DB::transaction(function () use ($mappings, $user, $names) {
                foreach ($mappings as $entry) {
                    $this->saveOne($entry, $user, $names[$entry['domain']][$entry['lendy_value']] ?? $entry['lendy_value']);
                }
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, self::BUSY);
        }
    }

    /** The code list a mapping's code is read from: collateral types always map to the guarantees list. */
    public static function codeListFor(string $domain, ?string $cicDomain): ?string
    {
        return $domain === CicCodeMapping::DOMAIN_COLLATERAL_TYPE ? self::GUARANTEES : $cicDomain;
    }

    /** @param  array{domain: string, lendy_value: string, cic_domain?: string|null, cic_code: string|null}  $entry */
    private function saveOne(array $entry, User $user, string $name): void
    {
        $domain = $entry['domain'];
        $code = $entry['cic_code'] ?? null;
        $codeList = $code === null ? null : self::codeListFor($domain, $entry['cic_domain'] ?? null);

        /** @var CicCodeMapping|null $mapping */
        $mapping = CicCodeMapping::query()->where('domain', $domain)->where('lendy_value', $entry['lendy_value'])->lockForUpdate()->first();
        $before = $mapping === null ? null : self::words($mapping->cic_domain, $mapping->cic_code);
        $after = $code === null ? null : self::words($codeList, $code);
        $subject = self::DOMAIN_WORDS[$domain]." \"{$name}\"";

        if ($code === null) {
            if ($mapping === null) {
                return;
            }

            AuditLogService::log('deleted', $mapping, ['value' => $name, 'cic_code' => $before], null,
                "CIC code for {$subject} removed (was {$before}).", $user->id);
            $mapping->delete();

            return;
        }

        if ($mapping !== null && $mapping->cic_code === $code && $mapping->cic_domain === $codeList) {
            return;
        }

        $mapping ??= new CicCodeMapping(['domain' => $domain, 'lendy_value' => $entry['lendy_value']]);
        $mapping->fill(['cic_domain' => $codeList, 'cic_code' => $code]);
        $mapping->forceFill(['updated_by' => $user->id])->save();

        AuditLogService::log(
            $before === null ? 'created' : 'updated',
            $mapping,
            $before === null ? null : ['value' => $name, 'cic_code' => $before],
            ['value' => $name, 'cic_code' => $after],
            "CIC code for {$subject}: ".($before ?? 'none').' → '.$after.'.',
            $user->id,
        );
    }

    /** "201 Real Estate Mortgage - Not insured by HGC (Guarantees)". */
    private static function words(?string $codeList, ?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $label = $codeList === null || ! CicDomains::has($codeList) ? null : CicDomains::label($codeList, $code);
        $list = match ($codeList) {
            self::GUARANTEES => 'Guarantees',
            self::ID_TYPES => 'ID types',
            self::IDENTIFICATION_TYPES => 'Identification types',
            default => null,
        };

        return trim($code.($label === null ? '' : " {$label}").($list === null ? '' : " ({$list})"));
    }

    /**
     * @param  array<string, CicCodeMapping>  $saved  by lendy value
     * @return list<array<string, mixed>>
     */
    private function rows(string $domain, array $saved): array
    {
        $rows = [];
        $standard = CicIdKinds::standard();

        foreach ($this->values($domain) as $value => $name) {
            $value = (string) $value;
            $mapping = $saved[$value] ?? null;
            [$suggestedList, $suggestedCode] = $mapping === null ? $this->suggestion($domain, $value, $name) : [null, null];
            $codeList = $mapping === null ? null : self::codeListFor($domain, $mapping->cic_domain);

            $row = [
                'lendy_value' => $value,
                'name' => $name,
                'cic_domain' => $codeList,
                'cic_code' => $mapping?->cic_code,
                'cic_label' => $mapping === null || $codeList === null ? null : CicDomains::label($codeList, $mapping->cic_code),
                'suggested_domain' => $suggestedList,
                'suggested_code' => $suggestedCode,
                'suggested_label' => $suggestedCode === null ? null : CicDomains::label((string) $suggestedList, $suggestedCode),
            ];

            if ($domain === CicCodeMapping::DOMAIN_ID_KIND) {
                $row['standard'] = array_key_exists($value, $standard);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function suggestion(string $domain, string $value, string $name): array
    {
        if ($domain === CicCodeMapping::DOMAIN_COLLATERAL_TYPE) {
            $code = self::SUGGESTED_COLLATERAL[mb_strtolower(trim($name))] ?? null;

            return $code === null ? [null, null] : [self::GUARANTEES, $code];
        }

        return self::SUGGESTED_ID_KIND[$value] ?? [null, null];
    }
}
