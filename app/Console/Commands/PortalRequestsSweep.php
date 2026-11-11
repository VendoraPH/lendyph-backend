<?php

namespace App\Console\Commands;

use App\Services\Portal\Requests\RequestSweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The nightly member portal requests sweep (portal design §6, 00:45 Manila,
 * after `savings:expire` at 00:30): open requests past their date expire
 * (with their holds), and withdrawals waiting for a second approval follow
 * that approval. Every change is re-checked under its locks, so running it
 * twice changes nothing more.
 */
#[Signature('portal:requests-sweep')]
#[Description('Expire member portal requests past their date and sync withdrawals with their savings approvals')]
class PortalRequestsSweep extends Command
{
    public function handle(RequestSweep $sweep): int
    {
        $result = $sweep->run();

        $this->info("Expired {$result['expired']} request(s); synced {$result['synced']} with their savings approval.");

        return self::SUCCESS;
    }
}
