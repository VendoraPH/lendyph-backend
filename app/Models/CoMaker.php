<?php

namespace App\Models;

use App\Support\StaffRouteBinding;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CoMaker extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'borrower_id',
        'member_borrower_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'address',
        'contact_number',
        'occupation',
        'employer',
        'monthly_income',
        'relationship_to_borrower',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CoMaker $coMaker) {
            $lastCode = static::query()->orderByDesc('id')->value('co_maker_code');
            $nextNum = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
            $coMaker->co_maker_code = 'CMK-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' '));
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function loans(): BelongsToMany
    {
        return $this->belongsToMany(Loan::class, 'co_maker_loan')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /**
     * Every `{coMaker}` route parameter resolves only to a co-maker whose
     * borrower the signed-in user may see (Borrower::visibleTo), so another
     * branch's co-maker answers 404. Without a signed-in user the binding is
     * unscoped.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->whereHas('borrower', fn ($q) => $q->visibleTo($user)),
        );
    }
}
