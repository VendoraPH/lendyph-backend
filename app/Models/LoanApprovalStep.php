<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

/**
 * One position in one round of a loan's approval chain.
 *
 * `step_id`, `name` and `role` are a SNAPSHOT of the ApprovalWorkflowSetting
 * chain taken when the loan was submitted — see LoanApprovalChainService::seed.
 * Nothing here is looked back up through that setting, so reconfiguring the
 * chain cannot rewrite a loan that is already in flight.
 *
 * Written only by LoanApprovalChainService.
 */
#[OA\Schema(
    schema: 'LoanApprovalStep',
    description: "One position in a loan's approval chain, shaped to the loan detail page's ApprovalStep interface",
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'Row id — the two PATCH endpoints are addressed by it'),
        new OA\Property(property: 'index', type: 'integer', example: 0, description: 'Position in the chain (`step_order`)'),
        new OA\Property(property: 'step_id', type: 'string', example: 'loan-processor', description: 'Config slug, snapshotted at seed time'),
        new OA\Property(property: 'name', type: 'string', example: 'Loan Processor'),
        new OA\Property(property: 'role', type: 'string', example: 'loan_processor'),
        new OA\Property(property: 'kind', type: 'string', enum: ['submit', 'approve', 'release']),
        new OA\Property(property: 'status', type: 'string', enum: ['waiting', 'pending', 'approved', 'sent_back']),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'acted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'acted_by', type: 'string', nullable: true, description: 'Display NAME of the actor, rendered directly by the UI'),
        new OA\Property(property: 'acted_by_id', type: 'integer', nullable: true),
        new OA\Property(property: 'can_act', type: 'boolean', description: 'Whether the REQUESTING user may act on this step right now'),
    ],
)]
class LoanApprovalStep extends Model
{
    use Auditable, HasFactory;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT_BACK = 'sent_back';

    public const KIND_SUBMIT = 'submit';

    public const KIND_APPROVE = 'approve';

    public const KIND_RELEASE = 'release';

    /**
     * Roles that may act on any step regardless of the step's own role.
     *
     * Mirrors the client's canUserActOnStep: `admin` is the client-side
     * administrator and `super_admin` the developer-side one.
     */
    public const BYPASS_ROLES = ['admin', 'super_admin'];

    protected $fillable = [
        'loan_id',
        'round',
        'step_order',
        'step_id',
        'name',
        'role',
        'kind',
        'status',
        'acted_by',
        'remarks',
        'acted_at',
        'sent_back_to_step_order',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'step_order' => 'integer',
            'sent_back_to_step_order' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function actedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
