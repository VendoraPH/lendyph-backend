<?php

namespace App\Http\Resources;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\InterbranchTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An interbranch transfer (Phase 6 MB-BE5). `amount` is integer centavos, as
 * on every accounting screen. `actions` says what the caller may do next by
 * the same rules InterbranchTransfers enforces (the maker never approves), so
 * the screen never offers a step the server would refuse.
 *
 * @mixin InterbranchTransfer
 */
class InterbranchTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InterbranchTransfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'transfer_number' => $transfer->transfer_number,
            'status' => $transfer->status,
            'status_label' => InterbranchTransfer::STATUS_LABELS[$transfer->status] ?? $transfer->status,
            'source_branch' => self::branch($transfer->sourceBranch),
            'destination_branch' => self::branch($transfer->destinationBranch),
            'from_account' => self::account($transfer->fromAccount),
            'to_account' => self::account($transfer->toAccount),
            'clearing_account' => self::account($transfer->clearingAccount),
            'amount' => $transfer->amount,
            'purpose' => $transfer->purpose,
            'reference' => $transfer->reference,
            'age_days' => $transfer->status === InterbranchTransfer::STATUS_IN_TRANSIT ? $transfer->ageInDays() : null,
            'created_by' => self::user($transfer->creator),
            'created_at' => $transfer->created_at,
            'approved_by' => self::user($transfer->approver),
            'approved_at' => $transfer->approved_at,
            'received_by' => self::user($transfer->receiver),
            'received_at' => $transfer->received_at,
            'rejected_by' => self::user($transfer->rejecter),
            'rejected_at' => $transfer->rejected_at,
            'rejection_note' => $transfer->rejection_note,
            'cancelled_at' => $transfer->cancelled_at,
            'reversed_by' => self::user($transfer->reverser),
            'reversed_at' => $transfer->reversed_at,
            'reversal_reason' => $transfer->reversal_reason,
            'dispatch_journal' => self::journal($transfer->dispatchJournal),
            'receipt_journal' => self::journal($transfer->receiptJournal),
            'dispatch_reversal_journal' => self::journal($transfer->dispatchReversalJournal),
            'actions' => self::actions($transfer, $request->user()),
        ];
    }

    /** @return array{can_approve: bool, can_reject: bool, can_cancel: bool, can_receive: bool, can_reverse: bool} */
    public static function actions(InterbranchTransfer $transfer, ?User $user): array
    {
        if (! $user instanceof User) {
            return ['can_approve' => false, 'can_reject' => false, 'can_cancel' => false, 'can_receive' => false, 'can_reverse' => false];
        }

        $access = app(BranchAccess::class);
        $seesSource = $access->canSee($user, $transfer->source_branch_id);
        $seesDestination = $access->canSee($user, $transfer->destination_branch_id);
        $pending = $transfer->status === InterbranchTransfer::STATUS_PENDING;
        $inTransit = $transfer->status === InterbranchTransfer::STATUS_IN_TRANSIT;
        $isMaker = $transfer->created_by === $user->id;
        $checker = ! $isMaker && $seesSource && $user->can('interbranch_transfers:approve');

        return [
            'can_approve' => $pending && $checker,
            'can_reject' => $pending && $checker,
            'can_cancel' => $pending && $isMaker && $user->can('interbranch_transfers:create'),
            'can_receive' => $inTransit && ! $isMaker && $transfer->approved_by !== $user->id
                && $seesDestination && $user->can('interbranch_transfers:receive'),
            'can_reverse' => $inTransit && $checker,
        ];
    }

    /** @return array{id: int, name: string, code: string|null}|null */
    private static function branch(?Branch $branch): ?array
    {
        return $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code];
    }

    /** @return array{id: int, code: string, name: string}|null */
    private static function account(?AccountingAccount $account): ?array
    {
        return $account === null ? null : ['id' => $account->id, 'code' => $account->code, 'name' => $account->name];
    }

    /** @return array{id: int, full_name: string}|null */
    private static function user(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'full_name' => $user->full_name];
    }

    /** @return array{id: int, journal_no: string|null}|null */
    private static function journal(?AccountingJournal $journal): ?array
    {
        return $journal === null ? null : ['id' => $journal->id, 'journal_no' => $journal->journal_no];
    }
}
