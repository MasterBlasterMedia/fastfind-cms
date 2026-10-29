<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\ContentRegistry;
use App\Services\DocumentSchema;
use App\Services\PageWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformContentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner', bool $active = true): User
    {
        return User::create(['name' => ucfirst($role), 'email' => uniqid($role).'@example.test', 'password' => 'test-password-long', 'role' => $role, 'is_active' => $active]);
    }

    private function createPage(string $title = 'Platform page'): array
    {
        return $this->postJson('/api/v1/studio/pages', ['title' => $title])->assertCreated()->json('page');
    }

    private function savePage(array $page, array $changes = []): array
    {
        $data = array_replace(['title' => $page['title'], 'slug' => $page['slug'], 'version' => $page['version'], 'summary' => $page['summary'] ?? '', 'show_in_search' => true, 'document' => $page['document']], $changes);
        return $this->putJson('/api/v1/studio/pages/'.$page['id'], $data)->assertOk()->json('page');
    }

    public static function roles(): array
    {
        return [['owner', true, 201], ['admin', true, 201], ['editor', true, 201], ['viewer', true, 403], ['owner', false, 403]];
    }

    public function test_new_editor_payload_preserves_legacy_metadata_until_explicitly_cleared(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $page = $this->savePage($page, ['seo_title' => 'Existing title', 'seo_description' => 'Existing description']);
        $page = $this->savePage($page, ['summary' => 'New editor changes']);
        $this->assertSame('Existing title', $page['seo_title']);
        $this->assertSame('Existing description', $page['seo_description']);
        $page = $this->savePage($page, ['seo_title' => null, 'seo_description' => null]);
        $this->assertNull($page['seo_title']);
        $this->assertNull($page['seo_description']);
    }

    #[DataProvider('roles')]
    public function test_content_creation_permissions(string $role, bool $active, int $status): void
    {
        $this->actingAs($this->user($role, $active))->postJson('/api/v1/studio/pages', ['title' => 'Permission test'])->assertStatus($status);
    }

    public function test_initial_version_revision_and_stale_deletion_are_consistent(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $this->assertSame(1, $page['version']);
        $this->assertDatabaseHas('page_revisions', ['page_id' => $page['id'], 'version' => 1]);
        $updated = $this->savePage($page, ['summary' => 'Newer changes']);
        $this->deleteJson('/api/v1/studio/pages/'.$page['id'], ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseHas('pages', ['id' => $page['id'], 'summary' => 'Newer changes']);
        $this->deleteJson('/api/v1/studio/pages/'.$page['id'], ['version' => $updated['version']])->assertOk();
        $this->assertDatabaseMissing('pages', ['id' => $page['id']]);
    }

    public function test_restore_conflict_returns_validation_and_preserves_current_data(): void
    {
        $this->actingAs($this->user());
        $first = $this->createPage('Original');
        $moved = $this->savePage($first, ['slug' => 'renamed']);
        $this->createPage('Original');
        $this->postJson('/api/v1/studio/pages/'.$first['id'].'/restore', ['version' => $moved['version'], 'revision_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->assertDatabaseHas('pages', ['id' => $first['id'], 'slug' => 'renamed', 'version' => $moved['version']]);
    }

    public function test_restoration_is_a_new_draft_revision_and_does_not_change_publication(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $page = $this->savePage($page, ['summary' => 'Published summary']);
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk()->json('page');
        $restored = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/restore', ['version' => $page['version'], 'revision_version' => 1])->assertOk()->json('page');
        $this->assertSame($page['version'] + 1, $restored['version']);
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertOk()->assertJsonPath('page.summary', 'Published summary');
        $this->getJson('/api/v1/studio/pages/'.$page['id'].'/revisions/2')->assertOk()->assertJsonFragment(['summary' => 'Published summary']);
    }

    public function test_scheduled_publication_uses_normal_snapshot_and_permission_checks(): void
    {
        $this->actingAs($this->user('editor'));
        $page = $this->createPage();
        $this->postJson('/api/v1/studio/pages/'.$page['id'].'/schedule', ['version' => $page['version'], 'scheduled_at' => now()->addHour()->toIso8601String()])->assertOk()->assertJsonPath('page.status', 'scheduled');
        $this->artisan('fastfind:publish-scheduled')->assertSuccessful();
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertNotFound();
        $this->travel(61)->minutes();
        $this->artisan('fastfind:publish-scheduled')->assertSuccessful();
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertOk();
        $this->assertDatabaseHas('pages', ['id' => $page['id'], 'scheduled_at' => null]);
    }

    public function test_deactivated_author_cannot_publish_through_scheduler(): void
    {
        $author = $this->user('editor');
        $this->actingAs($author);
        $page = $this->createPage();
        $this->postJson('/api/v1/studio/pages/'.$page['id'].'/schedule', ['version' => 1, 'scheduled_at' => now()->addMinute()->toIso8601String()])->assertOk();
        $author->update(['is_active' => false]);
        $this->travel(2)->minutes();
        $this->artisan('fastfind:publish-scheduled')->assertFailed();
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertNotFound();
    }

    public function test_review_archive_and_unpublish_keep_public_content_private(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/review', ['version' => 1])->assertOk()->assertJsonPath('page.status', 'in_review')->json('page');
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertNotFound();
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk()->json('page');
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/archive', ['version' => $page['version']])->assertOk()->assertJsonPath('page.status', 'archived')->json('page');
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertNotFound();
        $this->getJson('/api/v1/search?q=platform')->assertOk()->assertJsonCount(0, 'results');
        $this->postJson('/api/v1/studio/pages/'.$page['id'].'/unpublish', ['version' => $page['version']])->assertOk()->assertJsonPath('page.status', 'draft');
    }

    public function test_preview_is_signed_expiring_bound_to_version_and_uncacheable(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $url = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/preview-link')->assertOk()->json('url');
        $this->getJson('/api/v1/preview/'.$page['id'])->assertForbidden();
        $this->getJson($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertJsonPath('preview', true);
        $page = $this->savePage($page, ['summary' => 'Changed']);
        $this->getJson($url)->assertStatus(410);
        $expired = URL::temporarySignedRoute('pages.preview', now()->subMinute(), ['page' => $page['id'], 'version' => $page['version']]);
        $this->getJson($expired)->assertForbidden();
    }

    public function test_owner_cannot_demote_self_and_demoted_owner_cannot_mutate_accounts(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->actingAs($first)->patchJson('/api/v1/studio/users/'.$first->id, ['role' => 'admin', 'is_active' => true])->assertUnprocessable();
        $this->patchJson('/api/v1/studio/users/'.$second->id, ['role' => 'admin', 'is_active' => true])->assertOk();
        $this->flushSession(); // Switching test identities must not reuse the first user's password marker.
        $this->actingAs($second->fresh())->patchJson('/api/v1/studio/users/'.$first->id, ['role' => 'viewer', 'is_active' => false])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $first->id, 'role' => 'owner', 'is_active' => true]);
    }

    public function test_login_returns_rotated_csrf_token_and_inactive_credentials_fail(): void
    {
        $user = $this->user();
        $oldToken = $this->getJson('/api/v1/session')->assertOk()->json('csrf_token');
        $response = $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'test-password-long'])->assertOk();
        $this->assertNotEmpty($response->json('csrf_token'));
        $this->assertNotSame($oldToken, $response->json('csrf_token'));
        $this->deleteJson('/api/v1/session')->assertOk();
        $user->update(['is_active' => false]);
        $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'test-password-long'])->assertUnprocessable();
    }

    public function test_navigation_rejects_normalization_attacks_and_accepts_empty_list(): void
    {
        $this->actingAs($this->user());
        foreach (['/\\evil.test', '/%5cevil.test', '//evil.test', '/%2fevil.test'] as $url) {
            $this->putJson('/api/v1/studio/navigation', ['items' => [['label' => 'Unsafe', 'url' => $url, 'visible' => true]]])->assertUnprocessable();
        }
        $this->putJson('/api/v1/studio/navigation', ['items' => []])->assertOk()->assertJsonCount(0, 'navigation');
    }

    public function test_media_is_decoded_reencoded_and_cannot_be_deleted_while_referenced(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user());
        $asset = $this->postJson('/api/v1/studio/media', ['file' => UploadedFile::fake()->image('photo.png', 640, 480), 'alt' => 'Example'])->assertCreated()->json('asset');
        $this->assertSame(640, $asset['width']);
        Storage::disk('public')->assertExists([$asset['path'], $asset['thumbnail_path']]);
        $page = $this->createPage();
        $document = ['sections' => [['id' => 's', 'layout' => 'stack', 'blocks' => [['id' => 'b', 'type' => 'image', 'props' => ['src' => $asset['url'], 'alt' => 'Example']]]]]];
        $page = $this->savePage($page, ['document' => $document]);
        $this->deleteJson('/api/v1/studio/media/'.$asset['id'])->assertUnprocessable();
        $this->deleteJson('/api/v1/studio/pages/'.$page['id'], ['version' => $page['version']])->assertOk();
        $this->deleteJson('/api/v1/studio/media/'.$asset['id'])->assertOk();
        Storage::disk('public')->assertMissing([$asset['path'], $asset['thumbnail_path']]);
        $this->postJson('/api/v1/studio/media', ['file' => UploadedFile::fake()->create('evil.php', 1, 'application/x-php')])->assertUnprocessable();
    }

    public function test_theme_switching_does_not_modify_content_and_rejects_css_injection(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $before = Page::find($page['id'])->toArray();
        $this->putJson('/api/v1/studio/themes', ['id' => 'minimalist', 'settings' => ['accent' => '#123456']])->assertOk()->assertJsonPath('active', 'minimalist');
        $this->getJson('/api/v1/site')->assertOk()->assertJsonPath('theme.active', 'minimalist');
        $this->assertSame($before, Page::find($page['id'])->toArray());
        $this->putJson('/api/v1/studio/themes', ['id' => 'minimalist', 'settings' => ['accent' => 'url(javascript:bad)']])->assertUnprocessable();
        $this->flushSession();
        $this->actingAs($this->user('editor'))->putJson('/api/v1/studio/themes', ['id' => 'professional'])->assertForbidden();
    }

    public function test_page_writer_enforces_authorization_without_a_controller(): void
    {
        $owner = $this->user();
        $viewer = $this->user('viewer');
        $this->actingAs($owner);
        $page = $this->createPage();
        $this->expectException(AuthorizationException::class);
        app(PageWriter::class)->changeState(Page::find($page['id']), 'publish', $viewer->id, 1);
    }

    public function test_every_registered_layout_survives_save_publish_and_reload(): void
    {
        $this->actingAs($this->user());
        $registry = app(ContentRegistry::class);
        $page = $this->createPage('Registry matrix');
        $sections = [];
        foreach ($registry->layouts() as $layout) {
            $type = $layout['allowedBlocks'][0] ?? 'text';
            $sections[] = ['id' => 's-'.count($sections), 'layout' => $layout['id'], 'blocks' => [['id' => 'b-'.count($sections), 'type' => $type, 'props' => $registry->block($type)['defaults']]]];
        }
        $page = $this->savePage($page, ['document' => ['sections' => $sections]]);
        $this->getJson('/api/v1/studio/pages/'.$page['id'])->assertOk()->assertJsonPath('page.document', $page['document']);
        $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk();
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertOk()->assertJsonPath('page.document', $page['document']);
    }

    public function test_extension_data_uses_generic_validation_and_published_snapshot_isolation(): void
    {
        $this->actingAs($this->user());
        $registry = app(\App\Plugins\ExtensionRegistry::class);
        $registry->pageFields('example/reference', 'Reference metadata', [['key' => 'label', 'type' => 'text']], fn (array $values) => Validator::make($values, ['label' => ['required', 'string', 'max:50']])->validate());
        $page = $this->createPage();
        $page = $this->savePage($page, ['extensions' => ['example/reference' => ['label' => 'Published label']]]);
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk()->json('page');
        $page = $this->savePage($page, ['extensions' => ['example/reference' => ['label' => 'Private new label']]]);
        $this->getJson('/api/v1/pages/'.$page['slug'])->assertOk()->assertJsonPath('page.extensions.example/reference.label', 'Published label');
        $registry->forget('example/reference');
        $this->savePage($page, ['summary' => 'Core still works after disabling the provider']);
        $this->assertSame('Private new label', Page::find($page['id'])->extensions['example/reference']['label']);
    }

    public function test_unknown_extension_namespace_cannot_be_written(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage();
        $this->putJson('/api/v1/studio/pages/'.$page['id'], [
            'version' => 1, 'title' => $page['title'], 'slug' => $page['slug'], 'document' => $page['document'],
            'show_in_search' => true, 'extensions' => ['unknown/plugin' => ['title' => 'Untrusted']],
        ])->assertUnprocessable()->assertJsonValidationErrors('extensions');
        $this->assertSame(1, Page::find($page['id'])->version);
    }

    public function test_search_only_indexes_published_content_and_search_visibility_is_frozen(): void
    {
        $this->actingAs($this->user());
        $page = $this->createPage('Orchid reference');
        $page = $this->savePage($page, ['summary' => 'Published garden']);
        $page = $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk()->json('page');
        $page = $this->savePage($page, ['summary' => 'Unpublished tulip secret', 'show_in_search' => false]);
        $this->getJson('/api/v1/search?q=orchid')->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('pagination.total', 1);
        $this->getJson('/api/v1/search?q=tulip')->assertOk()->assertJsonCount(0, 'results');
        $this->postJson('/api/v1/studio/pages/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk();
        $this->getJson('/api/v1/search?q=orchid')->assertOk()->assertJsonCount(0, 'results');
    }
}
