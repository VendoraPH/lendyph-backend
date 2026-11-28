<?php

namespace App\Models;

use App\Services\Cic\CicSwitch;
use Database\Factories\CicSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CIC reporting's settings, one row per deployment (current()). The format
 * version is fixed at the 2016 CSDF's "1.0" and is never mass-assigned: it is
 * shown, not edited, in v1.
 *
 * @property int $id
 * @property string|null $provider_code
 * @property string $format_version
 * @property bool $is_mfi
 * @property string|null $institution_name
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property int|null $updated_by
 */
class CicSetting extends Model
{
    /** @use HasFactory<CicSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'provider_code', 'is_mfi', 'institution_name', 'contact_name', 'contact_email', 'contact_phone',
    ];

    protected $attributes = [
        'format_version' => '1.0',
        'is_mfi' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_mfi' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    /** The deployment's settings, made with the defaults the first time. */
    public static function current(): self
    {
        $setting = static::query()->orderBy('id')->first();

        if ($setting !== null) {
            return $setting;
        }

        $setting = new static;
        $setting->format_version = CicSwitch::formatVersion();
        $setting->save();

        return $setting;
    }

    /** Whether a provider code is set (the file can't be made without one). */
    public function hasProviderCode(): bool
    {
        return is_string($this->provider_code) && trim($this->provider_code) !== '';
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
