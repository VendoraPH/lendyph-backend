<?php

namespace App\Services\Cic\Records;

use App\Models\CicCodeMapping;
use App\Models\CicProductMapping;
use App\Models\CicSetting;
use App\Models\CollateralType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What every builder reads once per build: the reference date, the settings,
 * branch codes, product mappings and collateral guarantee codes. Loaded in a
 * few small queries, never once per record.
 */
final class CicBuildContext
{
    /** CIC's limit on a branch code; a longer one is left blank, never cut. */
    public const BRANCH_CODE_MAX = 5;

    /**
     * The seeded collateral types' guarantee codes (GuaranteesDomain), until
     * staff map them: Land Title 201, Chattel 202, Stock Certificate 217, and
     * share capital (by its source) 234.
     *
     * @var array<string, string>
     */
    public const GUARANTEE_DEFAULTS = ['Land Title' => '201', 'Chattel' => '202', 'Stock Certificate' => '217'];

    public const SHARE_CAPITAL_GUARANTEE = '234';

    public readonly string $referenceDate;

    public readonly string $providerCode;

    public readonly bool $mfi;

    /** @var array<int, string> branch id → code, only codes CIC can take */
    public readonly array $branchCodes;

    /** @var array<int, CicProductMapping> loan product id → mapping */
    public readonly array $productMappings;

    /** @var array<int, string> loan product id → name, for issue messages */
    public readonly array $productNames;

    /** @var array<int, string> collateral type id → guarantee code */
    public readonly array $guaranteeCodes;

    /**
     * @param  array<int, true>  $previouslyActive  loans the previous complete month-end capture held as active (AC)
     * @param  array<int, true>  $forced  loans built whatever their state, for a correction's re-read
     */
    public function __construct(
        public readonly Carbon $reference,
        public readonly array $previouslyActive = [],
        public readonly array $forced = [],
    ) {
        $setting = CicSetting::current();

        $this->referenceDate = CicFormat::date($reference);
        $this->providerCode = CicFormat::text($setting->provider_code);
        $this->mfi = (bool) $setting->is_mfi;

        $this->branchCodes = DB::table('branches')->pluck('code', 'id')
            ->map(fn (?string $code) => CicFormat::text($code))
            ->filter(fn (string $code) => $code !== '' && mb_strlen($code) <= self::BRANCH_CODE_MAX)
            ->all();

        $this->productMappings = CicProductMapping::query()->get()->keyBy('loan_product_id')->all();
        $this->productNames = DB::table('loan_products')->pluck('name', 'id')->map(fn (?string $name) => (string) $name)->all();

        $mapped = CicCodeMapping::query()
            ->where('domain', CicCodeMapping::DOMAIN_COLLATERAL_TYPE)
            ->pluck('cic_code', 'lendy_value')
            ->mapWithKeys(fn (mixed $code, int|string $typeId) => [(int) $typeId => (string) $code])
            ->all();

        $defaults = [];

        foreach (CollateralType::query()->get(['id', 'name', 'source']) as $type) {
            $default = $type->source === 'share_capital'
                ? self::SHARE_CAPITAL_GUARANTEE
                : (self::GUARANTEE_DEFAULTS[$type->name] ?? null);

            if ($default !== null) {
                $defaults[$type->id] = $default;
            }
        }

        $this->guaranteeCodes = array_replace($defaults, $mapped);
    }

    public function branchCode(?int $branchId): string
    {
        return $branchId === null ? '' : ($this->branchCodes[$branchId] ?? '');
    }

    /** Whether the previous complete month-end capture held the loan as active. */
    public function wasActive(int $loanId): bool
    {
        return isset($this->previouslyActive[$loanId]);
    }

    public function isForced(int $loanId): bool
    {
        return isset($this->forced[$loanId]);
    }

    /** The reference date's last moment, for "on or before" checks on datetimes. */
    public function endOfReferenceDay(): Carbon
    {
        return $this->reference->copy()->endOfDay();
    }
}
