<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Models\Borrower;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;
use Illuminate\Support\Facades\DB;

/**
 * K. Missing or inconsistent identity information (AMLA design §3 conflict
 * 2): a transaction in the rule's channels above `review_amount` by a walk-in
 * with no ID on file, or a member with no valid ID and number on file. The
 * walk-in's ID stays optional; this never blocks anything.
 */
final class MissingIdentity extends DetectionRule
{
    public function code(): string
    {
        return 'K';
    }

    public function words(): string
    {
        return 'Missing or inconsistent identity information';
    }

    public function description(): string
    {
        return 'A cash transaction above the review amount by someone with no ID on file.';
    }

    public function parameters(): array
    {
        return ['review_amount' => 'peso', 'channels' => 'channels'];
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        $review = $context->centavos('review_amount');
        $channels = $context->channels();

        if (! $this->counts($trigger) || ! in_array($trigger->channel, $channels, true) || self::cents($trigger) <= $review || $this->hasId($trigger)) {
            return null;
        }

        $day = self::day($trigger);
        $events = $this->subjectEvents($trigger)->whereDate('occurred_on', $day->toDateString())->whereIn('channel', $channels)
            ->orderBy('id')->get()->filter(fn (AmlaEvent $event) => self::cents($event) > $review)->values();
        $who = $trigger->borrower_id !== null ? 'a member with no valid ID and number on file' : 'a walk-in with no ID on file';

        return new Finding(
            key: $this->subjectKey($trigger).'|'.$day->toDateString(),
            windowStart: $day->toDateString(),
            windowEnd: $day->toDateString(),
            eventIds: self::ids($events),
            reason: sprintf('%d %s transaction%s above the review amount of %s on %s, by %s.',
                $events->count(), strtolower(self::channelWords($channels)), $events->count() === 1 ? '' : 's', self::peso($review), self::dateWords($day), $who),
            indicators: [
                self::indicator('ID on file', null),
                self::indicator('Review amount', self::peso($review)),
            ],
            countedTransactions: $events->count(),
        );
    }

    private function hasId(AmlaEvent $trigger): bool
    {
        if ($trigger->borrower_id !== null) {
            $member = Borrower::query()->find($trigger->borrower_id);

            return $member !== null && CddState::memberHasValidId($member);
        }

        $walkIn = DB::table('gcash_non_members')->where('id', $trigger->gcash_non_member_id)->first(['id_type', 'id_number']);

        return $walkIn !== null && filled($walkIn->id_type) && filled($walkIn->id_number);
    }
}
