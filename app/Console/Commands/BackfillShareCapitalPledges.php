<?php

namespace App\Console\Commands;

use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pledges:backfill {--dry-run : Show count without creating}')]
#[Description('Create default share capital pledges for borrowers that do not have one')]
class BackfillShareCapitalPledges extends Command
{
    public function handle(): int
    {
        $query = Borrower::whereDoesntHave('shareCapitalPledge');
        $count = $query->count();

        if ($count === 0) {
            $this->info('All borrowers already have pledges.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Found {$count} borrower(s) without pledges.");

            return self::SUCCESS;
        }

        $now = now();
        $created = 0;

        // insert() bypasses Eloquent events intentionally — pledges are being bulk-created here
        $query->chunkById(500, function ($borrowers) use ($now, &$created) {
            ShareCapitalPledge::insert(
                $borrowers->map(fn (Borrower $b) => [
                    'borrower_id' => $b->id,
                    'amount' => 0,
                    'schedule' => '15/30',
                    'auto_credit' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray()
            );
            $created += $borrowers->count();
        });

        $this->info("Created {$created} pledge(s).");

        return self::SUCCESS;
    }
}
