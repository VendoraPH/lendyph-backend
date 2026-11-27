<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A member's or walk-in's AMLA profile (AMLA design §4.8; task §10): the KYC
 * answers the member record doesn't hold, linked to that record and never a
 * second customer profile. Its subject never changes and it is never deleted;
 * the KYC answers, review dates and enhanced due diligence date may change,
 * each change kept in AmlaProfileChange. PEP and adverse-information details
 * are encrypted at rest.
 *
 * @property int $id
 * @property string $subject_type member|walk_in
 * @property int|null $borrower_id
 * @property int|null $gcash_non_member_id
 * @property string|null $nationality ISO 3166-1 alpha-2
 * @property string|null $occupation
 * @property string|null $source_of_funds
 * @property string|null $source_of_funds_other
 * @property string|null $purpose
 * @property int|null $expected_monthly_centavos
 * @property int|null $expected_monthly_count
 * @property string|null $pep_status
 * @property string|null $pep_details
 * @property bool|null $adverse_info
 * @property string|null $adverse_info_note
 * @property Carbon|null $last_review_on
 * @property Carbon|null $next_review_on
 * @property Carbon|null $edd_required_since
 */
class AmlaProfile extends Model
{
    use AmlaKeptForever;

    public const MEMBER = 'member';

    public const WALK_IN = 'walk_in';

    /** @var list<string> the KYC answers staff edit, in the order the API sends them */
    public const KYC_FIELDS = [
        'nationality', 'occupation', 'source_of_funds', 'source_of_funds_other', 'purpose',
        'expected_monthly_centavos', 'expected_monthly_count', 'pep_status', 'pep_details',
        'adverse_info', 'adverse_info_note',
    ];

    /** @var array<string, string> */
    public const PEP_STATUSES = [
        'not_pep' => 'Not a politically exposed person',
        'pep' => 'Politically exposed person',
        'pep_family' => 'Family member of a politically exposed person',
        'pep_associate' => 'Close associate of a politically exposed person',
    ];

    /** The columns that may change after creation; the subject never does. */
    private const MUTABLE = [
        ...self::KYC_FIELDS,
        'last_review_on', 'next_review_on', 'edd_required_since', 'updated_by', 'updated_at',
    ];

    protected $fillable = [
        'subject_type', 'borrower_id', 'gcash_non_member_id', ...self::KYC_FIELDS,
        'last_review_on', 'next_review_on', 'edd_required_since', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'borrower_id' => 'integer',
            'gcash_non_member_id' => 'integer',
            'expected_monthly_centavos' => 'integer',
            'expected_monthly_count' => 'integer',
            'pep_details' => 'encrypted',
            'adverse_info' => 'boolean',
            'adverse_info_note' => 'encrypted',
            'last_review_on' => 'date',
            'next_review_on' => 'date',
            'edd_required_since' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** Only the KYC answers, review dates and EDD date, never the subject. */
    protected function amlaAllowsUpdate(): bool
    {
        return array_diff(array_keys($this->getDirty()), self::MUTABLE) === [];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function walkIn(): BelongsTo
    {
        return $this->belongsTo(GCashNonMember::class, 'gcash_non_member_id')->withTrashed();
    }

    public function profileChanges(): HasMany
    {
        return $this->hasMany(AmlaProfileChange::class, 'profile_id');
    }

    public function idVerifications(): HasMany
    {
        return $this->hasMany(AmlaIdVerification::class, 'profile_id');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(AmlaRiskAssessment::class, 'profile_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AmlaReview::class, 'profile_id');
    }
}
