<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * L. Relevant watchlist matches: a restricted, critical alert for a possible
 * sanctions or watchlist match, never confirmed by itself. It waits for
 * watchlist screening (AMLA-B5): until then there is nothing to match, so it
 * raises nothing.
 */
final class WatchlistMatch extends DetectionRule
{
    public function code(): string
    {
        return 'L';
    }

    public function words(): string
    {
        return 'Relevant watchlist matches';
    }

    public function description(): string
    {
        return 'A possible match against an uploaded watchlist, to be verified by a person.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function unavailable(): ?string
    {
        return 'Not available yet: watchlist screening (AMLA-B5) isn\'t built, so there is nothing to match.';
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        return null;
    }
}
