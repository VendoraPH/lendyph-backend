<?php

namespace App\Models;

use Database\Factories\CicCodeMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Lendy value mapped to a CIC code, by domain: `collateral_type` (the value
 * is a collateral_types id) or `id_kind` (a document label or kind). The CIC
 * codes are the read-only lists in resources/cic/2016/domains.json
 * (App\Services\Cic\CicDomains); one mapping per domain and value.
 * `cic_domain` names the code list the code is from: GuaranteesDomain for a
 * collateral type, IDTypeDomain or IdentificationTypeDomain for an ID kind
 * (their codes overlap).
 *
 * @property int $id
 * @property string $domain
 * @property string $lendy_value
 * @property string|null $cic_domain
 * @property string $cic_code
 * @property int $updated_by
 */
class CicCodeMapping extends Model
{
    /** @use HasFactory<CicCodeMappingFactory> */
    use HasFactory;

    public const DOMAIN_COLLATERAL_TYPE = 'collateral_type';

    public const DOMAIN_ID_KIND = 'id_kind';

    /** @var list<string> */
    public const DOMAINS = [self::DOMAIN_COLLATERAL_TYPE, self::DOMAIN_ID_KIND];

    protected $fillable = ['domain', 'lendy_value', 'cic_domain', 'cic_code'];

    protected function casts(): array
    {
        return ['updated_by' => 'integer'];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
