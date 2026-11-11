<?php

namespace App\Services\Portal\Requests;

use App\Models\PortalRequest;
use App\Models\PortalRequestEvent;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsProduct;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Savings\Api\Presenter;
use App\Services\Savings\Api\Refuse;
use App\Services\Savings\SavingsMath;
use App\Support\PhilippineMobile;
use Illuminate\Support\Collection;

/**
 * Portal requests as the API sends them (portal design §3, §5). Allow-lists
 * only: never a model's `toArray()`.
 *
 * - **Member:** their own request, payout numbers masked to the last 4,
 *   profile values masked as the portal profile masks them, and a timeline
 *   that says "staff", never who.
 * - **Staff:** the member, the branch, the savings records it touches (hold,
 *   approval, transaction, opened account) and a timeline with names.
 *   Profile values in full only with `borrowers:view`, payout numbers in full
 *   only with `savings:view` (the caller's branch is already checked).
 *
 * Money is a 2-decimal string as stored; nothing is computed here.
 */
final class RequestPresenter
{
    public function __construct(
        private readonly ProfileFields $fields,
        private readonly Presenter $savings,
    ) {}

    /** The request in a line, for notices, audit rows and lists. */
    public function summary(PortalRequest $request): string
    {
        return match ($request->type) {
            PortalRequest::TYPE_SAVINGS_WITHDRAWAL => 'withdrawal of '.$this->peso($request->amount).' from '.($this->account($request)?->account_number ?? 'a savings account'),
            PortalRequest::TYPE_SAVINGS_OPENING => 'new '.($this->product($request)?->name ?? 'savings').' account with '.$this->peso($request->amount),
            default => 'profile change ('.implode(', ', array_map(
                fn (string $field): string => strtolower(ProfileFields::LABELS[$field] ?? $field),
                array_keys($request->details ?? []),
            )).')',
        };
    }

    /** "₱1,234.50". */
    public function peso(mixed $pesos): string
    {
        return Refuse::peso(SavingsMath::centavos($pesos === null ? 0 : (string) $pesos));
    }

