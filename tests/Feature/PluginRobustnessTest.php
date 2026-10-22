<?php

namespace Tests\Feature;

use App\Models\PluginInstallation;
use App\Models\User;
use App\Plugins\ExtensionRegistry;
use App\Plugins\Manifest;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PluginRobustnessTest extends TestCase
{
    use RefreshDatabase;
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) File::deleteDirectory($directory);
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'email' => Str::uuid().'@example.test', 'password' => 'long-test-password', 'role' => 'owner', 'is_active' => true]);
    }

    private function fixture(string $id, string $register, array $dependencies = [], ?string $entry = null): array
    {
        $path = storage_path('app/private/robustness-tests/'.Str::uuid());
        File::ensureDirectoryExists($path);
        $this->directories[] = $path;
        $manifest = ['id' => $id, 'name' => 'Independent robustness fixture', 'description' => 'Public SDK test fixture', 'version' => '1.0.0', 'entry' => 'plugin.php',
            'requires' => ['fastfind' => '^1.0', 'php' => '^8.3'], 'permissions' => ['pages.read', 'pages.write', 'content.extend'], 'dependencies' => $dependencies];
        file_put_contents($path.'/plugin.json', json_encode($manifest));
        file_put_contents($path.'/plugin.php', $entry ?? '<?php return new class extends \\App\\Plugins\\AbstractPlugin { public function register(\\App\\Plugins\\PluginContext $context): void { '.$register.' } };');
        return [$path, app(Manifest::class)->read($path.'/plugin.json')];
    }

    public function test_dependencies_boot_before_consumers_regardless_of_installation_or_id_order(): void
    {
        $actor = $this->owner();
        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        $providerId = 'qa/zz-provider-'.$suffix;
        $consumerId = 'qa/aa-consumer-'.$suffix;
        [$consumer, $consumerManifest] = $this->fixture($consumerId, 'if (!app()->bound("fixture.provider")) throw new \\RuntimeException("Provider was not booted first"); app()->instance("fixture.consumer", true);', [$providerId => '^1.0']);
        [$provider, $providerManifest] = $this->fixture($providerId, 'app()->instance("fixture.provider", true);');
        $manager = app(PluginManager::class);
        $manager->installVerified($consumer, $consumerManifest, 'bundled', $actor->id);
        $manager->installVerified($provider, $providerManifest, 'bundled', $actor->id);
        PluginInstallation::query()->update(['status' => 'active']);
        $manager->bootActive();
        $this->assertTrue(app()->bound('fixture.provider'));
        $this->assertTrue(app()->bound('fixture.consumer'));
        $this->assertDatabaseHas('plugin_installations', ['id' => $consumerId, 'status' => 'active']);
    }

    public function test_failed_dependency_disables_consumers_before_their_registration_runs(): void
    {
        $actor = $this->owner();
        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        $providerId = 'qa/zz-broken-'.$suffix;
        $consumerId = 'qa/aa-dependent-'.$suffix;
        [$consumer, $consumerManifest] = $this->fixture($consumerId, 'app()->instance("fixture.consumer.executed", true);', [$providerId => '^1.0']);
        [$provider, $providerManifest] = $this->fixture($providerId, '$context->pageFields("Partial", [], fn ($values) => $values); throw new \\RuntimeException("Cannot start");');
        $manager = app(PluginManager::class);
        $manager->installVerified($consumer, $consumerManifest, 'bundled', $actor->id);
        $manager->installVerified($provider, $providerManifest, 'bundled', $actor->id);
        PluginInstallation::query()->update(['status' => 'active']);
        $manager->bootActive();
        $this->assertFalse(app()->bound('fixture.consumer.executed'));
        $this->assertDatabaseHas('plugin_installations', ['id' => $providerId, 'status' => 'error']);
        $this->assertDatabaseHas('plugin_installations', ['id' => $consumerId, 'status' => 'error']);
        $this->assertSame([], app(ExtensionRegistry::class)->descriptors()['page_fields']);
    }

    public function test_cycle_in_persisted_dependency_graph_is_disabled_without_loading_entries(): void
    {
        $actor = $this->owner();
        $suffix = substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        $firstId = 'qa/cycle-first-'.$suffix;
        $secondId = 'qa/cycle-second-'.$suffix;
        $manager = app(PluginManager::class);
        foreach ([[$firstId, $secondId], [$secondId, $firstId]] as [$id, $dependency]) {
            [$path, $manifest] = $this->fixture($id, 'app()->instance("fixture.cycle.executed", true);', [$dependency => '^1.0']);
            $manager->installVerified($path, $manifest, 'bundled', $actor->id);
        }
        PluginInstallation::query()->update(['status' => 'active']);
        $manager->bootActive();
        $this->assertFalse(app()->bound('fixture.cycle.executed'));
        $this->assertDatabaseCount('plugin_installations', 2);
        $this->assertSame(2, PluginInstallation::where('status', 'error')->count());
    }

    public function test_named_entry_is_loaded_only_once_during_same_request_lifecycle(): void
    {
        $actor = $this->owner();
        $suffix = str_replace('-', '', (string) Str::uuid());
        $id = 'qa/named-'.substr($suffix, 0, 8);
        $class = 'IndependentEntry'.$suffix;
        [$path, $manifest] = $this->fixture($id, '', [], '<?php class '.$class.' extends \\App\\Plugins\\AbstractPlugin { public function register(\\App\\Plugins\\PluginContext $context): void {} } return new '.$class.';');
        $manager = app(PluginManager::class);
        $manager->installVerified($path, $manifest, 'bundled', $actor->id);
        $manager->activate($id, $actor->id);
        $manager->deactivate($id, $actor->id);
        $this->assertSame('active', $manager->activate($id, $actor->id)->status);
        $this->assertTrue(class_exists($class, false));
    }

    public function test_changed_php_release_requires_a_fresh_process_before_execution(): void
    {
        $actor = $this->owner();
        $id = 'qa/upgrade-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        [$path, $manifest] = $this->fixture($id, '');
        $manager = app(PluginManager::class);
        $manager->installVerified($path, $manifest, 'bundled', $actor->id);
        $manager->activate($id, $actor->id);
        $manager->deactivate($id, $actor->id);
        [$nextPath, $nextManifest] = $this->fixture($id, '', [], '<?php app()->instance("fixture.replacement.executed", true); return new class extends \\App\\Plugins\\AbstractPlugin { public function register(\\App\\Plugins\\PluginContext $context): void {} };');
        $nextManifest['version'] = '1.1.0';
        file_put_contents($nextPath.'/plugin.json', json_encode($nextManifest));
        try {
            $manager->upgradeVerified($nextPath, $nextManifest, $actor->id);
            $this->fail('Changed code loaded into the same process.');
        } catch (ValidationException) {
            $this->assertFalse(app()->bound('fixture.replacement.executed'));
            $this->assertDatabaseHas('plugin_installations', ['id' => $id, 'installed_version' => '1.0.0', 'path' => $path]);
        }
    }

    public function test_upgrade_checks_new_dependencies_before_its_hook(): void
    {
        $actor = $this->owner();
        $id = 'qa/needs-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        [$path, $manifest] = $this->fixture($id, '');
        $manager = app(PluginManager::class);
        $manager->installVerified($path, $manifest, 'bundled', $actor->id);
        $manifest['version'] = '1.1.0';
        $manifest['dependencies'] = ['missing/provider' => '^2.0'];
        file_put_contents($path.'/plugin.json', json_encode($manifest));
        $this->expectException(ValidationException::class);
        $manager->upgradeVerified($path, $manifest, $actor->id);
    }

    public function test_merged_and_validated_extension_output_respects_platform_bounds(): void
    {
        $extensions = app(ExtensionRegistry::class);
        $extensions->pageFields('qa/new', 'New', [], fn ($values) => $values);
        try {
            $extensions->validatePageData(['qa/new' => ['text' => str_repeat('b', 32000)]], ['qa/inactive' => ['text' => str_repeat('a', 40000)]]);
            $this->fail('Merged size limit was bypassed.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('too large', $error->getMessage());
        }
        $extensions->pageFields('qa/bad', 'Bad', [], fn () => 'not an array');
        $this->expectException(ValidationException::class);
        $extensions->validatePageData(['qa/bad' => []]);
    }
}
