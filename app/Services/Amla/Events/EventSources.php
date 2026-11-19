<?php

namespace App\Services\Amla\Events;

use App\Services\Amla\Events\Sources\GCash;
use App\Services\Amla\Events\Sources\LoanReleases;
use App\Services\Amla\Events\Sources\Repayments;
use App\Services\Amla\Events\Sources\RepaymentVoids;
use App\Services\Amla\Events\Sources\SavingsTransactions;
use App\Services\Amla\Events\Sources\ShareCapital;
use App\Services\Amla\Events\Sources\TimeDepositPayouts;
use App\Services\Amla\Events\Sources\TimeDepositPlacements;

/**
 * Every AMLA event source, in harvest order: a receipt is harvested before its
 * void, and a savings row before the reversal that marks it.
 */
final class EventSources
{
    /** @var list<class-string<EventSource>> */
    private const SOURCES = [
        LoanReleases::class,
        Repayments::class,
        RepaymentVoids::class,
        SavingsTransactions::class,
        TimeDepositPlacements::class,
        TimeDepositPayouts::class,
        ShareCapital::class,
        GCash::class,
    ];

    /** @return list<EventSource> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::SOURCES);
    }
}
