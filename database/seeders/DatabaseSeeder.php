<?php

namespace Database\Seeders;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        SiteSetting::firstOrCreate(['key' => 'brand'], ['value' => ['name' => 'FastFind', 'tagline' => 'Everything worth discovering, in one place.']]);
        foreach ([['label' => 'Home', 'url' => '/'], ['label' => 'Guides', 'url' => '/p/getting-started'], ['label' => 'About', 'url' => '/p/about-fastfind']] as $position => $item) {
            NavigationItem::firstOrCreate(['url' => $item['url']], [...$item, 'position' => $position, 'visible' => true]);
        }
        $examples = [
            ['Getting started', 'getting-started', 'An introduction to creating and publishing with FastFind.', [
                ['heading', ['text' => 'Make room for better content', 'level' => 1]],
                ['text', ['text' => 'FastFind is an independent content studio for publishing useful, beautiful pages. Draft your ideas, create flexible layouts, and share them with the world.']],
                ['callout', ['title' => 'Designed for clarity', 'text' => 'Compose content from blocks, arrange sections by dragging, and publish when you are ready.']],
            ]],
            ['About FastFind', 'about-fastfind', 'A calmer way to bring your content to life.', [
                ['heading', ['text' => 'One home for everything that matters', 'level' => 1]],
                ['text', ['text' => 'FastFind combines thoughtful publishing tools with a fast, accessible reading experience. Your content, your brand, your platform.']],
                ['quote', ['text' => 'The best content systems get out of the way of good ideas.', 'by' => 'The FastFind team']],
            ]],
        ];
        foreach ($examples as [$title, $slug, $summary, $items]) {
            if (Page::where('slug', $slug)->exists()) continue;
            $blocks = array_map(fn ($pair) => ['id' => (string) Str::uuid(), 'type' => $pair[0], 'props' => $pair[1]], $items);
            $document = ['sections' => [['id' => (string) Str::uuid(), 'layout' => 'stack', 'settings' => ['background' => 'plain', 'width' => 'normal'], 'blocks' => $blocks]]];
            $snapshot = ['title' => $title, 'slug' => $slug, 'summary' => $summary, 'seo_title' => null, 'seo_description' => null, 'document' => $document];
            $page = Page::create(['title' => $title, 'slug' => $slug, 'published_slug' => $slug, 'summary' => $summary, 'status' => 'published', 'document' => $document, 'published_snapshot' => $snapshot, 'published_search_text' => mb_strtolower(implode(' ', array_merge([$title, $summary], array_map(fn ($p) => implode(' ', array_filter($p[1], 'is_string')), $items)))), 'published_at' => now()]);
            $page->revisions()->create(['version' => 1, 'snapshot' => $page->only(['title', 'slug', 'summary', 'seo_title', 'seo_description', 'show_in_search', 'document'])]);
        }
    }
}
