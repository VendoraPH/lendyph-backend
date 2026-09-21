<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * The FIRST of this user's branches, kept for one release.
     *
     * `branches()` below is the real assignment now; this is the singular shape
     * the API still has to emit, and `users.branch_id` is still a real column
     * holding a real id — see ResolvesBranchAssignment for which of the set ends
     * up in it. Already-signed-in frontend sessions rehydrate a `{branch: {...}}`
     * auth store out of localStorage with no version or migration step, so this
     * relation is what stops them crashing the moment this deploys. It goes when
     * the column goes, in a later release.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Every branch this user is assigned to. The source of truth.
     *
     * Always a superset of `branch()` — `users.branch_id` is maintained as one
     * member of this set, never as something outside it.
     *
     * Assignment is DISPLAY-ONLY. Nothing reads it to decide what a user may
     * see or do, there are no policies and no global scopes, and that is a
     * deliberate property rather than an oversight:
     * tests/Feature/BranchAssignmentIsDisplayOnlyTest.php fails loudly if
     * anyone starts scoping on it without designing that first.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Users assigned to $branchId, through the pivot rather than the column.
     *
     * This is the `?branch_id=` filter on `GET /api/users` (the list and its
     * `meta.stats`, which must agree). Filtering on `users.branch_id` would now
     * answer "users whose FIRST branch is this one", which silently hides every
     * user assigned here as anything but their first — the exact users this
     * feature exists to create.
     *
     * Deliberately NOT `where('branch_id', $branchId)->orWhereHas(...)`. The
     * column is always one of the pivot's rows, so the union would return the
     * same set for every user this application can produce, and would differ
     * only for a row that has a column but no pivot row — which is precisely
     * the branchless-user bug the factory and seeder fixes exist to prevent.
     * Papering over it here would hide it.
     */
    public function scopeForBranch($query, int $branchId)
    {
        return $query->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId));
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