    /** @return array<string, mixed> */
    public function memberRow(PortalRequest $request): array
    {
        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'type' => $request->type,
            'status' => $request->status,
            'summary' => ucfirst($this->summary($request)),
            'amount' => $request->amount === null ? null : (string) $request->amount,
            'expires_on' => $request->expires_on?->toDateString(),
            'created_at' => $request->created_at?->toJSON(),
            'updated_at' => $request->updated_at?->toJSON(),
            'can_cancel' => in_array($request->status, RequestDesk::CANCELLABLE, true),
            'can_reply' => $request->status === PortalRequest::STATUS_NEED_INFO,
        ];
    }

    /** @return array<string, mixed> */
    public function member(PortalRequest $request): array
    {
        return [
            ...$this->memberRow($request),
            'details' => $this->details($request, fullProfile: false, fullPayout: false),
            'timeline' => $this->events($request)->map(fn (PortalRequestEvent $e): array => [
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'actor' => $e->actor_type,
                'message' => $e->message,
                'created_at' => $e->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function staffRow(PortalRequest $request): array
    {
        $member = $request->borrower;

        return [
            'id' => $request->id,
            'reference' => $request->reference,
            'type' => $request->type,
            'status' => $request->status,
            'summary' => ucfirst($this->summary($request)),
            'amount' => $request->amount === null ? null : (string) $request->amount,
            'member' => $member === null ? null : ['id' => $member->id, 'name' => $member->full_name, 'member_number' => MemberNumber::of($member)],
            'branch' => $request->branch === null ? null : ['id' => $request->branch->id, 'name' => $request->branch->name],
            'expires_on' => $request->expires_on?->toDateString(),
            'decided_by' => $this->person($request->decided_by),
            'decided_at' => $request->decided_at?->toJSON(),
            'created_at' => $request->created_at?->toJSON(),
            'updated_at' => $request->updated_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    public function staff(PortalRequest $request, User $viewer): array
    {
        $hold = $request->savings_hold_id === null ? null : SavingsHold::query()->find($request->savings_hold_id);
        $approval = $request->savings_approval_id === null ? null : SavingsApproval::query()->find($request->savings_approval_id);
        $transaction = $request->savings_transaction_id === null ? null : SavingsTransaction::query()->find($request->savings_transaction_id);
        $opened = $request->opened_account_id === null ? null : SavingsAccount::query()->find($request->opened_account_id);

        return [
            ...$this->staffRow($request),
            'details' => $this->details($request, fullProfile: $viewer->can('borrowers:view'), fullPayout: $viewer->can('savings:view')),
            'hold' => $hold === null ? null : [
                'id' => $hold->id,
                'amount' => (string) $hold->amount,
                'status' => $hold->status,
                'expires_on' => $hold->expires_on?->toDateString(),
            ],
            'approval' => $approval === null ? null : $this->savings->approval($approval),
            'transaction' => $transaction === null ? null : $this->savings->transaction($transaction),
            'opened_account' => $opened === null ? null : ['id' => $opened->id, 'account_number' => $opened->account_number],
            'timeline' => $this->events($request)->map(fn (PortalRequestEvent $e): array => [
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'actor' => $e->actor_type,
                'user' => $this->person($e->user_id),
                'message' => $e->message,
                'created_at' => $e->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function details(PortalRequest $request, bool $fullProfile, bool $fullPayout): array
    {
        $details = $request->details ?? [];

        return match ($request->type) {
            PortalRequest::TYPE_SAVINGS_WITHDRAWAL => [
                'account_id' => $request->savings_account_id,
                'account_number' => $this->account($request)?->account_number,
                'product_name' => $this->account($request)?->product?->name,
                'method' => $details['method'] ?? null,
                'payout' => $this->payout($details, $fullPayout),
            ],
            PortalRequest::TYPE_SAVINGS_OPENING => [
                'product_id' => $request->savings_product_id,
                'product_name' => $this->product($request)?->name,
                'initial_deposit' => $request->amount === null ? null : (string) $request->amount,
            ],
            default => ['changes' => $this->fields->changes($details, $request->current_values ?? [], $fullProfile)],
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{bank_name: string|null, account_name: string|null, account_number: string|null, gcash_number: string|null}
     */
    private function payout(array $details, bool $full): array
    {
        $number = fn (?string $value): ?string => $value === null ? null : ($full ? $value : self::lastFour($value));

        return [
            'bank_name' => $details['bank_name'] ?? null,
            'account_name' => $details['account_name'] ?? null,
            'account_number' => $number($details['account_number'] ?? null),
            'gcash_number' => isset($details['gcash_number'])
                ? ($full ? $details['gcash_number'] : PhilippineMobile::mask((string) $details['gcash_number']))
                : null,
        ];
    }

    /** "•••• 7890": a payout number shown or audited masked to its last 4 (portal design §5). */
    public static function lastFour(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return '•••• '.substr($digits, -4);
    }

    /** @return Collection<int, PortalRequestEvent> */
    private function events(PortalRequest $request): Collection
    {
        return $request->events()->orderBy('id')->get();
    }

    private function account(PortalRequest $request): ?SavingsAccount
    {
        return $request->savings_account_id === null ? null : SavingsAccount::query()->with('product:id,name')->find($request->savings_account_id);
    }

    private function product(PortalRequest $request): ?SavingsProduct
    {
        return $request->savings_product_id === null ? null : SavingsProduct::query()->find($request->savings_product_id, ['id', 'name']);
    }

    /** @return array{id: int, name: string}|null */
    private function person(?int $userId): ?array
    {
        $user = $userId === null ? null : User::query()->find($userId);

        return $user === null ? null : ['id' => $user->id, 'name' => $user->full_name];
    }
}
