<?php

namespace App\Services\Cic\Settings;

use App\Models\CicSetting;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

/**
 * Saves CIC's lender settings (prompt §3.1): the provider code, MFI status,
 * institution name and contact. The format version is fixed and never saved
 * here. Each real change writes one audit row with the old and new values of
 * the fields that changed; these are the lender's own settings, not member
 * data, so the values are kept. A save that changes nothing writes nothing.
 */
final class CicSettingsService
{
    private const WORDS = [
        'provider_code' => 'provider code',
        'is_mfi' => 'MFI status',
        'institution_name' => 'institution name',
        'contact_name' => 'contact name',
        'contact_email' => 'contact email',
        'contact_phone' => 'contact phone',
    ];

    /** @param  array<string, mixed>  $changes  validated fields, provider code already upper-case */
    public function update(array $changes, User $user): CicSetting
    {
        return DB::transaction(function () use ($changes, $user) {
            $current = CicSetting::current();
            $setting = CicSetting::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $setting->fill(array_intersect_key($changes, self::WORDS));
            $dirty = array_keys(array_intersect_key($setting->getDirty(), self::WORDS));

            if ($dirty === []) {
                return $setting;
            }

            $old = [];
            $new = [];

            foreach ($dirty as $field) {
                $old[$field] = $setting->getOriginal($field);
                $new[$field] = $setting->getAttribute($field);
            }

            $setting->forceFill(['updated_by' => $user->id])->save();

            AuditLogService::log(
                'updated',
                $setting,
                $old,
                $new,
                'CIC settings changed: '.implode(', ', array_map(fn (string $field) => self::WORDS[$field], $dirty)).'.',
                $user->id,
            );

            return $setting;
        });
    }
}
