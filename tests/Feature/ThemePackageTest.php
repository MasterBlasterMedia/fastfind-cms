<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\ThemeManager;
use App\Themes\ThemeManifest;
use App\Themes\ThemePackageInstaller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ThemePackageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private string $secret;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->directory = storage_path('framework/testing/theme-packages-'.Str::uuid());
        File::ensureDirectoryExists($this->directory);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['themes.package_path' => $this->directory.'/installed', 'themes.output_path' => $this->directory.'/output',
            'plugins.publishers' => ['test-publisher' => base64_encode(sodium_crypto_sign_publickey($pair))]]);
        $this->owner = $this->user();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function user(string $role = 'owner', bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => Str::uuid().'@example.test', 'password' => 'long-test-password', 'role' => $role, 'is_active' => $active]);
    }

    private function manifest(array $overrides = []): array
    {
        return array_replace(json_decode(file_get_contents(base_path('examples/themes/aurora/theme.json')), true, 16, JSON_THROW_ON_ERROR), $overrides);
    }

    private function archive(array $overrides = [], array $extra = [], ?callable $configure = null): string
    {
        $path = $this->directory.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        $zip->addFromString('theme.json', json_encode($this->manifest($overrides), JSON_THROW_ON_ERROR));
        foreach ($extra as $name => $bytes) $zip->addFromString($name, $bytes);
        if ($configure) $configure($zip);
        $this->assertTrue($zip->close());
        return $path;
    }

    private function install(string $file, ?string $signature = null, ?string $hash = null, string $publisher = 'test-publisher', ?int $actor = null): array
    {
        return app(ThemePackageInstaller::class)->install($file, $publisher,
            $signature ?? base64_encode(sodium_crypto_sign_detached(file_get_contents($file), $this->secret)),
            $hash ?? hash_file('sha256', $file), $actor ?? $this->owner->id);
    }

    private function assertRejected(callable $action): void
    {
        try { $action(); $this->fail('Expected the theme package to be rejected.'); }
        catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
    }

    public function test_signed_theme_uses_public_manifest_contract_and_installs_without_activation(): void
    {
        $theme = $this->install($this->archive([], ['README.md' => 'A third-party theme.']));
        $this->assertSame('aurora', $theme['id']);
        $this->assertSame('package', $theme['source']);
        $this->assertArrayNotHasKey('path', $theme);
        $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
        $this->assertCount(3, app(ThemeManager::class)->all());
        foreach (app(ThemeManager::class)->bundled() as $bundled) {
            $manifest = collect($bundled)->except(['source', 'publisher', 'installed'])->all();
            $this->assertSame($manifest, app(ThemeManifest::class)->validate($manifest));
        }
        $record = SiteSetting::findOrFail('theme_packages')->value['aurora'];
        $this->assertFileExists($record['path'].'/theme.json');
        $this->assertFileExists($record['path'].'/README.md');
        $this->assertDatabaseHas('audit_events', ['action' => 'theme.installed']);
    }

    public function test_activation_preserves_content_and_legacy_customization(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/studio/pages', ['title' => 'Unaffected content'])->assertCreated();
        $before = Page::firstOrFail()->toArray();
        SiteSetting::updateOrCreate(['key' => 'theme'], ['value' => ['id' => 'minimalist', 'settings' => ['accent' => '#123456']]]);
        $current = app(ThemeManager::class)->current();
        $this->assertSame('#123456', $current['settings']['accent']);
        $this->assertSame('square', $current['settings']['radius']);
        $this->install($this->archive());
        $this->flushSession();
        $this->actingAs($this->user('admin'))->putJson('/api/v1/studio/themes', ['id' => 'aurora', 'settings' => ['accent' => '#654321']])
            ->assertOk()->assertJsonPath('active', 'aurora')->assertJsonPath('settings.accent', '#654321')->assertJsonPath('theme.layout', 'editorial');
        $this->assertSame($before, Page::firstOrFail()->toArray());
        $this->assertDatabaseHas('audit_events', ['action' => 'theme.activated']);
    }

    public function test_active_updates_are_rejected_and_inactive_updates_preserve_previous_release(): void
    {
        $this->install($this->archive());
        $before = SiteSetting::findOrFail('theme_packages')->value['aurora'];
        app(ThemeManager::class)->activate('aurora', [], $this->owner->id);
        $this->assertRejected(fn () => $this->install($this->archive(['version' => '1.1.0'])));
        $this->assertSame('1.0.0', app(ThemeManager::class)->current()['theme']['version']);
        $this->assertSame($before, SiteSetting::findOrFail('theme_packages')->value['aurora']);
        app(ThemeManager::class)->activate('minimalist', [], $this->owner->id);
        $updated = $this->install($this->archive(['version' => '1.1.0']));
        $this->assertSame('1.1.0', $updated['version']);
        $this->assertSame('minimalist', app(ThemeManager::class)->current()['active']);
        $this->assertFileExists($before['path'].'/theme.json');
        $this->assertNotSame($before['path'], SiteSetting::findOrFail('theme_packages')->value['aurora']['path']);
        $this->assertRejected(fn () => $this->install($this->archive()));
        $this->assertDatabaseHas('audit_events', ['action' => 'theme.upgraded']);
    }

    public function test_untrusted_publisher_invalid_signature_and_wrong_digest_never_install(): void
    {
        $file = $this->archive();
        $this->assertRejected(fn () => $this->install($file, publisher: 'unknown-publisher'));
        $this->assertRejected(fn () => $this->install($file, signature: base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES))));
        $this->assertRejected(fn () => $this->install($file, hash: str_repeat('0', 64)));
        $this->assertNull(SiteSetting::find('theme_packages'));
        $this->assertSame(3, AuditEvent::where('action', 'theme.package_rejected')->count());
    }

    public function test_packages_cannot_replace_bundled_ids_or_another_publishers_theme(): void
    {
        $this->assertRejected(fn () => $this->install($this->archive(['id' => 'professional'])));
        $this->install($this->archive());
        config(['plugins.publishers.another-publisher' => config('plugins.publishers.test-publisher')]);
        $this->assertRejected(fn () => $this->install($this->archive(['version' => '2.0.0']), publisher: 'another-publisher'));
        $this->assertSame('test-publisher', SiteSetting::findOrFail('theme_packages')->value['aurora']['publisher']);
    }

    public function test_installation_cannot_silently_reactivate_an_unavailable_selection(): void
    {
        SiteSetting::create(['key' => 'theme', 'value' => ['id' => 'aurora', 'settings' => []]]);
        $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
        $this->assertRejected(fn () => $this->install($this->archive()));
        $this->assertNull(SiteSetting::find('theme_packages'));
        $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
    }

    public static function malformedManifests(): array
    {
        return [
            'unsafe id' => [['id' => '../outside']],
            'executable layout' => [['layout' => 'uploaded-script']],
            'invalid version' => [['version' => 'not-semver']],
            'incompatible version' => [['requires' => ['fastfind' => '^99']]],
            'invalid constraint type' => [['requires' => ['fastfind' => []]]],
            'unsafe accent' => [['defaults' => ['accent' => 'url(https://example.test)']]],
            'unsafe background' => [['defaults' => ['background' => '<style>']]],
            'unknown font' => [['defaults' => ['font' => 'remote-font']]],
            'non-string enum' => [['defaults' => ['radius' => true]]],
            'unknown setting' => [['defaults' => ['script' => true]]],
            'executable entry point' => [['entry' => 'theme.php']],
        ];
    }

    #[DataProvider('malformedManifests')]
    public function test_malformed_manifests_leave_active_theme_unchanged(array $changes): void
    {
        $this->assertRejected(fn () => $this->install($this->archive($changes)));
        $this->assertNull(SiteSetting::find('theme_packages'));
        $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
    }

    public static function unsafeFiles(): array
    {
        return array_map(fn ($path) => [$path], ['../theme.json', '/theme.json', 'C:/theme.json', 'theme\\file.json', 'THEME.json', 'theme.php', 'style.css', 'theme.js', 'assets/picture.svg', 'CON.txt']);
    }

    #[DataProvider('unsafeFiles')]
    public function test_unsafe_or_unrecognized_archive_entries_are_rejected(string $name): void
    {
        $this->assertRejected(fn () => $this->install($this->archive([], [$name => 'Untrusted bytes'])));
        $this->assertNull(SiteSetting::find('theme_packages'));
    }

    public function test_symlinks_encryption_oversized_entries_and_invalid_json_are_rejected(): void
    {
        $files = [
            $this->archive([], ['README.md' => 'outside'], fn ($zip) => $zip->setExternalAttributesName('README.md', ZipArchive::OPSYS_UNIX, 0120777 << 16)),
            $this->archive(configure: fn ($zip) => $zip->setEncryptionName('theme.json', ZipArchive::EM_AES_256, 'secret')),
            $this->archive([], ['README.md' => str_repeat('x', 65537)]),
            $this->archive(configure: fn ($zip) => $zip->addFromString('theme.json', '{broken json')),
        ];
        foreach ($files as $file) $this->assertRejected(fn () => $this->install($file));
        $this->assertNull(SiteSetting::find('theme_packages'));
    }

    public function test_missing_manifest_nonzip_and_archive_size_limit_are_rejected(): void
    {
        $missing = $this->archive([], ['README.md' => 'No manifest'], fn ($zip) => $zip->deleteName('theme.json'));
        $this->assertRejected(fn () => $this->install($missing));
        $invalid = $this->directory.'/not-a-zip.zip';
        file_put_contents($invalid, 'Not a ZIP archive');
        $this->assertRejected(fn () => $this->install($invalid));
        $file = $this->archive();
        config(['themes.max_archive_bytes' => filesize($file) - 1]);
        $this->assertRejected(fn () => $this->install($file));
        $this->assertNull(SiteSetting::find('theme_packages'));
    }

    public function test_service_authorization_rejects_nonowners_and_inactive_accounts(): void
    {
        $file = $this->archive();
        foreach ([$this->user('admin'), $this->user('editor'), $this->user('viewer'), $this->user('owner', false)] as $user) {
            try { $this->install($file, actor: $user->id); $this->fail('Unauthorized installation succeeded.'); }
            catch (AuthorizationException) { $this->assertNull(SiteSetting::find('theme_packages')); }
        }
        $this->install($file);
        $this->expectException(AuthorizationException::class);
        app(ThemeManager::class)->activate('aurora', [], $this->user('editor')->id);
    }

    public function test_upload_route_requires_owner_validates_fields_and_installs_signed_zip(): void
    {
        $url = '/api/v1/studio/themes/packages';
        $this->postJson($url)->assertUnauthorized();
        foreach ([$this->user('admin'), $this->user('editor'), $this->user('viewer'), $this->user('owner', false)] as $user) {
            $this->flushSession();
            $this->actingAs($user)->postJson($url)->assertForbidden();
        }
        $this->flushSession();
        $this->actingAs($this->owner)->postJson($url)->assertUnprocessable()->assertJsonValidationErrors(['file', 'publisher', 'signature', 'sha256']);
        $file = $this->archive();
        $input = ['file' => new UploadedFile($file, 'aurora.zip', 'application/zip', null, true), 'publisher' => 'test-publisher',
            'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($file), $this->secret)), 'sha256' => hash_file('sha256', $file)];
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('theme.id', 'aurora')->assertJsonPath('theme.installed', true);
        $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
    }

    public function test_invalid_stored_selection_and_unavailable_theme_fall_back_safely(): void
    {
        foreach (['broken', ['id' => []], ['id' => 'missing', 'settings' => ['accent' => 'bad']], ['id' => 'professional', 'settings' => ['accent' => 'bad']]] as $selection) {
            SiteSetting::updateOrCreate(['key' => 'theme'], ['value' => $selection]);
            $this->assertSame('professional', app(ThemeManager::class)->current()['active']);
            $this->assertSame(ThemeManifest::DEFAULTS['accent'], app(ThemeManager::class)->current()['settings']['accent']);
        }
        SiteSetting::updateOrCreate(['key' => 'theme_packages'], ['value' => ['broken' => [], 'aurora' => ['manifest' => $this->manifest(['defaults' => ['accent' => 'bad']]), 'publisher' => 'test-publisher']]]);
        $this->assertCount(2, app(ThemeManager::class)->all());
        config(['themes.bundled_path' => $this->directory.'/missing-bundled-themes']);
        $current = app(ThemeManager::class)->current();
        $this->assertSame('editorial', $current['theme']['layout']);
        $this->assertEquals(ThemeManifest::DEFAULTS, $current['settings']);
    }

    public function test_packaging_command_emits_installable_signed_zip_without_private_key(): void
    {
        $keyPath = $this->directory.'/private.key';
        file_put_contents($keyPath, base64_encode($this->secret));
        $this->artisan('fastfind:theme-package', ['directory' => base_path('examples/themes/aurora'), '--key' => $keyPath, '--publisher' => 'test-publisher'])->assertSuccessful();
        $files = glob($this->directory.'/output/*.zip');
        $this->assertCount(1, $files);
        $metadata = json_decode(file_get_contents($files[0].'.json'), true, 16, JSON_THROW_ON_ERROR);
        $theme = $this->install($files[0], $metadata['signature'], $metadata['sha256'], $metadata['publisher']);
        $this->assertSame('aurora', $theme['id']);
        $zip = new ZipArchive;
        $zip->open($files[0]);
        $this->assertFalse($zip->locateName('private.key'));
        $zip->close();
    }
}
