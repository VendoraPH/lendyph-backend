<?php

namespace App\Models;

use Database\Factories\PortalAnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff announcement to members (portal design §7): plain text, to all
 * members, some branches or some member types. Not fanned out into
 * notifications; reads are tracked per member.
 */
class PortalAnnouncement extends Model
{
    /** @use HasFactory<PortalAnnouncementFactory> */
    use HasFactory;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_BRANCH = 'branch';

    public const AUDIENCE_MEMBER_TYPE = 'member_type';

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_BRANCH, self::AUDIENCE_MEMBER_TYPE];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_WITHDRAWN];

    protected $fillable = [
        'title',
        'body',
        'audience',
        'branch_ids',
        'member_types',
        'publish_at',
        'expires_at',
        'pinned',
        'status',
        'created_by',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'member_types' => 'array',
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
            'pinned' => 'boolean',
            'created_by' => 'integer',
            'published_by' => 'integer',
        ];
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PortalAnnouncementRead::class, 'announcement_id');
    }
}
