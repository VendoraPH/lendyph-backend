<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Borrower extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'civil_status',
        'gender',
        'address',
        'contact_number',
        'email',
        'employer_or_business',
        'monthly_income',
        'spouse_first_name',
        'spouse_middle_name',
        'spouse_last_name',
        'spouse_contact_number',
        'spouse_occupation',
        'photo_path',
        'branch_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'monthly_income' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Borrower $borrower) {
            $lastCode = static::query()->orderByDesc('id')->value('borrower_code');
            $nextNum = $lastCode ? (int) substr($lastCode, 4) + 1 : 1;
            $borrower->borrower_code = 'BRW-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function coMakers(): HasMany
    {
        return $this->hasMany(CoMaker::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function shareCapitalPledge(): HasOne
    {
        return $this->hasOne(ShareCapitalPledge::class);
    }

    public function shareCapitalLedger(): HasMany
    {
        return $this->hasMany(ShareCapitalLedger::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('borrower_code', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('middle_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('contact_number', 'like', "%{$term}%");
        });
    }
}
