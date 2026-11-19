<?php

namespace App\Models;

use App\Services\Amla\Screening\NameNormaliser;
use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listed person or entity in a watchlist version, as the list gives it:
 * its reference, kind, name, aliases, birth years and nationalities.
 * `fingerprint` identifies the same listing across versions (source,
 * reference, names and birth years), so a rescreen never repeats a match on
 * an unchanged listing. Kept as written.
 *
 * @property int $id
 * @property int $version_id
 * @property string $reference
 * @property string $kind individual|entity
 * @property string $full_name
 * @property list<string> $aliases
 * @property list<int> $birth_years
 * @property list<string> $nationalities
 * @property string $fingerprint
 */
class AmlaWatchlistEntry extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const KINDS = ['individual' => 'Individual', 'entity' => 'Entity'];

    protected $fillable = ['version_id', 'reference', 'kind', 'full_name', 'aliases', 'birth_years', 'nationalities', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'version_id' => 'integer',
            'aliases' => 'array',
            'birth_years' => 'array',
            'nationalities' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The listed name and every alias, plus each one's form without its
     * middle names (first and last word), as people are often written
     * (AMLA-B5 review).
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_values(array_filter([$this->full_name, ...$this->aliases], fn ($name) => trim((string) $name) !== ''));

        foreach ($names as $name) {
            $words = NameNormaliser::words($name);

            if (count($words) >= 3) {
                $names[] = $words[0].' '.$words[count($words) - 1];
            }
        }

        return array_values(array_unique($names));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AmlaWatchlistVersion::class, 'version_id');
    }
}
