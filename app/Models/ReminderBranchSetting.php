<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch's own reminder sender, phone, email and payment instructions. Read
 * only while `use_company_default` is off; otherwise the company's apply.
 */
class ReminderBranchSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'branch_id',
        'use_company_default',
        'sender_name',
        'contact_phone',
        'contact_email',
        'payment_instructions',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'use_company_default' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
