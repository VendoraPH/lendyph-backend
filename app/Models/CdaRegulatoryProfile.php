<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaRegulatoryProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The cooperative's CDA-only details, one row (CDA design §1): registration,
 * type and category, asset class, TIN, the Certificate of Tax Exemption, a
 * structured address and contact, sector and satellite offices. The name,
 * address and contact printed by default come from BrandingSetting, the
 * branch count from branches and the officers from the officers register;
 * a finalized report freezes all of it in its snapshot. Audited.
 *
 * @property string $cte_status
 * @property Carbon|null $cte_expires_on
 */
class CdaRegulatoryProfile extends Model
{
    /** @use HasFactory<CdaRegulatoryProfileFactory> */
    use Auditable, HasFactory;

    public const CATEGORIES = ['primary', 'secondary', 'tertiary', 'federation', 'union'];

    public const ASSET_CLASSES = ['micro', 'small', 'medium', 'large'];

    public const ASSET_CLASS_SOURCES = ['computed', 'manual'];

    public const CTE_STATUSES = ['none', 'valid', 'pending', 'expired'];

    /** The stored fields, in the order the API sends them. */
    public const FIELDS = [
        'previous_name', 'cda_registration_no', 'cda_registration_date', 'cda_region', 'cda_extension_office',
        'cooperative_type', 'cooperative_category', 'asset_class', 'asset_class_source', 'tin',
        'cte_status', 'cte_number', 'cte_issued_on', 'cte_expires_on',
        'address_line', 'address_barangay', 'address_city', 'address_province', 'address_region',
        'phone', 'email', 'website', 'primary_activity', 'sector', 'subsector', 'satellite_offices',
        'authorized_rep_officer_id',
    ];

    public const DATE_FIELDS = ['cda_registration_date', 'cte_issued_on', 'cte_expires_on'];

    protected $fillable = [...self::FIELDS, 'updated_by'];

    protected function casts(): array
    {
        return [
            'cda_registration_date' => 'date',
            'cte_issued_on' => 'date',
            'cte_expires_on' => 'date',
            'satellite_offices' => 'integer',
            'authorized_rep_officer_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** The profile row, created empty if a database lost it. */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? static::query()->create([]);
    }

    /**
     * Whether a Certificate of Tax Exemption covers a period starting on this
     * date (design §6): `valid`, and no expiry or one on or after the start.
     */
    public function cteCovers(string $periodStart): bool
    {
        return $this->cte_status === 'valid'
            && ($this->cte_expires_on === null || $this->cte_expires_on->toDateString() >= $periodStart);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
