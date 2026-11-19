<?php

namespace App\Console\Commands;

use App\Services\Amla\Events\Coverage;
use App\Services\Amla\Events\EventSources;
use App\Services\Amla\Foundation\AmlaMonitoring;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('amla:coverage-check {--date= : The Manila day to check, YYYY-MM-DD (default: yesterday)}')]
#[Description('Compare one day\'s money rows with their AMLA events, by source; rows without an event are Unscreened')]
class CheckAmlaCoverage extends Command
{
    public function handle(Coverage $coverage, AmlaMonitoring $monitoring, EventSources $sources): int
    {
        if (! $monitoring->enabled()) {
            $this->line('AMLA monitoring is off, so coverage was not checked.');

            return self::SUCCESS;
        }

        $day = (string) ($this->option('date') ?? Carbon::now()->subDay()->toDateString());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            $this->error('Give the day as YYYY-MM-DD.');

            return self::FAILURE;
        }

        $parsed = Carbon::parse($day);

        $results = $coverage->check($day);
        $this->line('AMLA coverage for '.$parsed->format('M j, Y').':');

        foreach ($sources->all() as $source) {
            $line = $results[$source->name()];
            $this->line(sprintf('  %s: %d monitored, %d harvested, %d failed, %d Unscreened, %d pending.',
                $source->words(), $line['rows'], $line['events'], $line['failed'], $line['unscreened'], $line['pending']));
        }

        return self::SUCCESS;
    }
}
