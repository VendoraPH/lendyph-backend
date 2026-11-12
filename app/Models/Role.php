<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;
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

    /**
     * A word of a key, in headline case, that fallbackLabel() reads in
     * capitals: the board (`bod`, `bod1`..`bod7`) and an account officer
     * (`ao`).
     */
    private const ACRONYM_KEY_PART = '/^(Bod|Ao)\d*$/';

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
    /**
     * The description on this role's automatic `updated` audit row: its
     * auditName() and the fields that changed, e.g. `Role "Teller" (cashier)
     * updated: display_name, description`. Other actions keep no description.
     */
    public function describeAuditEvent(string $action): ?string
    {
        if ($action !== 'updated') {
            return null;
        }

        $fields = array_keys(array_diff_key($this->getChanges(), [self::UPDATED_AT => true]));

        return sprintf('Role %s updated: %s', $this->auditName(), implode(', ', $fields));
    }

    /**
     * How audit descriptions name this role: the editable label as a JSON
     * string (so a quote inside it is escaped, not mistaken for the end) and
     * the fixed key in brackets, e.g. `"Teller" (cashier)`, so a row still
     * identifies the role after the label changes.
     */
    public function auditName(): string
    {
        return json_encode($this->auditLabel(), JSON_UNESCAPED_UNICODE).' ('.$this->name.')';
    }

    /**
     * The label the audit trail names this role by: its display name, or
     * fallbackLabel() of its key when it has none.
     */
    public function auditLabel(): string
    {
        return filled($this->display_name) ? trim((string) $this->display_name) : self::fallbackLabel((string) $this->name);
    }

    /**
     * How a role key reads when there is no display name to show, also for a
     * key no role has any more: the key in headline case ("Loan Officer"),
     * with an acronym part in capitals ("BOD1", "BOD Chair", "AO"). Display
     * only; the key and stored rows never change.
     */
    public static function fallbackLabel(string $key): string
    {
        $words = array_map(
            fn (string $word) => preg_match(self::ACRONYM_KEY_PART, $word) === 1 ? strtoupper($word) : $word,
            explode(' ', Str::headline($key)),
        );

        return implode(' ', $words);
    }

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
