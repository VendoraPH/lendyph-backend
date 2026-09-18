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

    /**
     * Never written to `audit_logs`. See Auditable::redactForAudit().
     *
     * Both are secrets the trail has no business keeping: the bcrypt hash is
     * crackable offline, and `remember_token` is a live credential.
     *
     * @var list<string>
     */
    protected array $auditRedacted = ['password', 'remember_token'];

    /**
     * `last_login_at` and `must_change_password` are deliberately absent.
     *
     * Both are decided by the server about the user, never by the user about
     * themselves, and neither has any business arriving in a request body.
     * `must_change_password` in particular is a lock: if it were fillable, the
     * account it locks could ask to be unlocked. Today's request classes are
     * strict allowlists — `StoreUserRequest`/`UpdateUserRequest` feed
     * `safe()->except('role')` and `UpdateMeRequest` feeds `validated()`, so an
     * unlisted key cannot reach `fill()` in the first place — but that is a
     * property of five rule sets that anyone may edit, not of the model. Leaving
     * it unfillable means the guarantee survives a future `update($request->all())`.
     *
     * The cost is that `update(['must_change_password' => ...])` silently does
     * nothing, exactly as it did for `last_login_at` (see AuthController::login).
     * The two writers below therefore use `forceFill()->save()`, and
     * ForcePasswordChangeTest covers both directions so a silent no-op cannot
     * come back:
     *   - set   → UserController::resetPassword()
     *   - clear → AuthController::changePassword()
     */
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

    /**
     * `must_change_password` gets its default here as well as in the schema.
     *
     * A DB-level default only applies to the row; the in-memory model that
     * `User::factory()->create()` or `User::create()` hands back still carries
     * `null` for the column until something re-reads it. Nothing breaks on null
     * — it is falsy, so the middleware lets it through — but "is this account
     * locked?" would then be answered by an absence rather than by a value, and
     * any strict comparison (`=== false`, a `boolean` cast, a JSON body that
     * should say `false` and says `null`) would be wrong for one instance and
     * right for the next. Defaulting in both places keeps the answer the same
     * whichever side of the write you ask on.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'must_change_password' => false,

        // Same reasoning, and the same trap: a freshly created in-memory User
        // would otherwise carry null here while its row carries 0, and
        // SignedFileLink stamps this value into every link it mints.
        'file_link_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'file_link_version' => 'integer',
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
