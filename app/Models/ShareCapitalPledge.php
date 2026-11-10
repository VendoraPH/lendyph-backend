<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShareCapitalPledge extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrower_id',
        'amount',
        'schedule',
        'auto_credit',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'auto_credit' => 'boolean',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function borrowerLedgerEntries(): HasMany
    {
        return $this->hasMany(ShareCapitalLedger::class, 'borrower_id', 'borrower_id');
    }

    /**
     * Pledges owned by an actual member.
     *
     * Borrower::booted() creates a pledge row for every borrower, `pending`
     * applicants included, so every read path that means "members" has to say
     * so. Delegates to Borrower::scopeMembers() rather than repeating the
     * status list — there is one definition of "member" and this is not it.
     */
    public function scopeForMembers($query)
    {
        return $query->whereHas('borrower', fn ($q) => $q->members());
    }

    /**
     * Pledges of members whose membership hasn't ended
     * (Borrower::scopeCurrentMembers()): what auto-credit posts for. A
     * withdrawn or deceased member's pledge is never credited again.
     */
    public function scopeForCurrentMembers($query)
    {
        return $query->whereHas('borrower', fn ($q) => $q->currentMembers());
    }

    /**
     * Every `{pledge}` route parameter resolves only to a row whose member
     * the signed-in user may see (Borrower::visibleTo), so another branch's
     * row answers 404 before any validation runs. Without a signed-in user the
     * binding is unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $user = request()->user();

        return $user instanceof User
            ? $query->whereHas('borrower', fn ($q) => $q->visibleTo($user))
            : $query;
    }
}
