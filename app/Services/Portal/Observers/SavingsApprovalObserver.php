<?php

namespace App\Services\Portal\Observers;

use App\Models\PortalRequest;
use App\Models\SavingsApproval;
use App\Services\Portal\Requests\RequestSweep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a member's withdrawal request in step with the savings approval its
 * staff approval opened (portal design §6, step 3). Registered by
 * convention (PortalServiceProvider), so no savings file is edited.
 *
 * When the approval leaves `pending` (approved and posted, rejected or
 * cancelled), the request is synced after commit, in its own transaction.
 * A failure is logged and never fails the checker's decision; the nightly
 * `portal:requests-sweep` catches it up, and also catches `savings:expire`'s
 * bulk updates, which fire no model events.
 */
class SavingsApprovalObserver
{
    public function updated(SavingsApproval $approval): void
    {
        if ($approval->kind !== 'withdrawal' || ! $approval->wasChanged('status') || $approval->getOriginal('status') !== 'pending') {
            return;
        }

        $approvalId = (int) $approval->getKey();

        try {
            DB::afterCommit(function () use ($approvalId): void {
                try {
                    $requestId = PortalRequest::query()->where('savings_approval_id', $approvalId)->value('id');

                    if ($requestId !== null) {
                        app(RequestSweep::class)->syncApproved((int) $requestId);
                    }
                } catch (Throwable $e) {
                    $this->logFailure($approvalId, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($approvalId, $e);
        }
    }

    private function logFailure(int $approvalId, Throwable $e): void
    {
        Log::error('A member portal request could not be synced with its savings approval; the nightly sweep will retry.', [
            'savings_approval_id' => $approvalId,
            'exception' => $e,
        ]);
    }
}
