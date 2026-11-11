<?php

namespace App\Services\Portal\Requests;

use App\Http\Middleware\Portal\EnsurePortalFeature;
use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\PortalUser;
use App\Services\AuditLogService;
use App\Services\Portal\PortalSettings;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsLocks;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The member's side of the requests center (portal design §6): submitting
 * the three request types, cancelling one while it is still open with staff
 * (submitted, under review, needing information), and replying when staff
 * asked for more information. The member comes only from the token; every
 * request is found within the member's own (404 otherwise).
 *
 * Writes run inside the caller's transaction; each audit row names the
 * member (the signed-in PortalUser).
 */
final class RequestDesk
{
    /** Statuses the member may cancel in. */
    public const CANCELLABLE = [PortalRequest::STATUS_SUBMITTED, PortalRequest::STATUS_UNDER_REVIEW, PortalRequest::STATUS_NEED_INFO];

    /** The feature switches each type needs (PortalSetting::FEATURES). */
    public const FEATURES = [
        PortalRequest::TYPE_PROFILE_UPDATE => ['profile_requests'],
        PortalRequest::TYPE_SAVINGS_WITHDRAWAL => ['withdrawal_requests', 'savings'],
        PortalRequest::TYPE_SAVINGS_OPENING => ['account_requests', 'savings'],
    ];

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly WithdrawalRequests $withdrawals,
        private readonly ProfileRequests $profiles,
        private readonly OpeningRequests $openings,
        private readonly RequestTimeline $timeline,
        private readonly RequestAlerts $alerts,
        private readonly RequestPresenter $presenter,
        private readonly SavingsLocks $locks,
    ) {}

    /** Whether a type is switched on. */
    public function typeOn(string $type): bool
    {
        foreach (self::FEATURES[$type] ?? [] as $feature) {
            if (! $this->settings->featureOn($feature)) {
                return false;
            }
        }

        return isset(self::FEATURES[$type]);
    }

    /**
     * Validate a submission before anything is locked: the type and its
     * switch (403 in words when off), then the type's own details.
     *
     * @param  array<string, mixed>  $input
     * @return array{type: string, details: array<string, mixed>, remarks: string|null}
     *
     * @throws ValidationException|HttpResponseException
     */
    public function validate(PortalUser $portalUser, array $input): array
    {
        $base = Validator::make($input, [
            'type' => ['required', 'string', Rule::in(PortalRequest::TYPES)],
            'details' => ['required', 'array'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            'type.required' => 'Choose what you are asking for.',
            'type.in' => 'Choose a profile change, a withdrawal or a new savings account.',
            'details.required' => 'Fill in the request\'s details.',
            'details.array' => 'Fill in the request\'s details.',
            'remarks.max' => 'The remarks can be at most 500 characters.',
        ])->validate();

        if (! $this->typeOn($base['type'])) {
            throw new HttpResponseException(response()->json(['success' => false, 'message' => EnsurePortalFeature::OFF, 'code' => 'feature_off'], 403));
        }

        $details = match ($base['type']) {
            PortalRequest::TYPE_SAVINGS_WITHDRAWAL => Validator::make($input, $this->withdrawals->rules($portalUser), $this->withdrawals->messages())->validate()['details'],
            PortalRequest::TYPE_SAVINGS_OPENING => Validator::make($input, $this->openings->rules(), $this->openings->messages())->validate()['details'],
            default => $this->profiles->validated($portalUser, $input['details']),
        };

        return ['type' => $base['type'], 'details' => $details, 'remarks' => $base['remarks'] ?? null];
    }

    /**
     * Submit, inside the portal IdempotentWrite's transaction.
     *
     * @param  array{type: string, details: array<string, mixed>, remarks: string|null}  $validated
     */
    public function submit(PortalUser $portalUser, array $validated): PortalRequest
    {
        $request = match ($validated['type']) {
            PortalRequest::TYPE_SAVINGS_WITHDRAWAL => $this->withdrawals->submit($portalUser, $validated['details']),
            PortalRequest::TYPE_SAVINGS_OPENING => $this->openings->submit($portalUser, $validated['details']),
            default => $this->profiles->submit($portalUser, $validated['details']),
        };

        $summary = $this->presenter->summary($request);
        $this->timeline->opened($request, $validated['remarks']);
        $this->alerts->submitted($request, $summary);

        AuditLogService::log('portal_request_submitted', $request, null, [
            'reference' => $request->reference,
            'type' => $request->type,
            'amount' => $request->amount === null ? null : (string) $request->amount,
        ], "Member portal request {$request->reference} sent: {$summary}.");

        if ($request->savings_hold_id !== null) {
            AuditLogService::log('portal_request_hold_placed', $request, null, ['savings_hold_id' => $request->savings_hold_id, 'amount' => (string) $request->amount],
                "Withdrawal request {$request->reference} reserved {$this->presenter->peso($request->amount)} with a hold.");
        }

        return $request;
    }

    /** The member cancels, inside a transaction. The hold, if any, is released by nobody. */
    public function cancel(PortalRequest $request): PortalRequest
    {
        $locked = $this->lockForMember($request, self::CANCELLABLE, 'It can be cancelled only while your branch hasn\'t decided it yet.');

        if ($this->withdrawals->releaseHold($locked, "Member cancelled request {$locked->reference}", null) !== null) {
            AuditLogService::log('portal_request_hold_released', $locked, ['hold' => 'active'], ['hold' => 'released'],
                "The hold for withdrawal request {$locked->reference} was released: the member cancelled it.");
        }

        $from = $locked->status;
        $this->timeline->move($locked, PortalRequest::STATUS_CANCELLED, PortalRequestEvent::ACTOR_MEMBER, null, null);

        AuditLogService::log('portal_request_cancelled', $locked, ['status' => $from], ['status' => PortalRequest::STATUS_CANCELLED],
            "The member cancelled portal request {$locked->reference}.");

        return $locked;
    }

    /** The member answers staff's question, inside a transaction: back to review. */
    public function reply(PortalRequest $request, string $message): PortalRequest
    {
        $locked = $this->lockForMember($request, [PortalRequest::STATUS_NEED_INFO], 'You can reply only when your branch asks for more information.');

        $this->timeline->move($locked, PortalRequest::STATUS_UNDER_REVIEW, PortalRequestEvent::ACTOR_MEMBER, null, $message);

        AuditLogService::log('portal_request_replied', $locked, ['status' => PortalRequest::STATUS_NEED_INFO], ['status' => PortalRequest::STATUS_UNDER_REVIEW],
            "The member replied to portal request {$locked->reference}.");

        return $locked;
    }

    /**
     * Insert a new open request; a second open request on the same key
     * (a race past the caller's check) answers 409.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function insertOpen(array $attributes, string $conflict): PortalRequest
    {
        try {
            return PortalRequest::query()->create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index !== 'portal_requests_live_key_unique') {
                throw $e;
            }

            Refuse::conflict($conflict.' Cancel it, or wait for your branch\'s decision.');
        }
    }

    /**
     * The request row, locked, in one of `$statuses`; 409 in words otherwise.
     *
     * @param  list<string>  $statuses
     */
    public static function lockOpen(int $id, array $statuses, string $why = ''): PortalRequest
    {
        $locked = PortalRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $statuses, true)) {
            Refuse::conflict(trim("Request {$locked->reference} is already ".str_replace('_', ' ', $locked->status).". {$why}"));
        }

        return $locked;
    }

    /**
     * Lock in the savings order: the account first (a withdrawal), then the
     * request.
     *
     * @param  list<string>  $statuses
     */
    private function lockForMember(PortalRequest $request, array $statuses, string $why): PortalRequest
    {
        if ($request->savings_account_id !== null) {
            $this->locks->account((int) $request->savings_account_id);
        }

        return self::lockOpen($request->id, $statuses, $why);
    }
}
