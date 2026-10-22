<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\BackupManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\TestCase;

class BackupAndHealthTest extends TestCase
{
    use DatabaseMigrations;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->backupRoot = storage_path('framework/testing/backup-'.Str::uuid());
        config(['platform.backups.path' => $this->backupRoot, 'platform.backups.max_bytes' => 2 * 1024 * 1024 * 1024]);
    }

    protected function tearDown(): void
    {
        if (isset($this->backupRoot) && str_starts_with($this->backupRoot, storage_path('framework/testing/backup-'))) File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'email' => 'backup-owner@example.test', 'password' => 'long-test-password', 'role' => 'owner', 'is_active' => true]);
    }

    public function test_sqlite_database_and_media_are_restored_and_verified_without_touching_live_data(): void
    {
        $owner = $this->owner();
        $page = Page::create(['title' => 'Backup snapshot', 'slug' => 'backup-snapshot', 'summary' => 'Original content', 'document' => ['sections' => []], 'author_id' => $owner->id]);
        Storage::disk('public')->put('images/nested/sample.txt', 'original-media');
        $backup = app(BackupManager::class)->create();
        $this->assertSame('ok', $backup['verification']['integrity']);
        $this->assertSame(1, $backup['verification']['tables']['pages']);
        $this->assertSame(1, $backup['verification']['media_files']);
        $page->update(['summary' => 'Changed after snapshot']);
        Storage::disk('public')->put('images/nested/sample.txt', 'new-live-media');
        $report = app(BackupManager::class)->verify($backup['id']);
        $this->assertSame('ok', $report['integrity']);
        $restored = new PDO('sqlite:'.$backup['path'].DIRECTORY_SEPARATOR.'database.sqlite');
        $this->assertSame('Original content', $restored->query('SELECT summary FROM pages')->fetchColumn());
        $restored = null;
        $this->assertSame('Changed after snapshot', $page->fresh()->summary);
        $this->assertSame('new-live-media', Storage::disk('public')->get('images/nested/sample.txt'));
        $this->assertSame('original-media', file_get_contents($backup['path'].DIRECTORY_SEPARATOR.'media/images/nested/sample.txt'));
        $this->assertSame([], glob($this->backupRoot.DIRECTORY_SEPARATOR.'.verify-*'));
        $this->artisan('fastfind:backup-verify', ['id' => $backup['id']])->assertSuccessful();
    }

    public function test_corrupt_media_fails_verification_and_cleans_disposable_files(): void
    {
        Storage::disk('public')->put('images/sample.txt', 'original-media');
        $backup = app(BackupManager::class)->create();
        file_put_contents($backup['path'].DIRECTORY_SEPARATOR.'media/images/sample.txt', 'corrupted-data');
        try {
            app(BackupManager::class)->verify($backup['id']);
            $this->fail('Corrupt backup media passed verification.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('checksum', $error->getMessage());
        }
        $this->assertSame([], glob($this->backupRoot.DIRECTORY_SEPARATOR.'.verify-*'));
    }

    public function test_manifest_traversal_and_arbitrary_backup_paths_are_rejected(): void
    {
        Storage::disk('public')->put('images/sample.txt', 'original-media');
        $backup = app(BackupManager::class)->create();
        $path = $backup['path'].DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode(file_get_contents($path), true);
        $manifest['media'][0]['file'] = '../../outside.txt';
        file_put_contents($path, json_encode($manifest));
        try {
            app(BackupManager::class)->verify($backup['id']);
            $this->fail('Traversal path was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Unsafe backup path', $error->getMessage());
        }
        $this->artisan('fastfind:backup-verify', ['id' => '../database.sqlite'])->assertFailed();
    }

    public function test_explicit_retention_keeps_newest_verified_backup_only(): void
    {
        $first = app(BackupManager::class)->create();
        $this->travel(1)->seconds();
        $second = app(BackupManager::class)->create();
        $this->assertSame(1, app(BackupManager::class)->prune(1));
        $this->assertDirectoryDoesNotExist($first['path']);
        $this->assertDirectoryExists($second['path']);
        $this->assertSame('ok', app(BackupManager::class)->verify($second['id'])['integrity']);
        $this->artisan('fastfind:backup', ['--retain' => '0'])->assertFailed();
    }

    public function test_backup_file_limits_fail_cleanly(): void
    {
        Storage::disk('public')->put('images/sample.txt', 'media');
        config(['platform.backups.max_files' => 0]);
        try {
            app(BackupManager::class)->create();
            $this->fail('File limit was ignored.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Too many media files', $error->getMessage());
        }
        $this->assertSame([], File::directories($this->backupRoot));
    }

    public function test_backup_refuses_public_storage_and_nonlocal_media(): void
    {
        config(['platform.backups.path' => config('filesystems.disks.public.root').DIRECTORY_SEPARATOR.'backups']);
        $this->artisan('fastfind:backup')->assertFailed();
        config(['platform.backups.path' => $this->backupRoot, 'filesystems.disks.public.driver' => 's3']);
        $this->artisan('fastfind:backup')->assertFailed();
    }

    public function test_health_is_owner_only_and_reports_real_scheduler_heartbeat(): void
    {
        $owner = $this->owner();
        $this->getJson('/api/v1/studio/health')->assertUnauthorized();
        $this->actingAs($owner)->getJson('/api/v1/studio/health')->assertOk()->assertJsonStructure(['version', 'status', 'checked_at', 'checks']);
        $this->artisan('fastfind:publish-scheduled')->assertSuccessful();
        $checks = $this->getJson('/api/v1/studio/health')->assertOk()->json('checks');
        $this->assertSame('ok', collect($checks)->firstWhere('id', 'scheduler')['status']);
        $this->assertSame('ok', collect($checks)->firstWhere('id', 'database')['status']);
        $this->assertSame('ok', collect($checks)->firstWhere('id', 'cache')['status']);
        $owner->update(['role' => 'admin']);
        $this->actingAs($owner->fresh())->getJson('/api/v1/studio/health')->assertForbidden();
    }
}
