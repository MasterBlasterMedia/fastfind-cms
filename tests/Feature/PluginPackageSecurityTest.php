<?php

namespace Tests\Feature;

use App\Models\PluginInstallation;
use App\Models\User;
use App\Plugins\PackageInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class PluginPackageSecurityTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;
    private string $secret;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('app/private/package-tests/'.Str::uuid());
        File::ensureDirectoryExists($this->directory);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['plugins.package_path' => $this->directory.'/installed', 'plugins.publishers' => ['test-publisher' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'long-test-password', 'role' => 'owner', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function archive(array $extra = [], ?callable $configure = null): string
    {
        $file = $this->directory.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $manifest = ['id' => 'independent/example', 'name' => 'Independent example', 'version' => '1.0.0', 'description' => 'A signed test package', 'entry' => 'plugin.php',
            'requires' => ['fastfind' => '^1.0', 'php' => '^8.3'], 'permissions' => []];
        $zip->addFromString('plugin.json', json_encode($manifest));
        $zip->addFromString('plugin.php', '<?php app()->instance("package.executed", true); return new class extends \\App\\Plugins\\AbstractPlugin { public function register(\\App\\Plugins\\PluginContext $context): void {} };');
        foreach ($extra as $name => $contents) $zip->addFromString($name, $contents);
        if ($configure) $configure($zip);
        $zip->close();
        return $file;
    }

    private function install(string $file, ?string $signature = null, ?string $hash = null, string $publisher = 'test-publisher')
    {
        return app(PackageInstaller::class)->install($file, $publisher, $signature ?? base64_encode(sodium_crypto_sign_detached(file_get_contents($file), $this->secret)), $hash ?? hash_file('sha256', $file), $this->owner->id);
    }

    public function test_verified_package_is_installed_without_executing_php_until_activation(): void
    {
        $record = $this->install($this->archive());
        $this->assertSame('inactive', $record->status);
        $this->assertFalse(app()->bound('package.executed'));
        $this->assertFileExists($record->path.'/plugin.php');
        $this->actingAs($this->owner)->postJson('/api/v1/studio/plugins/independent/example/activate')->assertOk();
        $this->assertTrue(app()->bound('package.executed'));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($path) => [$path], ['../escape.php', '/absolute.php', 'C:/drive.php', 'src/../../escape.php', 'src\\escape.php', 'CON.php', 'src/evil.php.', 'src//evil.php', '.env', 'payload.exe']);
    }

    #[DataProvider('unsafePaths')]
    public function test_signed_archive_rejects_unsafe_paths_before_loading_code(string $path): void
    {
        try { $this->install($this->archive([$path => '<?php malicious();'])); $this->fail('Unsafe archive accepted.'); }
        catch (ValidationException) { $this->assertDatabaseCount('plugin_installations', 0); $this->assertFalse(app()->bound('package.executed')); }
    }

    public function test_unknown_publisher_bad_signature_and_changed_hash_are_rejected(): void
    {
        $file = $this->archive();
        foreach ([['publisher' => 'unknown'], ['signature' => base64_encode(str_repeat('x', 64))], ['hash' => str_repeat('0', 64)]] as $overrides) {
            try { $this->install($file, ...$overrides); $this->fail('Untrusted package accepted.'); }
            catch (ValidationException) { $this->assertDatabaseCount('plugin_installations', 0); }
        }
        $this->assertFalse(app()->bound('package.executed'));
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_symlink_case_collision_file_directory_collision_and_bomb_are_rejected(): void
    {
        $archives = [
            $this->archive(['link.php' => 'plugin.php'], fn ($zip) => $zip->setExternalAttributesName('link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16)),
            $this->archive(['PLUGIN.php' => '<?php malicious();']),
            $this->archive(['src.php' => 'file', 'src.php/child.php' => 'child']),
            $this->archive(['huge.txt' => str_repeat('a', 2 * 1024 * 1024)]),
        ];
        foreach ($archives as $file) {
            try { $this->install($file); $this->fail('Unsafe archive accepted.'); }
            catch (ValidationException) { $this->assertDatabaseCount('plugin_installations', 0); }
        }
        $this->assertFalse(app()->bound('package.executed'));
    }

    public function test_upload_requires_owner_and_csrf_web_middleware_is_on_route(): void
    {
        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'long-test-password', 'role' => 'editor', 'is_active' => true]);
        $this->actingAs($editor)->postJson('/api/v1/studio/plugins/packages', [])->assertForbidden();
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/v1/studio/plugins/packages');
        $this->assertContains('web', $route->gatherMiddleware());
    }
}
