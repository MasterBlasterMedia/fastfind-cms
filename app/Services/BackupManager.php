<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/** Local SQLite backup and disposable restoration verification. Never writes to the live database. */
final class BackupManager
{
    public function create(): array
    {
        return Cache::lock('fastfind-backup', 1800)->block(5, function () {
            if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('This backup command supports SQLite only. Use native database backup tooling for PostgreSQL or MySQL.');
            if (DB::transactionLevel() > 0) throw new RuntimeException('A consistent SQLite snapshot cannot be taken inside an active transaction.');
            if (config('filesystems.disks.public.driver') !== 'local') throw new RuntimeException('This backup command supports local public media storage only.');
            $root = $this->root();
            $id = now()->utc()->format('Ymd\THis\Z').'-'.Str::uuid();
            $directory = $root.DIRECTORY_SEPARATOR.$id;
            File::makeDirectory($directory, 0700);
            try {
                $database = $directory.DIRECTORY_SEPARATOR.'database.sqlite';
                // VACUUM INTO exports a transactionally consistent, compact SQLite snapshot.
                $pdo = DB::connection()->getPdo();
                $pdo->exec('VACUUM INTO '.$pdo->quote($database));
                $snapshot = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $snapshot->exec('PRAGMA trusted_schema = OFF');
                $databaseInfo = ['file' => 'database.sqlite', 'sha256' => hash_file('sha256', $database), 'bytes' => filesize($database), 'tables' => $this->tableCounts($snapshot)];
                $snapshot = null;
                $source = rtrim(Storage::disk('public')->path(''), '/\\');
                if (is_link($source)) throw new RuntimeException('The media root must not be a symbolic link.');
                if (!is_dir($source)) File::makeDirectory($source, 0700, true);
                $source = realpath($source);
                $files = $this->mediaFiles($source);
                $media = [];
                $bytes = $databaseInfo['bytes'];
                if ($bytes > config('platform.backups.max_bytes')) throw new RuntimeException('Backup exceeds the configured size limit.');
                foreach ($files as $file) {
                    $relative = str_replace('\\', '/', substr($file, strlen($source) + 1));
                    $bytes += filesize($file);
                    if ($bytes > config('platform.backups.max_bytes')) throw new RuntimeException('Backup exceeds the configured size limit.');
                    $destination = $directory.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    File::ensureDirectoryExists(dirname($destination), 0700);
                    if (!copy($file, $destination)) throw new RuntimeException('A media file could not be copied. Retry while writes are paused.');
                    $media[] = ['file' => $relative, 'sha256' => hash_file('sha256', $destination), 'bytes' => filesize($destination)];
                }
                $manifest = ['format' => 1, 'id' => $id, 'created_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'), 'database' => $databaseInfo, 'media' => $media, 'bytes' => $bytes];
                $this->writeJson($directory.DIRECTORY_SEPARATOR.'manifest.json', $manifest);
                $verification = $this->verify($id);
                return [...$manifest, 'path' => $directory, 'verification' => $verification];
            } catch (\Throwable $error) {
                $this->removeOwnedDirectory($directory, $root);
                throw $error;
            }
        });
    }

    public function verify(string $id): array
    {
        $directory = $this->backupDirectory($id);
        $manifestPath = $this->containedFile($directory, 'manifest.json');
        if (filesize($manifestPath) > 8 * 1024 * 1024) throw new RuntimeException('Backup manifest exceeds the size limit.');
        $manifest = json_decode(file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['id'] ?? null) !== $id || ($manifest['database']['file'] ?? null) !== 'database.sqlite'
            || !is_array($manifest['media'] ?? null) || count($manifest['media']) > config('platform.backups.max_files')) throw new RuntimeException('Invalid backup manifest.');
        $restoreRoot = $this->root();
        $restore = $restoreRoot.DIRECTORY_SEPARATOR.'.verify-'.Str::uuid();
        File::makeDirectory($restore, 0700);
        $restored = null;
        try {
            $database = $this->containedFile($directory, 'database.sqlite');
            $this->verifyFile($database, $manifest['database']);
            if (filesize($database) > config('platform.backups.max_bytes')) throw new RuntimeException('Backup exceeds the configured size limit.');
            $restoredDatabase = $restore.DIRECTORY_SEPARATOR.'database.sqlite';
            if (!copy($database, $restoredDatabase)) throw new RuntimeException('Could not restore the verification database.');
            $bytes = filesize($database);
            $seen = [];
            foreach ($manifest['media'] as $entry) {
                $name = $entry['file'] ?? null;
                if (!is_string($name) || isset($seen[$name])) throw new RuntimeException('Invalid or duplicate media manifest entry.');
                $seen[$name] = true;
                $file = $this->containedFile($directory, 'media/'.$name);
                $this->verifyFile($file, $entry);
                $bytes += filesize($file);
                if ($bytes > config('platform.backups.max_bytes')) throw new RuntimeException('Backup exceeds the configured size limit.');
                $destination = $restore.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                File::ensureDirectoryExists(dirname($destination), 0700);
                if (!copy($file, $destination) || hash_file('sha256', $destination) !== $entry['sha256']) throw new RuntimeException('A restored media file failed verification.');
            }
            $restored = new PDO('sqlite:'.$restoredDatabase, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $restored->exec('PRAGMA trusted_schema = OFF');
            $integrity = $restored->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
            if ($integrity !== ['ok'] || $restored->query('PRAGMA foreign_key_check')->fetchAll() !== []) throw new RuntimeException('Restored database failed SQLite integrity checks.');
            $tables = $this->tableCounts($restored);
            if ($tables !== ($manifest['database']['tables'] ?? null)) throw new RuntimeException('Restored database row counts differ from the snapshot.');
            // A disposable write proves this is an operable restored database, not just readable bytes.
            $probe = 'fastfind_restore_verification_'.bin2hex(random_bytes(8));
            $restored->exec('CREATE TABLE '.$probe.' (verified INTEGER NOT NULL)');
            $restored->exec('INSERT INTO '.$probe.' VALUES (1)');
            if ((int) $restored->query('SELECT verified FROM '.$probe)->fetchColumn() !== 1) throw new RuntimeException('Restored database write verification failed.');
            $report = ['verified_at' => now()->utc()->toIso8601String(), 'integrity' => 'ok', 'tables' => $tables, 'media_files' => count($manifest['media']), 'bytes' => $bytes];
            $this->writeJson($directory.DIRECTORY_SEPARATOR.'verification.json', $report);
            return $report;
        } finally {
            $restored = null;
            $this->removeOwnedDirectory($restore, $restoreRoot);
        }
    }

    public function prune(int $retain): int
    {
        if ($retain < 1 || $retain > 100) throw new RuntimeException('Retention must be between 1 and 100 backups.');
        $root = $this->root();
        $directories = array_filter(File::directories($root), fn ($directory) => $this->validId(basename($directory)) && is_file($directory.DIRECTORY_SEPARATOR.'verification.json') && !is_link($directory));
        usort($directories, function ($left, $right) {
            $leftManifest = json_decode(file_get_contents($this->containedFile($left, 'manifest.json')), true, 64, JSON_THROW_ON_ERROR);
            $rightManifest = json_decode(file_get_contents($this->containedFile($right, 'manifest.json')), true, 64, JSON_THROW_ON_ERROR);
            return strcmp($rightManifest['created_at'] ?? '', $leftManifest['created_at'] ?? '');
        });
        $deleted = 0;
        foreach (array_slice($directories, $retain) as $directory) {
            $this->removeOwnedDirectory($directory, $root);
            $deleted++;
        }
        return $deleted;
    }

    private function root(): string
    {
        $root = config('platform.backups.path');
        if (is_link($root)) throw new RuntimeException('The backup root must not be a symbolic link.');
        File::ensureDirectoryExists($root, 0700);
        $resolved = realpath($root);
        if (!$resolved) throw new RuntimeException('The backup root could not be resolved.');
        foreach ([public_path(), config('filesystems.disks.public.root')] as $publicRoot) {
            $public = realpath($publicRoot);
            if ($public && ($resolved === $public || str_starts_with($resolved, $public.DIRECTORY_SEPARATOR))) throw new RuntimeException('Backups must be stored outside public web and media roots.');
        }
        return $resolved;
    }

    private function backupDirectory(string $id): string
    {
        if (!$this->validId($id)) throw new RuntimeException('Invalid backup identifier.');
        $path = $this->root().DIRECTORY_SEPARATOR.$id;
        if (!is_dir($path) || is_link($path)) throw new RuntimeException('Backup was not found.');
        return $path;
    }

    private function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id);
    }

    private function containedFile(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, '//') || str_starts_with($relative, '/') || preg_match('~(^|/)\.\.?(/|$)|[\x00-\x1f:]~', $relative)) throw new RuntimeException('Unsafe backup path.');
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parts = explode('/', $relative);
        $check = $root;
        foreach ($parts as $part) { $check .= DIRECTORY_SEPARATOR.$part; if (is_link($check)) throw new RuntimeException('Backup symlinks are not allowed.'); }
        $resolved = realpath($path);
        if (!$resolved || !str_starts_with($resolved, realpath($root).DIRECTORY_SEPARATOR) || !is_file($resolved)) throw new RuntimeException('Backup file is missing or outside its root.');
        return $resolved;
    }

    private function mediaFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) throw new RuntimeException('Media symlinks cannot be backed up.');
            if ($entry->isFile()) {
                $files[] = $this->containedFile($root, str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1)));
                if (count($files) > config('platform.backups.max_files')) throw new RuntimeException('Too many media files for one backup.');
            }
        }
        sort($files);
        return $files;
    }

    private function verifyFile(string $file, array $entry): void
    {
        if (!is_string($entry['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) || !is_int($entry['bytes'] ?? null)
            || filesize($file) !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_file('sha256', $file))) throw new RuntimeException('Backup checksum or file size verification failed.');
    }

    private function tableCounts(PDO $pdo): array
    {
        $counts = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM "'.str_replace('"', '""', $table).'"')->fetchColumn();
        }
        return $counts;
    }

    private function writeJson(string $path, array $data): void
    {
        if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Backup metadata could not be written.');
    }

    private function removeOwnedDirectory(string $path, string $root): void
    {
        $resolved = realpath($path);
        if ($resolved && !is_link($path) && dirname($resolved) === realpath($root)) File::deleteDirectory($resolved);
    }
}
