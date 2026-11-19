<?php

namespace App\Console\Commands;

use App\Services\Amla\Reports\ReportExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:prune-exports {--full : Check every expired export, not only those expired in the last day}')]
#[Description('Delete AMLA export files whose download link has expired (in the last day; every one with --full); each export\'s record and checksum stay')]
class PruneAmlaExports extends Command
{
    public function handle(ReportExports $exports): int
    {
        $this->line(sprintf('%d expired AMLA export file(s) deleted.', $exports->pruneExpired(full: (bool) $this->option('full'))));

        return self::SUCCESS;
    }
}
