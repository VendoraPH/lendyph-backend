<?php

namespace App\Console\Commands;

use App\Services\Amla\Cases\SetAsideBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:backfill-set-asides {--dry-run : List what would be copied without writing}')]
#[Description('Copy CTR set-aside asks still waiting into their own rows (the AMLA report migration runs this once)')]
class BackfillAmlaSetAsides extends Command
{
    public function handle(SetAsideBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfill->run($dryRun);

        foreach ($result['skipped'] as $row) {
            $this->line("  {$row['reference']} left as it is: {$row['reason']}");
        }

        $this->line(sprintf('%s %d waiting set-aside ask(s); %d left as they are.', $dryRun ? 'Would copy' : 'Copied', count($result['copied']), count($result['skipped'])));

        return self::SUCCESS;
    }
}
