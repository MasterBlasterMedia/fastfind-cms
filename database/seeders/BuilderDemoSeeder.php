<?php

namespace Database\Seeders;

use App\Models\Page;
use App\Services\ContentRegistry;
use App\Services\DocumentSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class BuilderDemoSeeder extends Seeder
{
    public function run(ContentRegistry $registry, DocumentSchema $schema): void
    {
        if (Page::where('slug', 'builder-catalog')->exists()) return;
        $sections = []; $covered = [];
        foreach ($registry->layouts() as $layout) {
            $definition = collect($registry->blocks())->first(fn ($block) => empty($layout['allowedBlocks']) || in_array($block['id'], $layout['allowedBlocks'], true));
            $blocks = [];
            $samples = max(1, $layout['minBlocks'] ?? 0, $layout['responsive']['desktop'] ?? 1);
            if (in_array($layout['id'], ['accordion', 'tabs', 'carousel'], true)) $samples = max(2, $samples);
            for ($index = 0; $index < $samples; $index++) {
                $blocks[] = $this->sample($definition, $index);
                $covered[$definition['id']] = true;
            }
            $sections[] = ['id' => (string) Str::uuid(), 'layout' => $layout['id'], 'settings' => ['background' => 'plain', 'width' => 'normal'], 'blocks' => $blocks];
        }
        $remaining = [];
        foreach ($registry->blocks() as $block) if (!isset($covered[$block['id']])) $remaining[] = $this->sample($block);
        if ($remaining) $sections[] = ['id' => (string) Str::uuid(), 'layout' => 'stack', 'settings' => ['background' => 'plain', 'width' => 'normal'], 'blocks' => $remaining];
        $document = $schema->validate(['sections' => $sections]);
        $page = Page::create(['title' => 'Builder catalog', 'slug' => 'builder-catalog', 'summary' => 'Editable demonstration of every registered layout and content block.', 'document' => $document]);
        $page->revisions()->create(['version' => 1, 'snapshot' => $page->only(['title', 'slug', 'summary', 'seo_title', 'seo_description', 'show_in_search', 'document', 'extensions'])]);
    }

    private function sample(array $definition, int $index = 0): array
    {
        $props = $definition['defaults'];
        if ($definition['id'] === 'heading') $props['text'] = 'An idea worth sharing'.($index ? ' · '.($index + 1) : '');
        if ($definition['id'] === 'tab-content') $props['title'] = ['Overview', 'Details'][$index % 2];
        if ($definition['id'] === 'image') $props = ['src' => '/images/demo-landscape.svg', 'alt' => 'Illustrated mountain landscape', 'caption' => 'A local demo image'];
        if ($definition['id'] === 'gallery') $props['items'] = "/images/demo-landscape.svg | Illustrated mountains | First view\n/images/demo-landscape.svg | Illustrated mountains | Second view";
        return ['id' => (string) Str::uuid(), 'type' => $definition['id'], 'props' => $props];
    }
}
