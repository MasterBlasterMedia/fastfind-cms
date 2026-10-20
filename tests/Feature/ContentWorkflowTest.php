<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::create(['name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'very-long-secret', 'role' => 'editor', 'is_active' => true]);
    }

    public function test_content_is_hidden_until_publish_and_draft_edits_remain_private(): void
    {
        $this->actingAs($this->editor());
        $created = $this->postJson('/api/v1/studio/pages', ['title' => 'Hidden thought'])->assertCreated()->json('page');
        $this->getJson('/api/v1/pages/hidden-thought')->assertNotFound();
        $data = [
            'version' => $created['version'], 'title' => 'Hidden thought', 'slug' => 'hidden-thought',
            'summary' => 'Original published text', 'show_in_search' => true,
            'document' => ['sections' => []],
        ];
        $saved = $this->putJson('/api/v1/studio/pages/'.$created['id'], $data)->assertOk()->json('page');
        $published = $this->postJson('/api/v1/studio/pages/'.$created['id'].'/publish', ['version' => $saved['version']])->assertOk()->json('page');
        $this->getJson('/api/v1/pages/hidden-thought')->assertOk()->assertJsonPath('page.summary', 'Original published text');
        $data['version'] = $published['version'];
        $data['summary'] = 'Unpublished new text';
        $this->putJson('/api/v1/studio/pages/'.$created['id'], $data)->assertOk();
        $this->getJson('/api/v1/pages/hidden-thought')->assertOk()->assertJsonPath('page.summary', 'Original published text');
    }

    public function test_editor_cannot_manage_users_or_site_settings(): void
    {
        $this->actingAs($this->editor());
        $this->putJson('/api/v1/studio/settings', ['name' => 'Changed'])->assertForbidden();
        $this->postJson('/api/v1/studio/users', ['name' => 'Bad', 'email' => 'bad@example.test', 'role' => 'owner', 'password' => 'password123456'])->assertForbidden();
    }

    public function test_content_builder_rejects_duplicate_ids_and_unsafe_urls(): void
    {
        $this->actingAs($this->editor());
        $p = $this->postJson('/api/v1/studio/pages', ['title' => 'Validate'])->assertCreated()->json('page');
        $id = 's-one';
        $doc = ['sections' => [[
            'id' => $id, 'layout' => 'stack', 'settings' => ['background' => 'plain', 'width' => 'normal'],
            'blocks' => [['id' => $id, 'type' => 'button', 'props' => ['text' => 'Bad', 'url' => 'javascript:alert(1)']]],
        ]]];
        $this->putJson('/api/v1/studio/pages/'.$p['id'], ['version' => $p['version'], 'title' => 'Validate', 'slug' => 'validate', 'show_in_search' => true, 'document' => $doc])->assertUnprocessable();
        $doc['sections'][0]['blocks'][0]['id'] = 'b-one';
        $this->putJson('/api/v1/studio/pages/'.$p['id'], ['version' => $p['version'], 'title' => 'Validate', 'slug' => 'validate', 'show_in_search' => true, 'document' => $doc])->assertUnprocessable();
    }

    public function test_stale_editor_cannot_overwrite_newer_version(): void
    {
        $this->actingAs($this->editor());
        $p = $this->postJson('/api/v1/studio/pages', ['title' => 'Collision'])->assertCreated()->json('page');
        $data = ['version' => $p['version'], 'title' => 'Collision', 'slug' => 'collision', 'show_in_search' => true, 'document' => ['sections' => []]];
        $this->putJson('/api/v1/studio/pages/'.$p['id'], $data)->assertOk();
        $this->putJson('/api/v1/studio/pages/'.$p['id'], $data)->assertUnprocessable();
    }

    public function test_visitor_cannot_read_admin_api(): void
    {
        $this->getJson('/api/v1/studio/pages')->assertUnauthorized();
    }
}
