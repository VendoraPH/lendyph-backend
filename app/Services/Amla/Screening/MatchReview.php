<?php

namespace App\Services\Amla\Screening;

use App\Models\AmlaScreeningMatch;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use Illuminate\Support\Facades\DB;

/**
 * A person's decision on a potential match (task §11: potential match
 * investigation and false-positive documentation): confirm it, or clear it
 * as a false positive, with a reason, once, under the match's row lock. The
 * reason is encrypted; the AMLA log records the match id only. Confirming
 * records a finding; it blocks, freezes or files nothing by itself (an order
 * is its own record).
 */
final class MatchReview
{
    public function __construct(private readonly AmlaLog $log) {}

    public function decide(User $user, int $matchId, string $status, string $reason): AmlaScreeningMatch
    {
        return DB::transaction(function () use ($user, $matchId, $status, $reason) {
            $match = AmlaScreeningMatch::query()->lockForUpdate()->findOrFail($matchId);

            if ($match->status !== AmlaScreeningMatch::PENDING) {
                $words = $match->status === AmlaScreeningMatch::CLEARED ? 'cleared as a false positive' : 'confirmed';
                abort(409, "This match is already {$words}.");
            }

            $match->forceFill([
                'status' => $status,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'decision_reason' => trim($reason),
            ])->save();

            $this->log->record($status === AmlaScreeningMatch::CONFIRMED ? 'screening_match_confirmed' : 'screening_match_cleared', $user, 'screening_match', $match->id, [
                'match_id' => $match->id,
            ]);

            return $match;
        });
    }
}
