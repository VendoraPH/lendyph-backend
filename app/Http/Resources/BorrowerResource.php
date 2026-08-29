<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Borrower',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'borrower_code', type: 'string'),
        new OA\Property(property: 'external_account_no', type: 'string', nullable: true, description: "The coop's own account number for this member in the legacy system it was migrated from. "
            .'Null for anyone registered through this system. Unique across borrowers when set — it is the key '
            .'the CSV importer joins loan rows to members on, so an operator correcting one by hand must keep it unique.'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive', 'blacklisted', 'pending', 'rejected']),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true, description: 'Set when a pending registration is approved'),
        new OA\Property(property: 'approved_by', type: 'integer', nullable: true, description: 'User id that approved the registration'),
        new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true, description: 'Set when a pending registration is rejected'),
        new OA\Property(property: 'rejected_by', type: 'integer', nullable: true, description: 'User id that rejected the registration'),
        new OA\Property(property: 'approved_by_user', type: 'object', nullable: true, description: 'The reviewer who approved the registration, as a UserResource. Present only when the `approvedBy` relation is eager-loaded; absent otherwise, never lazily fetched.'),
        new OA\Property(property: 'rejected_by_user', type: 'object', nullable: true, description: 'The reviewer who rejected the registration, as a UserResource. Present only when the `rejectedBy` relation is eager-loaded; absent otherwise, never lazily fetched.'),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true, description: 'Reason captured when a registration is rejected'),
        new OA\Property(property: 'is_imported', type: 'boolean', description: 'True when this member was carried in by a CSV migration rather than registered through this system. '
            .'Imported members are created `active` with no documents and no KYC review, so `approved_at`/`approved_by` on them record who '
            .'ran the migration and the date the extract represents — not an identity check.'),
        new OA\Property(property: 'has_valid_id', type: 'boolean', description: 'Whether a `valid_id` document is on file. Present only when the caller loaded the `documents` relation or '
            .'the `valid_id_documents_count` count; absent otherwise, never lazily fetched. `is_imported && has_valid_id === false` is the '
            .'"imported, KYC not on file" backlog.'),
        new OA\Property(property: 'branch_id', type: 'integer', nullable: true, description: 'Foreign key to the assigned branch (null for anonymous submissions awaiting admin review)'),
        new OA\Property(property: 'photo_url', type: 'string', nullable: true),
        new OA\Property(property: 'photo', type: 'string', nullable: true, description: 'Alias for photo_url'),
        new OA\Property(property: 'phone', type: 'string', nullable: true, description: 'Alias for contact_number'),
        new OA\Property(property: 'monthly_income', type: 'number', nullable: true),
        new OA\Property(property: 'date_hired', type: 'string', format: 'date', nullable: true, description: 'Employment start date'),
        new OA\Property(property: 'pledge_amount', type: 'number', nullable: true, description: 'Share capital pledge amount'),
        new OA\Property(property: 'address', type: 'string', nullable: true, description: 'Legacy single-line address'),
        new OA\Property(property: 'street_address', type: 'string', nullable: true),
        new OA\Property(property: 'barangay', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'province', type: 'string', nullable: true),
        new OA\Property(property: 'spouse_first_name', type: 'string', nullable: true),
        new OA\Property(property: 'spouse_last_name', type: 'string', nullable: true),
    ],
)]
class BorrowerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Signed, expiring link minted by Borrower::photoUrl — the file is on
        // the private disk and has no directly reachable URL.
        $photoUrl = $this->photo_url;

        /**
         * Whether a valid ID is on file, from whatever the caller already
         * loaded — and null, meaning "not asked", when they loaded neither.
         *
         * Never a query of its own. A `$this->documents()->where(...)` here
         * would be one round trip per row on a 100-member page, on the list
         * endpoint, which is exactly how a badge becomes an outage. `show()`
         * loads the documents; `index()` asks for the count
         * (`valid_id_documents_count`); anything else omits the key rather than
         * guessing, because guessing `false` would tell an operator that every
         * member in a payload the loader forgot is missing their KYC.
         */
        $validIdOnFile = match (true) {
            $this->resource->relationLoaded('documents') => $this->documents->contains(
                fn ($document): bool => $document->type === 'valid_id',
            ),
            $this->resource->valid_id_documents_count !== null => (int) $this->resource->valid_id_documents_count > 0,
            default => null,
        };

        return [
            'id' => $this->id,
            'borrower_code' => $this->borrower_code,
            /*
             * Exposed so an operator can SEE and correct a bad legacy
             * account number after an import. A wrong one here does not
             * just mislabel a member — it is the join key, so it silently
             * attaches that member's loans to the wrong person, and the
             * only way to notice is to be able to read it back.
             */
            'external_account_no' => $this->external_account_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'birthdate' => $this->birthdate?->toDateString(),
            'civil_status' => $this->civil_status,
            'gender' => $this->gender,
            'address' => $this->address,
            'street_address' => $this->street_address,
            'barangay' => $this->barangay,
            'city' => $this->city,
            'province' => $this->province,
            'contact_number' => $this->contact_number,
            'phone' => $this->contact_number,
            'email' => $this->email,
            'employer_or_business' => $this->employer_or_business,
            'date_hired' => $this->date_hired?->toDateString(),
            'monthly_income' => $this->monthly_income !== null ? (float) $this->monthly_income : null,
            'pledge_amount' => $this->pledge_amount !== null ? (float) $this->pledge_amount : null,
            'spouse_first_name' => $this->spouse_first_name,
            'spouse_middle_name' => $this->spouse_middle_name,
            'spouse_last_name' => $this->spouse_last_name,
            'spouse_contact_number' => $this->spouse_contact_number,
            'spouse_occupation' => $this->spouse_occupation,
            'photo_url' => $photoUrl,
            'photo' => $photoUrl,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            /*
             * The reviewer, not just their id.
             *
             * `approved_by` / `rejected_by` are bare user ids, so a screen
             * showing who reviewed a registration could only render
             * "Reviewer #7" — and resolving the name client-side would need
             * `users:view`, which a reviewer holding only `borrowers:approve`
             * may not have. Shaped exactly like LoanResource's
             * `approved_by_user` / `rejected_by_user` so the two read the same.
             *
             * whenLoaded(), so this can never introduce a query per row: if the
             * relation was not eager-loaded the key is simply absent from the
             * payload rather than lazily fetched. That does mean the caller has
             * to load it — see BorrowerController, where every path returning
             * this resource needs `approvedBy`/`rejectedBy` alongside `branch`.
             */
            'approved_by_user' => new UserResource($this->whenLoaded('approvedBy')),
            'rejected_by_user' => new UserResource($this->whenLoaded('rejectedBy')),
            'rejection_reason' => $this->rejection_reason,

            /*
             * The imported-member backlog, in two fields the members list can
             * badge on directly.
             *
             * `approved_at` and `approved_by` alone cannot express this. The CSV
             * importer stamps both — see CsvImportRun::admissionStamp() — so an
             * imported member reads exactly like a reviewed one, which is the
             * honest record of who admitted them but says nothing about whether
             * anyone ever saw an ID. They never had one: the importer creates
             * members with no documents at all, and approveRegistration() would
             * refuse every one of them for that reason.
             *
             * `is_imported` is still derived from `external_account_no` — that
             * is the only signal on the row — but it is derived HERE rather than
             * by every client that wants to draw the badge. The two are not the
             * same thing: `external_account_no` is a legacy join key an operator
             * can type onto a member who was never imported, which is exactly
             * what it is exposed for, so its meaning is "has a legacy account
             * number" and this field's meaning is "came in through a migration".
             * They agree today; when they stop, one definition changes and no
             * client does.
             *
             * `has_valid_id` is the half that is genuinely new, and the half an
             * operator acts on.
             */
            'is_imported' => $this->external_account_no !== null,
            'has_valid_id' => $this->when($validIdOnFile !== null, $validIdOnFile),
            'branch_id' => $this->branch_id,
            'branch' => new BranchResource($this->whenLoaded('branch')),
            'co_makers' => CoMakerResource::collection($this->whenLoaded('coMakers')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
