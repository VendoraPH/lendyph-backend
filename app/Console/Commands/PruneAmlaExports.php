<?php

namespace App\Console\Commands;

use App\Services\Amla\Reports\ReportExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:prune-exports')]
#[Description('Delete AMLA export files whose download link has expired; each export\'s record and checksum stay')]
class PruneAmlaExports extends Command
{
    public function handle(ReportExports $exports): int
    {
        $this->line(sprintf('%d expired AMLA export file(s) deleted.', $exports->pruneExpired()));

        return self::SUCCESS;
    }
}
