<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'contact_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Everyone assigned to this branch, through the pivot.
     *
     * Was `hasMany(User::class)` on `users.branch_id`. It has to move with the
     * assignment: `BranchController::index()` and `show()` publish this as
     * `users_count`, and read through the column that count would answer "users
     * whose FIRST branch is this one" — so a branch staffed entirely by people
     * assigned to it second would report zero.
     *
     * The count is unchanged for every user that exists today, because
     * `users.branch_id` is maintained as one member of the pivot set rather
     * than as something outside it.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user');
    }

    public function borrowers(): HasMany
    {
        return $this->hasMany(Borrower::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
