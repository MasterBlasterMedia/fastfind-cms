<?php

namespace App\Http\Controllers;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicController extends Controller
{
    public function site(): JsonResponse
    {
        return response()->json([
            'brand' => SiteSetting::find('brand')?->value ?? ['name' => 'FastFind', 'tagline' => 'Find the extraordinary in the everyday.'],
            'navigation' => NavigationItem::query()->where('visible', true)->orderBy('position')->get(['label', 'url']),
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
            'published_at' => $page->published_at,
        ]]);
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
            ->orderByDesc('published_at')->limit(30)->get()
            ->map(fn ($p) => [
                'title' => $p->publishedView()['title'] ?? $p->title,
                'slug' => $p->published_slug,
                'summary' => $p->publishedView()['summary'] ?? '',
            ]);
        return response()->json(['results' => $pages, 'query' => $term]);
    }
}
