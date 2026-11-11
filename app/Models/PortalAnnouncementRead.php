<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A member having read an announcement (portal design §2). */
class PortalAnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['announcement_id', 'portal_user_id', 'read_at'];

    protected function casts(): array
    {
        return [
            'announcement_id' => 'integer',
            'portal_user_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }
}
