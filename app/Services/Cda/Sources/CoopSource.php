<?php

namespace App\Services\Cda\Sources;

use App\Models\Branch;
use App\Models\BrandingSetting;
use App\Models\User;

/**
 * The cooperative's own figures (CDA design §2): its name, address and
 * contact from Settings › Branding, its branches, and its system users (a
 * hint only: employment is entered by hand). Current values, with no as-of
 * date; a finalized report keeps them in its snapshot.
 */
final class CoopSource implements CdaSource
{
    public const BRANDING_LABEL = 'Settings › Branding';

    public function resolve(string $key, array $params, SourceContext $context): SourceResult
    {
        return match ($key) {
            'coop.name' => $this->branding('organization_name'),
            'coop.address' => $this->branding('organization_address'),
            'coop.contact' => $this->branding('organization_contact'),
            'branches.count' => new SourceResult(Branch::query()->where('is_active', true)->count(), 'Settings › Branches (active)', 'count'),
            'branches.list' => new SourceResult(
                Branch::query()->where('is_active', true)->orderBy('id')->get(['name', 'code', 'address'])
                    ->map(fn (Branch $branch) => ['name' => $branch->name, 'code' => $branch->code, 'address' => $branch->address])
                    ->all(),
                'Settings › Branches (active)',
                'table',
            ),
            'users.count' => new SourceResult(
                User::query()->when($params['active'] ?? true, fn ($query) => $query->where('status', 'active'))->count(),
                ($params['active'] ?? true) ? 'Users (active)' : 'Users',
                'count',
            ),
            default => throw SourceUnavailable::unknownKey($key, 'coop'),
        };
    }

    private function branding(string $field): SourceResult
    {
        $value = BrandingSetting::query()->orderBy('id')->value($field);

        return new SourceResult(filled($value) ? (string) $value : null, self::BRANDING_LABEL, 'text');
    }
}
