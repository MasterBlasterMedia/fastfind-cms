<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\PluginInstallation;
use App\Models\User;
use App\Plugins\ExtensionRegistry;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PluginLifecycleTest extends TestCase
{
    use RefreshDatabase;
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) File::deleteDirectory($directory);
        parent::tearDown();
    }

    private function user(string $role = 'owner'): User
    {
        return User::create(['name' => 'Plugin tester', 'email' => Str::uuid().'@example.test', 'password' => 'test-password-long', 'role' => $role, 'is_active' => true]);
    }

    private function activateSeo(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/install')->assertOk()->assertJsonPath('plugin.status', 'inactive');
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/activate')->assertOk()->assertJsonPath('plugin.status', 'active');
    }

    public function test_owner_can_install_activate_deactivate_and_uninstall_seo(): void
    {
        $this->activateSeo();
        $this->getJson('/api/v1/studio/extensions')->assertJsonPath('page_fields.0.plugin_id', 'fastfind/seo');
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->putJson('/api/v1/studio/plugins/fastfind/seo/settings', ['settings' => ['title_suffix' => ' | Test site', 'default_robots' => 'index,follow', 'sitemap_enabled' => true]])->assertOk();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/deactivate')->assertOk();
        $this->getJson('/api/v1/studio/extensions')->assertJsonPath('page_fields', []);
        $this->getJson('/api/v1/plugins/fastfind/seo/redirects')->assertNotFound();
        $this->getJson('/api/v1/home')->assertOk();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/uninstall')->assertOk();
        $this->assertDatabaseMissing('plugin_installations', ['id' => 'fastfind/seo']);
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/install')->assertOk();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/activate')->assertOk();
        $this->getJson('/api/v1/studio/plugins/fastfind/seo/settings')->assertJsonPath('settings.title_suffix', '| Test site');
    }

    public function test_admin_editor_viewer_and_guest_cannot_manage_installations(): void
    {
        $this->getJson('/api/v1/studio/plugins')->assertUnauthorized();
        foreach (['admin', 'editor', 'viewer'] as $role) {
            $this->actingAs($this->user($role));
            $this->postJson('/api/v1/studio/plugins/fastfind/seo/install')->assertForbidden();
            $this->getJson('/api/v1/studio/plugins')->assertForbidden();
        }
        $this->assertDatabaseCount('plugin_installations', 0);
    }

    public function test_metadata_is_snapshotted_validated_and_survives_plugin_disable(): void
    {
        $this->activateSeo();
        $page = $this->postJson('/api/v1/studio/pages', ['title' => 'SEO article'])->assertCreated()->json('page');
        $data = ['version' => $page['version'], 'title' => $page['title'], 'slug' => $page['slug'], 'summary' => 'Public summary', 'show_in_search' => true,
            'document' => ['sections' => []], 'extensions' => ['fastfind/seo' => ['title' => 'Published search title', 'canonical' => 'https://example.test/p/article', 'structured_type' => 'Article', 'structured_author' => '</script><script>alert(1)</script>']]];
        $saved = $this->putJson('/api/v1/studio/pages/'.$page['id'], $data)->assertOk()->json('page');
        $published = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $saved['version']])->assertOk()->json('page');
        $this->withoutVite();
        $this->get('/p/seo-article')->assertOk()->assertSee('Published search title')->assertSee('rel="canonical"', false)->assertDontSee('</script><script>alert(1)</script>', false);
        $data['version'] = $published['version'];
        $data['extensions']['fastfind/seo']['title'] = 'Private draft title';
        $changed = $this->putJson('/api/v1/studio/pages/'.$page['id'], $data)->assertOk()->json('page');
        $this->get('/p/seo-article')->assertSee('Published search title')->assertDontSee('Private draft title');
        $data['version'] = $changed['version']; $data['extensions']['fastfind/seo']['canonical'] = 'javascript:alert(1)';
        $this->putJson('/api/v1/studio/pages/'.$page['id'], $data)->assertUnprocessable();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/deactivate')->assertOk();
        $this->get('/p/seo-article')->assertOk()->assertDontSee('rel="canonical"', false);
        $this->assertSame('Private draft title', Page::find($page['id'])->extensions['fastfind/seo']['title']);
        $data['extensions'] = ['fastfind/seo' => ['title' => 'Forged inactive change']];
        $this->putJson('/api/v1/studio/pages/'.$page['id'], $data)->assertUnprocessable();
    }

    public function test_sitemap_excludes_drafts_and_noindex_and_redirects_reject_cycles(): void
    {
        $this->activateSeo();
        Page::create(['title' => 'Private', 'slug' => 'private', 'document' => ['sections' => []]]);
        foreach (['visible' => 'index,follow', 'hidden' => 'noindex,follow'] as $slug => $robots) {
            Page::create(['title' => $slug, 'slug' => $slug, 'published_slug' => $slug, 'status' => 'published', 'published_at' => now(), 'document' => ['sections' => []],
                'published_snapshot' => ['title' => $slug, 'slug' => $slug, 'extensions' => ['fastfind/seo' => ['robots' => $robots]]]]);
        }
        $this->get('/sitemap-pages.xml')->assertOk()->assertSee('/p/visible')->assertDontSee('/p/private')->assertDontSee('/p/hidden');
        $this->putJson('/api/v1/plugins/fastfind/seo/redirects', ['redirects' => [['source' => '/old', 'destination' => '/new', 'status' => 301]]])->assertOk();
        $this->get('/old')->assertRedirect('/new')->assertStatus(301);
        foreach ([
            [['source' => '/a', 'destination' => '/b', 'status' => 301], ['source' => '/b', 'destination' => '/a', 'status' => 301]],
            [['source' => '/studio', 'destination' => '/new', 'status' => 301]],
            [['source' => '/old', 'destination' => '/\\evil.test', 'status' => 301]],
        ] as $redirects) $this->putJson('/api/v1/plugins/fastfind/seo/redirects', compact('redirects'))->assertUnprocessable();
        $this->actingAs($this->user('editor'));
        $this->getJson('/api/v1/plugins/fastfind/seo/redirects')->assertForbidden();
    }

    public function test_unrelated_plugin_uses_same_fields_routes_settings_and_lifecycle_contract(): void
    {
        $path = $this->fixture();
        config(['plugins.bundled_paths' => [$path]]);
        $this->actingAs($this->user());
        $base = '/api/v1/studio/plugins/example/notes';
        $this->postJson($base.'/install')->assertOk();
        $this->assertFalse(app()->bound('fixture.executed'));
        $this->postJson($base.'/activate')->assertOk();
        $this->assertTrue(app()->bound('fixture.executed'));
        $this->getJson('/api/v1/plugins/example/notes/ping')->assertOk()->assertJsonPath('plugin', 'example/notes');
        $this->assertSame(['example/notes' => ['note' => 'test']], app(ExtensionRegistry::class)->validatePageData(['example/notes' => ['note' => 'test']]));
        $this->putJson($base.'/settings', ['settings' => ['label' => 'Example']])->assertOk();
        $this->postJson($base.'/deactivate')->assertOk();
        $manifest = json_decode(file_get_contents($path.'/plugin.json'), true);
        $manifest['version'] = '1.1.0'; file_put_contents($path.'/plugin.json', json_encode($manifest));
        $this->postJson($base.'/upgrade')->assertOk()->assertJsonPath('plugin.installed_version', '1.1.0');
        $this->assertTrue(app()->bound('fixture.upgraded'));
        $this->postJson($base.'/uninstall', ['purge' => true])->assertOk();
        $this->assertTrue(app()->bound('fixture.purged'));
    }

    public function test_tampered_and_incompatible_plugins_cannot_activate(): void
    {
        $path = $this->fixture(); config(['plugins.bundled_paths' => [$path]]);
        $this->actingAs($this->user());
        $this->postJson('/api/v1/studio/plugins/example/notes/install')->assertOk();
        file_put_contents($path.'/plugin.php', "\n// modified", FILE_APPEND);
        $this->postJson('/api/v1/studio/plugins/example/notes/activate')->assertUnprocessable();
        $this->assertFalse(app()->bound('fixture.executed'));
        $this->assertDatabaseHas('plugin_installations', ['id' => 'example/notes', 'status' => 'error']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_seo_upgrade_preserves_settings_and_migrations_and_reactivation(): void
    {
        $path = storage_path('app/private/test-plugins/'.Str::uuid());
        File::copyDirectory(base_path('plugins/fastfind-seo'), $path); $this->directories[] = $path;
        config(['plugins.bundled_paths' => [$path]]);
        $this->activateSeo();
        $this->putJson('/api/v1/plugins/fastfind/seo/redirects', ['redirects' => [['source' => '/before', 'destination' => '/after', 'status' => 302]]])->assertOk();
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/deactivate')->assertOk();
        $manifest = json_decode(file_get_contents($path.'/plugin.json'), true);
        $manifest['version'] = '1.0.1'; file_put_contents($path.'/plugin.json', json_encode($manifest));
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/upgrade')->assertOk()->assertJsonPath('plugin.installed_version', '1.0.1');
        $this->postJson('/api/v1/studio/plugins/fastfind/seo/activate')->assertOk();
        $this->get('/before')->assertRedirect('/after')->assertStatus(302);
        $this->get('/sitemap.xml')->assertOk();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_10_20_100001_create_fastfind_seo_redirects')->count());
    }

    public function test_incompatible_requirements_and_unmet_dependencies_are_rejected(): void
    {
        $path = $this->fixture(); config(['plugins.bundled_paths' => [$path]]);
        $this->actingAs($this->user());
        $manifest = json_decode(file_get_contents($path.'/plugin.json'), true);
        $manifest['requires']['fastfind'] = '^99.0'; file_put_contents($path.'/plugin.json', json_encode($manifest));
        $this->postJson('/api/v1/studio/plugins/example/notes/install')->assertUnprocessable();
        $manifest['requires']['fastfind'] = '^1.0'; $manifest['dependencies'] = ['missing/dependency' => '^1.0'];
        file_put_contents($path.'/plugin.json', json_encode($manifest));
        $this->postJson('/api/v1/studio/plugins/example/notes/install')->assertOk();
        $this->postJson('/api/v1/studio/plugins/example/notes/activate')->assertUnprocessable();
        $this->assertFalse(app()->bound('fixture.executed'));
    }

    private function fixture(): string
    {
        $path = storage_path('app/private/test-plugins/'.Str::uuid());
        File::ensureDirectoryExists($path); $this->directories[] = $path;
        file_put_contents($path.'/plugin.json', json_encode(['id' => 'example/notes', 'name' => 'Independent Notes', 'description' => 'Independent test plugin', 'version' => '1.0.0', 'entry' => 'plugin.php',
            'requires' => ['fastfind' => '^1.0', 'php' => '^8.3'], 'permissions' => ['pages.read', 'pages.write', 'settings.manage']]));
        file_put_contents($path.'/plugin.php', <<<'PHP'
<?php
app()->instance('fixture.executed', true);
return new class extends \App\Plugins\AbstractPlugin {
    public function register(\App\Plugins\PluginContext $context): void {
        $context->pageFields('Notes', [['key' => 'note', 'label' => 'Note', 'type' => 'text']], fn ($data) => \Illuminate\Support\Facades\Validator::make($data, ['note' => 'required|string|max:100'])->validate());
        $context->settingsFields([['key' => 'label', 'type' => 'text']], fn ($data) => \Illuminate\Support\Facades\Validator::make($data, ['label' => 'required|string'])->validate());
        $context->route('GET', 'ping', 'pages.read', fn () => response()->json(['plugin' => $context->id]));
    }
    public function upgrade(\App\Plugins\PluginContext $context, string $fromVersion): void { app()->instance('fixture.upgraded', $fromVersion === '1.0.0'); }
    public function uninstall(\App\Plugins\PluginContext $context, bool $purge): void { app()->instance('fixture.purged', $purge); }
};
PHP);
        return $path;
    }
}
