<?php

namespace App\Services\Cda;

use App\Http\Resources\Cda\CdaPresenter;
use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\CdaOfficer;
use App\Models\CdaRegulatoryProfile;
use App\Models\CdaSetting;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\BalanceSheetBuilder;
use App\Services\Accounting\Money;
use App\Services\Cda\Sources\CoopSource;
use Throwable;

/**
 * The regulatory profile as the settings screen shows it (CDA design §3):
 * - `stored`: CDA's own fields, as saved;
 * - `pulled`: read-only values from elsewhere, each with its source: the
 *   name, address and contact (Settings › Branding), the active branch
 *   count, and the chairperson, general manager, secretary and treasurer
 *   serving today (the officers register; null when none is recorded);
 * - `asset_class_suggested`: the asset size the total assets as of today
 *   suggest, by the settings' bounds (null without books).
 */
final class RegulatoryProfileView
{
    public function __construct(private readonly BalanceSheetBuilder $balanceSheet) {}

    /** @return array<string, mixed> */
    public function present(CdaRegulatoryProfile $profile): array
    {
        $stored = [];

        foreach (CdaRegulatoryProfile::FIELDS as $field) {
            $value = $profile->{$field};
            $stored[$field] = in_array($field, CdaRegulatoryProfile::DATE_FIELDS, true) ? $value?->toDateString() : $value;
        }

        $stored['cte_status'] ??= 'none';
        $stored['asset_class_source'] ??= 'computed';

        return [
            'stored' => $stored,
            'pulled' => $this->pulled(),
            'asset_class_suggested' => $this->suggestedAssetClass(),
            'updated_by' => CdaPresenter::user($profile->updater()->first()),
            'updated_at' => $profile->updated_at,
        ];
    }

    /** @return array<string, array{value: mixed, source: string}> */
    private function pulled(): array
    {
        $branding = BrandingSetting::query()->orderBy('id')->first();
        $today = now()->toDateString();
        $officers = CdaOfficer::query()->servingOn($today)->whereIn('position', CdaOfficer::PROFILE_POSITIONS)
            ->with('member')->orderBy('term_start')->get()->groupBy('position');

        $pulled = [
            'name' => ['value' => $branding?->organization_name, 'source' => CoopSource::BRANDING_LABEL],
            'address' => ['value' => $branding?->organization_address, 'source' => CoopSource::BRANDING_LABEL],
            'contact' => ['value' => $branding?->organization_contact, 'source' => CoopSource::BRANDING_LABEL],
            'branch_count' => ['value' => Branch::query()->where('is_active', true)->count(), 'source' => 'Settings › Branches (active)'],
        ];

        foreach (CdaOfficer::PROFILE_POSITIONS as $position) {
            $officer = $officers->get($position)?->last();
            $pulled[$position] = ['value' => $officer?->displayName(), 'source' => 'CDA › Officers'];
        }

        return $pulled;
    }

    /** @return array{asset_class: string, total_assets: string, as_of: string, source: string}|null */
    private function suggestedAssetClass(): ?array
    {
        if (! AccountMap::chartExists()) {
            return null;
        }

        $asOf = now()->toDateString();

        try {
            $totalAssets = (int) ($this->balanceSheet->build($asOf)['total_assets'] ?? 0);
        } catch (Throwable) {
            return null;
        }

        $bounds = CdaSetting::current()->asset_class_thresholds ?? [];
        $class = 'large';

        foreach (['micro', 'small', 'medium'] as $candidate) {
            if (isset($bounds[$candidate]) && $totalAssets <= (int) Money::toCentavos((string) $bounds[$candidate])) {
                $class = $candidate;

                break;
            }
        }

        return [
            'asset_class' => $class,
            'total_assets' => CdaPresenter::money($totalAssets),
            'as_of' => $asOf,
            'source' => 'Accounting › Balance sheet',
        ];
    }
}
