<?php

namespace App\Console\Commands;

use App\Services\Amla\Events\Harvester;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:harvest')]
#[Description('Turn new money movements into AMLA events (only while AMLA monitoring is on); read-only on the money tables')]
class HarvestAmlaEvents extends Command
{
    public function handle(Harvester $harvester): int
    {
        $results = $harvester->harvest();

        if ($results === null) {
            $this->line('AMLA monitoring is off, so nothing was harvested.');

            return self::SUCCESS;
        }

        foreach ($results as $line) {
            $this->line($line['started']
                ? "{$line['words']}: monitoring started."
                : sprintf('%s: %d event%s added, %d marked reversed, %d failed.', $line['words'], $line['added'], $line['added'] === 1 ? '' : 's', $line['reversed'], $line['failed']));
        }

        return self::SUCCESS;
    }
}
