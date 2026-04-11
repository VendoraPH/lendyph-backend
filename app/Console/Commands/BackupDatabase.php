<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('db:backup {--keep=7 : Number of daily backups to retain}')]
#[Description('Create a MySQL dump backup and prune old backups')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $db = config('database.connections.mysql');
        $backupDir = storage_path('backups');

        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'lendyph-'.date('Y-m-d-His').'.sql.gz';
        $filepath = $backupDir.'/'.$filename;

        // Use MYSQL_PWD env var to avoid leaking password in process list (ps aux)
        $result = Process::env(['MYSQL_PWD' => $db['password']])->run(sprintf(
            'mysqldump -h%s -P%s -u%s %s | gzip > %s',
            escapeshellarg($db['host']),
            escapeshellarg($db['port']),
            escapeshellarg($db['username']),
            escapeshellarg($db['database']),
            escapeshellarg($filepath),
        ));

        if ($result->failed()) {
            $this->error('Backup failed. Check server logs for details.');

            return self::FAILURE;
        }

        $size = round(filesize($filepath) / 1024, 1);
        $this->info("Backup created: {$filename} ({$size} KB)");

        // Prune old backups
        $keep = (int) $this->option('keep');
        $files = glob($backupDir.'/lendyph-*.sql.gz');
        rsort($files);

        $pruned = 0;
        foreach (array_slice($files, $keep) as $old) {
            unlink($old);
            $pruned++;
        }

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} old backup(s), keeping latest {$keep}.");
        }

        return self::SUCCESS;
    }
}
