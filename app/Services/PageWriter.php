<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PageWriter
{
    public function __construct(private readonly DocumentSchema $schema) {}

    public function save(Page $page, array $data, int $expectedVersion, int $userId): Page
    {
        return DB::transaction(function () use ($page, $data, $expectedVersion, $userId) {
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'This page has changed in another browser. Reload before saving.']);
            }
            $locked->fill([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'summary' => $data['summary'] ?? null,
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'show_in_search' => $data['show_in_search'] ?? true,
                'document' => $this->schema->validate($data['document']),
                'updated_by' => $userId,
            ]);
            $locked->version++;
            $locked->save();
            $this->snapshot($locked, $userId);
            $this->audit($userId, 'page.saved', $locked);
            return $locked->fresh();
        });
    }

    public function changeState(Page $page, string $action, int $userId, int $expectedVersion): Page
    {
        return DB::transaction(function () use ($page, $action, $userId, $expectedVersion) {
            $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'This page changed in another browser. Reload before publishing.']);
            }
            if ($action === 'publish') {
                $locked->status = 'published';
                if (Page::where('published_slug', $locked->slug)->where('id', '!=', $locked->id)->exists()) {
                    throw ValidationException::withMessages(['slug' => 'That public URL is already in use.']);
                }
                $locked->published_slug = $locked->slug;
                $locked->published_in_search = $locked->show_in_search;
                $locked->published_at = now();
                $parts = [$locked->title, $locked->summary ?? ''];
                foreach ($locked->document['sections'] ?? [] as $section) {
                    foreach ($section['blocks'] ?? [] as $block) {
                        foreach ($block['props'] ?? [] as $value) {
                            if (is_string($value)) $parts[] = $value;
                        }
                    }
                }
                $locked->published_search_text = mb_strtolower(implode(' ', $parts));
                $locked->published_snapshot = [
                    'title' => $locked->title, 'slug' => $locked->slug, 'summary' => $locked->summary,
                    'seo_title' => $locked->seo_title, 'seo_description' => $locked->seo_description,
                    'document' => $this->schema->validate($locked->document),
                ];
            } else {
                $locked->status = 'draft';
                $locked->published_slug = null;
                $locked->published_at = null;
                $locked->published_snapshot = null;
                $locked->published_search_text = null;
            }
            $locked->updated_by = $userId;
            $locked->version++;
            $locked->save();
            $this->snapshot($locked, $userId);
            $this->audit($userId, 'page.'.$action, $locked);
            return $locked->fresh();
        });
    }

    public function restore(Page $page, int $version, int $expectedVersion, int $userId): Page
    {
        $revision = $page->revisions()->where('version', $version)->firstOrFail();
        $data = $revision->snapshot;
        return $this->save($page, [
            'title' => $data['title'], 'slug' => $data['slug'], 'summary' => $data['summary'],
            'seo_title' => $data['seo_title'], 'seo_description' => $data['seo_description'],
            'show_in_search' => $data['show_in_search'], 'document' => $data['document'],
        ], $expectedVersion, $userId);
    }

    public function snapshot(Page $page, int $userId): void
    {
        $page->revisions()->create(['version' => $page->version, 'user_id' => $userId,
            'snapshot' => $page->only(['title', 'slug', 'summary', 'seo_title', 'seo_description', 'show_in_search', 'document'])]);
    }

    private function audit(int $userId, string $action, Page $page): void
    {
        AuditEvent::create(['user_id' => $userId, 'action' => $action, 'subject' => 'page', 'subject_id' => $page->id]);
    }
}
