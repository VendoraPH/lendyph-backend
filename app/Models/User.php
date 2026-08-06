<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'mobile_number',
        'password',
        'branch_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => "{$this->first_name} {$this->last_name}");
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Whether this user may act on $target through the user-management API.
     *
     * Only one boundary exists: a `super_admin` account may not be touched by
     * anyone who is not a `super_admin`. Restricting who can *grant* the
     * platform role is pointless if a client admin can simply seize the account
     * that already holds it — reset its password and log in as it, or
     * deactivate it — and inherit the `Gate::before` bypass in
     * AppServiceProvider plus the restructure dual-control exemption in
     * `LoanService::approve()` without any role ever changing.
     *
     * Everyone else is fair game to anyone holding the relevant `users:*`
     * permission; this is a small cooperative and admins genuinely administer
     * each other.
     */
    public function canManageAccount(self $target): bool
    {
        return ! $target->hasRole('super_admin') || $this->hasRole('super_admin');
    }
}
