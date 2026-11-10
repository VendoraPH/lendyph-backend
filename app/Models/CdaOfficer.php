<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\CdaOfficerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The officers register (CDA design §6): board, committees and management,
 * with their terms. An officer linked to a member takes the name and sex from
 * the member (never copied here); an unlinked one (a non-member manager)
 * stores their own. Deleting is soft. Audited.
 *
 * @property string $position
 * @property Carbon $term_start
 * @property Carbon|null $term_end
 */
class CdaOfficer extends Model
{
    /** @use HasFactory<CdaOfficerFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Position keys. The four the regulatory profile pulls (chairperson,
     * general_manager, secretary, treasurer) are fixed; the rest describe the
     * board, committees and staff.
     */
    public const POSITIONS = [
        'chairperson', 'vice_chairperson', 'board_member', 'secretary', 'treasurer', 'general_manager',
        'committee_chairperson', 'committee_member', 'manager', 'bookkeeper', 'other',
    ];

    public const PROFILE_POSITIONS = ['chairperson', 'general_manager', 'secretary', 'treasurer'];

    public const STATUSES = ['active', 'inactive', 'resigned', 'removed'];

    public const SEXES = ['male', 'female'];

    protected $fillable = [
        'borrower_id', 'user_id', 'name', 'sex', 'position', 'committee', 'position_title',
        'term_start', 'term_end', 'election_date', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'borrower_id' => 'integer',
            'user_id' => 'integer',
            'term_start' => 'date',
            'term_end' => 'date',
            'election_date' => 'date',
        ];
    }

    /**
     * Officers serving on a date: active, with a term that has started and
     * hasn't ended.
     *
     * @param  Builder<CdaOfficer>  $query
     */
    public function scopeServingOn(Builder $query, string $date): void
    {
        $query->where('status', 'active')
            ->whereDate('term_start', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('term_end')->orWhereDate('term_end', '>=', $date));
    }

    /**
     * Officers whose term overlaps a period (design §6: an officer counts
     * for a year when the term overlaps it).
     *
     * @param  Builder<CdaOfficer>  $query
     */
    public function scopeServingBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('term_start', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('term_end')->orWhereDate('term_end', '>=', $from));
    }

    /** The name to show: the linked member's, else the officer's own. */
    public function displayName(): ?string
    {
        return $this->borrower_id !== null ? $this->member?->full_name : $this->name;
    }

    /** The sex to show: the linked member's gender, else the officer's own. */
    public function displaySex(): ?string
    {
        return $this->borrower_id !== null ? $this->member?->gender : $this->sex;
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Borrower::class, 'borrower_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(CdaOfficerTraining::class, 'officer_id');
    }
}
