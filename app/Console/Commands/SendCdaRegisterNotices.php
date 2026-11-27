<?php

namespace App\Console\Commands;

use App\Services\Cda\Registers\RegisterNotices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled at 00:55 Manila (routes/console/cda/registers.php), after the
 * deadline reminders at 00:50. In-app notices only, each sent once
 * (`cda_notices_sent`), so a second run the same night sends nothing. Writes
 * no files.
 */
#[Signature('cda:register-notices')]
#[Description('Notify CDA staff of documents expiring within 30 days and officers missing a required training')]
class SendCdaRegisterNotices extends Command
{
    public function handle(RegisterNotices $notices): int
    {
        $sent = $notices->run();

        $this->info("Sent {$sent['documents']} expiring-document and {$sent['trainings']} officer-training notices.");

        return self::SUCCESS;
    }
}
