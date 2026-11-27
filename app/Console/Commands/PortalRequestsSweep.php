<?php

namespace App\Console\Commands;

use App\Services\Portal\Requests\RequestSweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The nightly member portal requests sweep (portal design §6, 00:45 Manila,
 * after `savings:expire` at 00:30): open requests past their date expire
 * (with their holds), withdrawals waiting for a second approval follow
 * that approval, and requests of portal accounts already turned off are
 * ended (cancelled, holds released) if their clean-up didn't run. Every change is re-checked under its locks, so running it
 * twice changes nothing more.
 */
#[Signature('portal:requests-sweep')]
#[Description('End requests of portal accounts turned off, expire member portal requests past their date and sync withdrawals with their savings approvals')]
class PortalRequestsSweep extends Command
{
    public function handle(RequestSweep $sweep): int
    {
        $result = $sweep->run();

        $this->info("Access turned off: ended {$result['ended']} request(s). Expired {$result['expired']} request(s); synced {$result['synced']} with their savings approval.");

        return self::SUCCESS;
    }
}
