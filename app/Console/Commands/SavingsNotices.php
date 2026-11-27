<?php

namespace App\Console\Commands;

use App\Services\Savings\Notices\NightlyNotices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The nightly savings notices (gap round S4, 00:50 Manila, after the 00:40
 * maturity job): time deposits reaching a `notice_days` mark and accounts
 * entering the approaching-dormancy window, to staff and, where the portal is
 * on, the member. In-app only. Running it twice sends nothing new; with no
 * savings rows it does nothing.
 */
#[Signature('savings:notices')]
#[Description('Send the in-app time deposit maturing and dormancy warning notices')]
class SavingsNotices extends Command
{
    public function handle(NightlyNotices $notices): int
    {
        $sent = $notices->run();

        $this->info("Sent {$sent['maturing']} time deposit maturing notice(s) and {$sent['dormancy_warnings']} dormancy warning(s).");

        return self::SUCCESS;
    }
}
