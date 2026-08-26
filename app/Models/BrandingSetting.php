<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class BrandingSetting extends Model
{
    protected $fillable = [
        'logo_path',
        'organization_name',
        'organization_address',
        'organization_contact',
    ];

    /**
     * Fetch the single branding row, creating it on first access.
     *
     * The app is single-tenant-per-deployment (one cooperative per instance),
     * so branding is a global singleton — every read/write goes through this
     * one row.
     */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /**
     * Absolute, host-portable public URL for the stored logo, or null when unset.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->logo_path
                ? Storage::disk('public')->url($this->logo_path)
                : null,
        );
    }
}
