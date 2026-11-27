<?php

namespace App\Services\Amla\Detection\Rules;

use App\Models\AmlaEvent;
use App\Services\Amla\Detection\DetectionContext;
use App\Services\Amla\Detection\DetectionRule;
use App\Services\Amla\Detection\Finding;

/**
 * G. Third party transactions: stays inactive. Lendy records no payer or
 * counterparty on payments and deposits (AMLA design §6), so there is nothing
 * to compare, and the rule can't be switched on.
 */
final class ThirdParty extends DetectionRule
{
    public function code(): string
    {
        return 'G';
    }

    public function words(): string
    {
        return 'Third party transactions';
    }

    public function description(): string
    {
        return 'Payments or transfers involving unexplained third parties.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function unavailable(): ?string
    {
        return 'Not available: Lendy records no payer or counterparty.';
    }

    public function canBeActive(): bool
    {
        return false;
    }

    public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding
    {
        return null;
    }
}
