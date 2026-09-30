<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * A staff member as the account-officer picker needs them: a name, and the id
 * to send back as `account_officer_id`. Nothing else, on purpose.
 *
 * `GET /api/staff` is readable by every role that can create or edit a loan,
 * which is far wider than who may list users. Reusing UserResource would hand
 * all of them the email, username, mobile number, roles and branches that
 * `users:view` exists to protect. Keep this to two keys; a field added here is
 * a field published to every loan officer on the deployment.
 *
 * `full_name` is the same accessor LoanResource returns for
 * `account_officer.full_name`, so a picked row and the loan's current officer
 * render identically.
 */
#[OA\Schema(
    schema: 'Staff',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Juan Dela Cruz'),
    ],
)]
class StaffResource extends JsonResource
{
    /**
     * @return array{id: int, full_name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
        ];
    }
}
