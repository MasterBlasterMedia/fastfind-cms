<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Services\ContentRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BuilderDemoTest extends TestCase
{
    use RefreshDatabase;
    public function test_demo_seed_contains_every_registered_layout_and_block_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $page = Page::where('slug', 'builder-catalog')->sole();
        $registry = app(ContentRegistry::class);
        $this->assertSame('draft', $page->status);
        $layouts = array_column($page->document['sections'], 'layout');
        $blocks = collect($page->document['sections'])->flatMap(fn ($section) => $section['blocks'])->pluck('type')->all();
        foreach ($registry->layouts() as $layout) $this->assertContains($layout['id'], $layouts);
        foreach ($registry->blocks() as $block) $this->assertContains($block['id'], $blocks);
        $this->getJson('/api/v1/pages/builder-catalog')->assertNotFound();
        $this->assertSame(1, $page->revisions()->count());
    }
}
