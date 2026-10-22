<?php

namespace App\Http\Controllers;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Services\ThemeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicController extends Controller
{
    public function site(ThemeManager $themes): JsonResponse
    {
        return response()->json([
            'active_plugins' => app(\App\Plugins\PluginManager::class)->activeIds(),
            'brand' => SiteSetting::find('brand')?->value ?? ['name' => 'FastFind', 'tagline' => 'Find the extraordinary in the everyday.'],
            'navigation' => NavigationItem::query()->where('visible', true)->orderBy('position')->get(['label', 'url']),
            'theme' => $themes->current(),
        ]);
    }

    public function landing(): JsonResponse
    {
        $featured = Page::query()->where('status', 'published')->orderByDesc('published_at')->limit(8)->get();
        return response()->json(['featured' => $featured->map(fn ($p) => [
            'title' => $p->publishedView()['title'] ?? $p->title,
            'slug' => $p->published_slug,
            'summary' => $p->publishedView()['summary'] ?? '',
        ])]);
    }

    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->where('status', 'published')->where('published_slug', $slug)->firstOrFail();
        $snapshot = $page->publishedView();
        return response()->json(['page' => [
            'title' => $snapshot['title'] ?? $page->title,
            'slug' => $page->published_slug,
            'summary' => $snapshot['summary'] ?? null,
            'seo_title' => $snapshot['seo_title'] ?? null,
            'seo_description' => $snapshot['seo_description'] ?? null,
            'document' => $snapshot['document'] ?? ['sections' => []],
            'extensions' => $snapshot['extensions'] ?? [],
            'head' => \App\Plugins\HeadMetadata::normalize(app(\App\Plugins\ExtensionRegistry::class)->headFor($snapshot)),
            'published_at' => $page->published_at,
        ]]);
    }

    public function preview(Request $request, Page $page): JsonResponse
    {
        // Signed links grant access to one saved version only, never subsequent edits.
        abort_unless($request->hasValidSignature(), 403);
        abort_unless((string) $request->query('version') === (string) $page->version, 410, 'This preview was superseded. Ask for a new link.');
        return response()->json(['page' => $page->only(['title', 'slug', 'summary', 'document', 'extensions', 'version']), 'preview' => true])
            ->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function search(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = trim($input['q']);
        $cleanTerm = trim(preg_replace('/[%_\\\\]/u', '', $term) ?? '');
        if (mb_strlen($cleanTerm) < 2) return response()->json(['results' => [], 'query' => $term]);
        $like = '%'.mb_strtolower($cleanTerm).'%';
        $pages = Page::query()
            ->where('status', 'published')->where('published_in_search', true)
            ->whereRaw('LOWER(published_search_text) LIKE ?', [$like])
            ->orderByRaw('CASE WHEN LOWER(published_search_text) LIKE ? THEN 0 ELSE 1 END', [mb_strtolower($cleanTerm).'%'])
            ->orderByDesc('published_at')->paginate(20);
        $results = $pages->getCollection()->map(fn ($p) => [
                'title' => $p->publishedView()['title'] ?? $p->title,
                'slug' => $p->published_slug,
                'summary' => $p->publishedView()['summary'] ?? '',
            ]);
        return response()->json(['results' => $results, 'query' => $term, 'pagination' => ['page' => $pages->currentPage(), 'last_page' => $pages->lastPage(), 'total' => $pages->total()]]);
    }
}
