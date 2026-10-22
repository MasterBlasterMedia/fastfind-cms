<?php

namespace App\Console\Commands;

use App\Services\BackupManager;
use Illuminate\Console\Command;

final class CreateBackup extends Command
{
    protected $signature = 'fastfind:backup {--retain= : Explicitly retain only the newest N verified backups}';
    protected $description = 'Create a private SQLite/media backup and verify a disposable restoration';

    public function handle(BackupManager $backups): int
    {
        try {
            $retain = $this->option('retain');
            if ($retain !== null && (!ctype_digit((string) $retain) || (int) $retain < 1 || (int) $retain > 100)) throw new \RuntimeException('Retention must be an integer between 1 and 100.');
            $result = $backups->create();
            $this->info('Verified backup: '.$result['id']);
            $this->line('Private directory: '.$result['path']);
            $this->line('Restored media files: '.$result['verification']['media_files']);
            if ($retain !== null) $this->line('Pruned backups: '.$backups->prune((int) $retain));
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
