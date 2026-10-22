<?php

namespace App\Console\Commands;

use App\Services\BackupManager;
use Illuminate\Console\Command;

final class VerifyBackup extends Command
{
    protected $signature = 'fastfind:backup-verify {id : Identifier printed by fastfind:backup}';
    protected $description = 'Restore an existing backup into a disposable private directory and verify integrity';

    public function handle(BackupManager $backups): int
    {
        try {
            $report = $backups->verify($this->argument('id'));
            $this->info('Disposable restoration verified: '.count($report['tables']).' tables, '.$report['media_files'].' media files.');
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
