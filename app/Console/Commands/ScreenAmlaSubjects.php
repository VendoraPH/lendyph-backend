<?php

namespace App\Console\Commands;

use App\Services\Amla\Screening\Screener;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:screen')]
#[Description('Screen members, walk-ins and co-makers against the uploaded watchlists (only while AMLA monitoring is on); stores potential matches for review, confirms nothing')]
class ScreenAmlaSubjects extends Command
{
    public function handle(Screener $screener): int
    {
        $result = $screener->run();

        if ($result === null) {
            $this->line('AMLA monitoring is off, so nobody was screened.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d list version%s rescreened, %d subject%s screened, %d potential match%s found for review.',
            $result['rescreened_versions'], $result['rescreened_versions'] === 1 ? '' : 's',
            $result['screened'], $result['screened'] === 1 ? '' : 's',
            $result['matches'], $result['matches'] === 1 ? '' : 'es',
        ));

        return self::SUCCESS;
    }
}
