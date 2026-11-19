<?php

namespace App\Console\Commands;

use App\Services\Amla\Detection\Detector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:detect')]
#[Description('Screen new AMLA events against the CTR rule and the detection rules, and rerun rules after voids and reversals (only while AMLA monitoring is on)')]
class DetectAmla extends Command
{
    public function handle(Detector $detector): int
    {
        $counts = $detector->run();

        if ($counts === null) {
            $this->line('AMLA monitoring is off, so nothing was screened.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Screened %d event(s): %d CTR candidate(s), %d alert(s) opened, %d joined. Reruns: %d alert(s), %d cleared; %d CTR candidate(s) marked reversed.',
            $counts['screened'], $counts['ctr_candidates'], $counts['alerts_opened'], $counts['alerts_joined'],
            $counts['alerts_rerun'], $counts['alerts_cleared'], $counts['ctr_reversed'],
        ));

        return self::SUCCESS;
    }
}
