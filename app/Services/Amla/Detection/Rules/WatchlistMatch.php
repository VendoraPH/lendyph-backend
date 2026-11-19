<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * L. Relevant watchlist matches: possible sanctions or watchlist matches are
 * found and reviewed in watchlist screening (AmlaScreeningMatch), restricted
 * to head office and never confirmed by themselves. They aren't raised again
 * as monitoring alerts, so this rule raises nothing.
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
        return 'Not raised here: watchlist matches are found and reviewed in watchlist screening, never as monitoring alerts.';
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        return null;
    }
}
