<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use Auditable, HasFactory;

    /**
     * How each editable field reads in an audit description.
     *
     * @var array<string, string>
     */
    private const AUDIT_FIELD_WORDS = [
        'name' => 'name',
        'code' => 'code',
        'address' => 'address',
        'contact_number' => 'contact number',
    ];

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
     * The description on this branch's automatic audit rows (multi-branch
     * audit D2), named by auditName(): `Branch "Butuan" (BTN) created.`, `…
     * deactivated.`, `… reactivated.`, or `… updated: name, contact number.`,
     * a status change and other fields together as `… deactivated and updated:
     * code.` The change list keeps the old and new values.
     */
    public function describeAuditEvent(string $action): ?string
    {
        if ($action === 'created') {
            return sprintf('Branch %s created.', $this->auditName());
        }

        if ($action !== 'updated') {
            return null;
        }

        $changes = $this->getChanges();
        $status = array_key_exists('is_active', $changes)
            ? ($this->is_active ? 'reactivated' : 'deactivated')
            : null;
        $fields = array_values(array_intersect_key(self::AUDIT_FIELD_WORDS, $changes));

        $what = match (true) {
            $status !== null && $fields !== [] => $status.' and updated: '.implode(', ', $fields),
            $status !== null => $status,
            $fields === [] => 'updated',
            default => 'updated: '.implode(', ', $fields),
        };

        return sprintf('Branch %s %s.', $this->auditName(), $what);
    }

    /**
     * How audit descriptions name this branch: its name as a JSON string (so a
     * quote inside it is escaped) and its code in brackets, as Role does.
     */
    public function auditName(): string
    {
        return json_encode((string) $this->name, JSON_UNESCAPED_UNICODE).' ('.$this->code.')';
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
