<?php

namespace App\Console\Commands;

use App\Services\Amla\Cases\FilingDeadlines;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('amla:deadlines')]
#[Description('Put AMLA filings due soon, due today or overdue in compliance staff\'s AMLA inbox (once per filing and kind)')]
class RemindAmlaDeadlines extends Command
{
    public function handle(FilingDeadlines $deadlines): int
    {
        $this->line(sprintf('%d AMLA inbox item(s) added for filing deadlines.', $deadlines->remind()));

        return self::SUCCESS;
    }
}
