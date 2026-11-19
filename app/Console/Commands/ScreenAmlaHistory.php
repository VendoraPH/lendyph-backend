<?php

namespace App\Console\Commands;

use App\Services\Amla\Events\HistoryScreening;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('amla:screen-history {--from= : The first day to screen, YYYY-MM-DD} {--dry-run : Count what would be added without writing anything}')]
#[Description('On counsel\'s advice only: turn money rows recorded before AMLA monitoring started into AMLA events; writes only AMLA tables')]
class ScreenAmlaHistory extends Command
{
    public function handle(HistoryScreening $history): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $results = $history->run((string) $this->option('from'), $dryRun);
        } catch (ValidationException $refused) {
            $this->error(collect($refused->errors())->flatten()->first());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run: nothing is written.');
        }

        foreach ($results as $line) {
            $this->line(sprintf('%s: %d to add, %d already harvested, %d to mark reversed, %d failed (recorded before %s).',
                $line['words'], $line['added'], $line['existing'], $line['reversed'], $line['failed'], $line['until']));
        }

        return self::SUCCESS;
    }
}
