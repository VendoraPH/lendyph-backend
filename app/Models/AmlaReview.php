<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CDD review (AMLA design §4.10; task §10): a periodic review, an
 * enhanced due diligence review, or a change-of-circumstance review, with its
 * outcome and the next review date it set. Kept as written; notes are
 * encrypted.
 *
 * @property int $id
 * @property int $profile_id
 * @property string $kind
 * @property string $outcome
 * @property string|null $notes
 * @property Carbon $reviewed_on
 * @property Carbon $next_review_on
 * @property string|null $level_at_review
 * @property int $reviewed_by
 */
class AmlaReview extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const KINDS = [
        'periodic' => 'Periodic review',
        'edd' => 'Enhanced due diligence',
        'trigger' => 'Change in circumstances',
    ];

    /** @var array<string, string> */
    public const OUTCOMES = [
        'no_change' => 'No change',
        'updated' => 'Profile updated',
        'escalated' => 'Escalated for investigation',
    ];

    protected $fillable = ['profile_id', 'kind', 'outcome', 'notes', 'reviewed_on', 'next_review_on', 'level_at_review', 'reviewed_by'];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'notes' => 'encrypted',
            'reviewed_on' => 'date',
            'next_review_on' => 'date',
            'reviewed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
