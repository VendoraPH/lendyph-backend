<?php

namespace App\Services\Portal\Requests;

use App\Models\Borrower;
use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\Portal\PortalNotices;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A member's profile change request (portal design §6): changes to the
 * address, contact details, civil status and employer, checked by the member
 * update rules when sent and again when applied. One open request per member
 * (`live_key` `p:{member}`). The current values are kept with it, so staff see
 * what changes. Nothing is written to the member until staff approve; the
 * member row's own audit row keeps the before and after.
 */
final class ProfileRequests
{
    public function __construct(
        private readonly ProfileFields $fields,
        private readonly RequestTimeline $timeline,
        private readonly RequestNumbers $numbers,
        private readonly PortalNotices $notices,
    ) {}

    /**
     * Only the portal's fields, at least one that differs, each by the member
     * update rules.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(PortalUser $portalUser, mixed $details): array
    {
        $details = is_array($details) ? $details : [];
        $unknown = array_diff(array_keys($details), array_keys(ProfileFields::FIELDS));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['details' => [
                'Only these can be changed in the portal: '.implode(', ', array_map('strtolower', ProfileFields::FIELDS)).'. Visit your branch for anything else.',
            ]]);
        }

        $member = $portalUser->borrower;
        $rules = [];

        foreach ($this->fields->rules($member) as $field => $fieldRules) {
            $rules["details.{$field}"] = $fieldRules;
        }

        $valid = Validator::make(['details' => $details], $rules, $this->fields->messages('details.'))->validate()['details'] ?? [];
        $changed = array_filter($valid, fn (mixed $value, string $field): bool => (string) ($member->{$field} ?? '') !== (string) ($value ?? ''), ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            throw ValidationException::withMessages(['details' => ['Change at least one detail. What you sent is already on file.']]);
        }

        return $changed;
    }

    /** @param  array<string, mixed>  $details  validated */
    public function submit(PortalUser $portalUser, array $details): PortalRequest
    {
        $member = $portalUser->borrower;
        $current = [];

        foreach (array_keys($details) as $field) {
            $current[$field] = $member->{$field};
        }

        return RequestDesk::insertOpen([
            'reference' => $this->numbers->next(),
            'portal_user_id' => $portalUser->id,
            'borrower_id' => $member->id,
            'branch_id' => $member->branch_id,
            'type' => PortalRequest::TYPE_PROFILE_UPDATE,
            'status' => PortalRequest::STATUS_SUBMITTED,
            'details' => $details,
            'current_values' => $current,
            'expires_on' => RequestExpiry::otherRequests(),
            'live_key' => 'p:'.$member->id,
        ], 'You already have an open profile change request.');
    }

    /**
     * Apply as `$staff`, inside a transaction: the member row first, then the
     * request (status re-checked), then the values through the member update
     * rules as they stand now. Writes the member through its model, so its
     * audit row names the staff user with before and after.
     *
     * @throws ValidationException
     */
    public function approve(PortalRequest $request, User $staff): PortalRequest
    {
        $member = Borrower::query()->whereKey($request->borrower_id)->lockForUpdate()->firstOrFail();
        $locked = RequestDesk::lockOpen($request->id, [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW, PortalRequest::STATUS_NEED_INFO]);
        $details = $locked->details ?? [];
        $rules = array_intersect_key($this->fields->rules($member), $details);
        $values = Validator::make($details, $rules, $this->fields->messages())->validate();

        if (isset($values['street_address']) && trim((string) $member->address) === '') {
            $values['address'] = $values['street_address'];
        }

        $contactChanged = array_intersect(array_keys($values), ProfileFields::CONTACT_FIELDS) !== [];
        $member->update($values);

        $this->timeline->move($locked, PortalRequest::STATUS_COMPLETED, PortalRequestEvent::ACTOR_STAFF, $staff->id, null, [
            'decided_by' => $staff->id,
            'decided_at' => now(),
        ]);

        if ($contactChanged && $locked->portalUser !== null) {
            $this->notices->send($locked->portalUser, PortalNotices::CATEGORY_SECURITY, 'Contact details changed',
                'Your mobile number or email on file was changed at your request. If you didn\'t ask for this, contact your branch.', '/member/profile');
        }

        return $locked;
    }
}
