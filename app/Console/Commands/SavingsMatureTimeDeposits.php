<?php

namespace App\Console\Commands;

use App\Services\Savings\TimeDeposits\MaturitySweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly at 00:40 Manila (routes/console/savings/time-deposits.php): moves
 * due time deposits to matured and settles, as the system, only those that
 * move no cash (a full rollover, or a payout into the member's savings
 * account). Safe to run twice: each placement is re-checked under its lock,
 * and its settlement journal posts once. Does nothing when there are none.
 */
#[Signature('savings:mature-time-deposits')]
#[Description('Mature due time deposits and settle, as the system, those that move no cash')]
class SavingsMatureTimeDeposits extends Command
{
    public function handle(MaturitySweeper $sweeper): int
    {
        $counts = $sweeper->run();

        $this->info(sprintf(
            'Time deposits: %d matured, %d settled automatically, %d left for staff, %d could not be settled (see the log).',
            $counts['matured'],
            $counts['settled'],
            $counts['waiting'],
            $counts['refused'],
        ));

        return self::SUCCESS;
    }
}
