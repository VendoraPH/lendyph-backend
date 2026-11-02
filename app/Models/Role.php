<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model that extends Spatie's Role with:
 * - description / is_active / is_system metadata
 * - Helper to distinguish seeded system roles from admin-created custom roles
 * - Audit rows for create, edit (display name, description, active flag) and
 *   delete. Permission changes are a pivot write the trait can't see; see
 *   RolePermissionAudit.
 *
 * Registered as the `role` model in config/permission.php.
 */
class Role extends SpatieRole
{
    use Auditable;

    /**
     * Keys that can't be renamed, deleted or deactivated, whatever `is_system`
     * says: no migration sets `is_system` on them (only the seeder does), and
     * permission checks and the Gate::before bypass key off these names.
     */
    public const PROTECTED_KEYS = ['super_admin', 'admin'];

    protected $fillable = [
        'name',
        'display_name',
        'guard_name',
        'description',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Override Spatie's users() relationship to explicitly use App\Models\User.
     *
     * Spatie's implementation resolves the user model via
     * `getModelForGuard($this->attributes['guard_name'] ?? config('auth.defaults.guard'))`,
     * which returns NULL in certain query-builder contexts (e.g., `withCount('users')`
     * in this codebase). Hardcoding the model avoids that null-resolution failure
     * since we only have one user model anyway.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            config('permission.column_names.role_pivot_key', 'role_id'),
            config('permission.column_names.model_morph_key', 'model_id')
        );
    }
}
