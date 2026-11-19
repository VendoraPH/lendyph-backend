<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use Auditable, HasFactory;

    public const STATUS_ACTIVE = 'active';

    /** No new members, loans, releases, accounts or placements; collections and withdrawals go on (enforced in MB-BE2). */
    public const STATUS_SUSPENDED = 'suspended';

    /** Nothing new. Reached only through an approved closure (BranchStatus). */
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_CLOSED];

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
        'email' => 'email',
        'manager_user_id' => 'manager',
        'opened_on' => 'opening date',
        'cda_authority_number' => 'CDA authority number',
        'cda_authority_issued_on' => 'CDA authority issue date',
    ];

    /**
     * How a status change reads in an audit description.
     *
     * @var array<string, string>
     */
    private const STATUS_WORDS = [
        self::STATUS_ACTIVE => 'reactivated',
        self::STATUS_SUSPENDED => 'suspended',
        self::STATUS_CLOSED => 'closed',
    ];

    /**
     * Said after the status word in this save's audit description, then
     * cleared: the reason for a suspension or reactivation (" Reason: …"), or
     * a closure's date and people. Not a column.
     */
    public ?string $statusAuditNote = null;

    /**
     * `status`, `is_main_office` and `closed_on` are left out on purpose:
     * they change only through BranchStatus, never from a request's fields.
     * `is_active` stays for today's callers and is written through the status
     * (see isActive()).
     */
    protected $fillable = [
        'name',
        'code',
        'address',
        'contact_number',
        'email',
        'manager_user_id',
        'opened_on',
        'cda_authority_number',
        'cda_authority_issued_on',
        'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'is_main_office' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_main_office' => 'boolean',
            'opened_on' => 'date',
            'closed_on' => 'date',
            'cda_authority_issued_on' => 'date',
        ];
    }

    /**
     * `is_active` is generated from the status in the database (active reads
     * true; suspended and closed read false), so today's readers (the pickers,
     * the public list, CDA counts) keep working. Setting it writes the status:
     * true makes the branch active, false suspends an active branch and
     * leaves a closed one closed.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): bool => ($attributes['status'] ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE,
            set: fn (mixed $value, array $attributes): array => [
                'status' => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? self::STATUS_ACTIVE
                    : (($attributes['status'] ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE ? self::STATUS_SUSPENDED : $attributes['status']),
            ],
        );
    }

    /**
     * The description on this branch's automatic audit rows (multi-branch
     * audit D2), named by auditName(): `Branch "Butuan" (BTN) created.`,
     * `… suspended.`, `… reactivated.`, `… closed on …`, `… made the main
     * office.`, or `… updated: name, contact number.`, a status change and
     * other fields together as `… suspended and updated: code.` A reason or
     * closure note follows the sentence. The change list keeps the old and
     * new values.
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
        $note = $this->statusAuditNote;
        $this->statusAuditNote = null;

        if (array_key_exists('is_main_office', $changes) && ! array_key_exists('status', $changes)) {
            return $this->is_main_office
                ? sprintf('Branch %s made the main office.', $this->auditName())
                : sprintf('Branch %s is no longer the main office.', $this->auditName());
        }

        $status = array_key_exists('status', $changes) ? (self::STATUS_WORDS[$this->status] ?? $this->status) : null;
        $fields = array_values(array_intersect_key(self::AUDIT_FIELD_WORDS, $changes));

        $what = match (true) {
            $status !== null && $fields !== [] => $status.' and updated: '.implode(', ', $fields),
            $status !== null => $status,
            $fields === [] => 'updated',
            default => 'updated: '.implode(', ', $fields),
        };

        if ($status !== null && $this->status === self::STATUS_CLOSED && $note !== null) {
            return sprintf('Branch %s %s %s', $this->auditName(), $what, $note);
        }

        return sprintf('Branch %s %s.', $this->auditName(), $what).($status !== null && $note !== null ? " Reason: {$note}" : '');
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

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return HasMany<BranchClosure, $this> */
    public function closures(): HasMany
    {
        return $this->hasMany(BranchClosure::class);
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
