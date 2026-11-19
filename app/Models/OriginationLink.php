<?php

namespace App\Models;

use App\Services\BranchAccess;
use App\Support\StaffRouteBinding;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A public application link (origination decisions 16 and 17): a random
 * token, a branch (none means every branch, which needs
 * `branches:view_all`), the products it offers (none means every product
 * offered online), an expiry and a switch. It counts visits, starts and
 * submissions; links marked as a test stay out of the dashboard figures.
 *
 * Branch staff see and manage only their own branches' links. A `{link}`
 * route parameter resolves only to a link the caller may see, so another
 * branch's link answers 404.
 *
 * @property string $token
 * @property ?int $branch_id
 * @property bool $active
 * @property bool $is_test
 */
class OriginationLink extends Model
{
    use Auditable;

    public const TOKEN_LENGTH = 32;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_DISABLED => 'Turned off',
        self::STATUS_EXPIRED => 'Expired',
    ];

    protected $fillable = [
        'name', 'token', 'branch_id', 'active', 'is_test', 'expires_at', 'display_name', 'contact_phone', 'contact_email',
        'visits', 'started', 'submitted', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'active' => 'boolean',
            'is_test' => 'boolean',
            'expires_at' => 'datetime',
            'visits' => 'integer',
            'started' => 'integer',
            'submitted' => 'integer',
        ];
    }

    /** A new random token: letters and digits only, so it sits in a URL as is. */
    public static function newToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public function status(): string
    {
        return match (true) {
            ! $this->active => self::STATUS_DISABLED,
            $this->expires_at !== null && $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_ACTIVE,
        };
    }

    /**
     * Links $user may see: their branches' only, and links with no branch
     * only with `branches:view_all`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(BranchAccess::class)->constrain($query, $user, $this->qualifyColumn('branch_id'));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return StaffRouteBinding::scope(
            parent::resolveRouteBindingQuery($query, $value, $field),
            fn ($query, User $user) => $query->visibleTo($user),
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loanProducts(): BelongsToMany
    {
        return $this->belongsToMany(LoanProduct::class, 'origination_link_products', 'link_id', 'loan_product_id')->orderBy('loan_products.id');
    }
}
