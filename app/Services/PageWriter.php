<?php

namespace App\Services;

use App\Events\PageChanged;
use App\Models\AuditEvent;
use App\Models\Page;
use App\Models\User;
use App\Plugins\ExtensionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PageWriter
{
    public function __construct(private readonly DocumentSchema $schema) {}

    public function save(Page $page, array $data, int $expectedVersion, int $userId, string $action = 'saved'): Page
    {
        return $this->write(function () use ($page, $data, $expectedVersion, $userId, $action) {
            $locked = $this->lock($page, $expectedVersion, $userId, 'update');
            $input = Validator::make($data, PageDataRules::for($locked))->validate();
            $locked->fill([
                'title' => $input['title'], 'slug' => $input['slug'], 'summary' => $input['summary'] ?? null,
                'seo_title' => array_key_exists('seo_title', $input) ? $input['seo_title'] : $locked->seo_title,
                'seo_description' => array_key_exists('seo_description', $input) ? $input['seo_description'] : $locked->seo_description,
                'show_in_search' => $input['show_in_search'], 'document' => $this->schema->validate($input['document']),
                'extensions' => app(ExtensionRegistry::class)->validatePageData($input['extensions'] ?? $locked->extensions ?? [], $locked->extensions ?? []),
                'updated_by' => $userId,
            ]);
            $locked->version++;
            $locked->save();
            $this->snapshot($locked, $userId);
            $this->audit($userId, 'page.'.$action, $locked);
            $this->changed($locked, $action);
            return $locked->fresh();
        });
    }

    public function changeState(Page $page, string $action, int $userId, int $expectedVersion): Page
    {
        if (!in_array($action, ['publish', 'unpublish', 'review', 'archive'], true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported publishing action.']);
        }
        return $this->write(function () use ($page, $action, $userId, $expectedVersion) {
            $locked = $this->lock($page, $expectedVersion, $userId, 'publish');
            if ($action === 'publish') {
                if (Page::where('published_slug', $locked->slug)->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['slug' => 'That public URL is already in use.']);
                }
                $document = $this->schema->validate($locked->document);
                $extensions = app(ExtensionRegistry::class)->validatePageData($locked->extensions ?? [], $locked->extensions ?? []);
                $locked->status = 'published';
                $locked->published_slug = $locked->slug;
                $locked->published_in_search = $locked->show_in_search;
                $locked->published_at = now();
                $parts = [$locked->title, $locked->summary ?? ''];
                foreach ($document['sections'] as $section) {
                    foreach ($section['blocks'] as $block) {
                        foreach ($block['props'] as $value) if (is_string($value)) $parts[] = $value;
                    }
                }
                $locked->published_search_text = mb_strtolower(implode(' ', $parts));
                $locked->published_snapshot = [
                    'title' => $locked->title, 'slug' => $locked->slug, 'summary' => $locked->summary,
                    'seo_title' => $locked->seo_title, 'seo_description' => $locked->seo_description,
                    'document' => $document, 'extensions' => $extensions,
                ];
            } elseif ($action === 'review') {
                // Review never removes an already live publication.
                if ($locked->status !== 'published') $locked->status = 'in_review';
            } else {
                $locked->status = $action === 'archive' ? 'archived' : 'draft';
                $locked->published_slug = null;
                $locked->published_at = null;
                $locked->published_snapshot = null;
                $locked->published_search_text = null;
                $locked->published_in_search = false;
            }
            $locked->scheduled_at = null;
            $locked->updated_by = $userId;
            $locked->version++;
            $locked->save();
            $this->snapshot($locked, $userId);
            $this->audit($userId, 'page.'.$action, $locked);
            $this->changed($locked, $action);
            return $locked->fresh();
        });
    }

    public function schedule(Page $page, string $date, int $expectedVersion, int $userId): Page
    {
        return $this->write(function () use ($page, $date, $expectedVersion, $userId) {
            $locked = $this->lock($page, $expectedVersion, $userId, 'publish');
            Validator::make(['scheduled_at' => $date], ['scheduled_at' => ['required', 'date', 'after:now']])->validate();
            if ($locked->status !== 'published') $locked->status = 'scheduled';
            $locked->scheduled_at = $date;
            $locked->updated_by = $userId;
            $locked->version++;
            $locked->save();
            $this->snapshot($locked, $userId);
            $this->audit($userId, 'page.scheduled', $locked);
            $this->changed($locked, 'scheduled');
            return $locked->fresh();
        });
    }

    public function restore(Page $page, int $version, int $expectedVersion, int $userId): Page
    {
        $revision = $page->revisions()->where('version', $version)->firstOrFail();
        return $this->save($page, $revision->snapshot, $expectedVersion, $userId, 'restored');
    }

    public function delete(Page $page, int $expectedVersion, int $userId): void
    {
        $this->write(function () use ($page, $expectedVersion, $userId) {
            $locked = $this->lock($page, $expectedVersion, $userId, 'delete');
            AuditEvent::create(['user_id' => $userId, 'action' => 'page.deleted', 'subject' => 'page', 'subject_id' => $locked->id, 'context' => ['title' => $locked->title]]);
            $locked->delete();
            $this->changed($locked, 'deleted');
        });
    }

    public function snapshot(Page $page, int $userId): void
    {
        $page->revisions()->create(['version' => $page->version, 'user_id' => $userId,
            'snapshot' => $page->only(['title', 'slug', 'summary', 'seo_title', 'seo_description', 'show_in_search', 'document', 'extensions'])]);
    }

    private function lock(Page $page, int $expectedVersion, int $userId, string $ability): Page
    {
        $locked = Page::whereKey($page->id)->lockForUpdate()->firstOrFail();
        Gate::forUser(User::findOrFail($userId))->authorize($ability, $locked);
        if ($locked->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => 'This page has changed in another browser. Reload before continuing.']);
        }
        return $locked;
    }

    private function write(callable $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['slug' => 'This URL or page version is already in use. Reload and try again.']);
            }
            throw $exception;
        }
    }

    private function changed(Page $page, string $action): void
    {
        DB::afterCommit(fn () => PageChanged::dispatch($page, $action));
    }

    private function audit(int $userId, string $action, Page $page): void
    {
        AuditEvent::create(['user_id' => $userId, 'action' => $action, 'subject' => 'page', 'subject_id' => $page->id]);
    }
}
