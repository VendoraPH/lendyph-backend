<?php

namespace App\Console\Commands;

use App\Services\Cda\Registers\TrainingDocumentRefiler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cda:refile-training-documents {--dry-run : Show which documents would move without writing}')]
#[Description('Move CDA training documents not linked to a report into their training\'s own reporting period, each audited as a system change')]
class RefileTrainingDocuments extends Command
{
    public function handle(TrainingDocumentRefiler $refiler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $refiler->run($dryRun);

        foreach ($result['moved'] as $move) {
            $this->line(sprintf(
                '  Document #%d of training #%d: %s from %d to %d',
                $move['document_id'],
                $move['training_id'],
                $dryRun ? 'would move' : 'moved',
                $move['from'],
                $move['to'],
            ));
        }

        foreach ($result['skipped'] as $skip) {
            $this->line("  Training #{$skip['training_id']} left as it is: {$skip['reason']}");
        }

        $this->info(sprintf(
            '%s %d document(s); %d left as they are.',
            $dryRun ? 'Would move' : 'Moved',
            count($result['moved']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
