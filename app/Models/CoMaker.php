<?php

namespace App\Models;

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
            $coMaker->co_maker_code = 'CMK-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
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
        return $this->belongsToMany(Loan::class, 'co_maker_loan')->withTimestamps();
    }
}
