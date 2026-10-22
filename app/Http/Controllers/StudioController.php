<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Events\PageChanged;
use App\Http\Requests\SavePageRequest;
use App\Services\ContentRegistry;
use App\Services\DocumentSchema;
use App\Services\MediaLibrary;
use App\Services\PageWriter;
use App\Services\SafeUrl;
use App\Services\ThemeManager;
use App\Services\UserManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StudioController extends Controller
{
    public function contentSchema(ContentRegistry $registry): JsonResponse
    {
        app(\App\Plugins\PluginManager::class)->bootActive();
        return response()->json($registry->catalog());
    }

    public function health(\App\Services\SystemHealth $health): JsonResponse
    {
        Gate::authorize('manage-users');
        return response()->json($health->report())->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'pages' => Page::count(), 'published' => Page::where('status', 'published')->count(),
            'drafts' => Page::where('status', 'draft')->count(), 'media' => MediaAsset::count(),
            'recent' => Page::latest('updated_at')->limit(5)->get(['id', 'title', 'status', 'updated_at', 'version']),
            'events' => AuditEvent::latest('id')->limit(8)->get(['action', 'subject', 'subject_id', 'created_at']),
        ]);
    }

    public function pages(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $pages = Page::query()->when(in_array($status, ['draft', 'in_review', 'scheduled', 'published', 'archived'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')->paginate(30, ['id', 'title', 'slug', 'published_slug', 'status', 'version', 'updated_at', 'published_at', 'scheduled_at']);
        return response()->json($pages);
    }

    public function create(Request $request, DocumentSchema $schema, PageWriter $writer): JsonResponse
    {
        Gate::authorize('create', Page::class);
        $input = $request->validate(['title' => ['required', 'string', 'max:180']]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $page = DB::transaction(function () use ($input, $request, $schema, $writer) {
            $title = trim($input['title']);
            $root = Str::slug($title) ?: 'untitled';
            $slug = $root;
            for ($i = 2; Page::where('slug', $slug)->exists(); $i++) $slug = $root.'-'.$i;
            $page = Page::create(['title' => $title, 'slug' => $slug, 'status' => 'draft',
                'document' => $schema->starter(), 'author_id' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $writer->snapshot($page, $request->user()->id);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'page.created', 'subject' => 'page', 'subject_id' => $page->id]);
            DB::afterCommit(fn () => PageChanged::dispatch($page, 'created'));
            return $page;
                }, 3);
                return response()->json(['page' => $page], 201);
            } catch (QueryException $exception) {
                if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) throw $exception;
            }
        }
        throw ValidationException::withMessages(['title' => 'Another page was created with this URL. Please try again.']);
    }

    public function show(Page $page): JsonResponse
    {
        Gate::authorize('view', $page);
        return response()->json(['page' => $page]);
    }

    public function update(SavePageRequest $request, Page $page, PageWriter $writer): JsonResponse
    {
        $data = $request->validated();
        $new = $writer->save($page, $data, (int) $data['version'], $request->user()->id);
        return response()->json(['page' => $new]);
    }

    public function publish(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('edit-content');
        $input = $request->validate(['version' => ['required', 'integer']]);
        if ($page->version !== (int) $input['version']) throw ValidationException::withMessages(['version' => 'Reload the page before publishing.']);
        return response()->json(['page' => $writer->changeState($page, 'publish', $request->user()->id, (int) $input['version'])]);
    }

    public function unpublish(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('edit-content');
        $input = $request->validate(['version' => ['required', 'integer']]);
        if ($page->version !== (int) $input['version']) throw ValidationException::withMessages(['version' => 'Reload the page before unpublishing.']);
        return response()->json(['page' => $writer->changeState($page, 'unpublish', $request->user()->id, (int) $input['version'])]);
    }

    public function delete(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('delete', $page);
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $writer->delete($page, (int) $input['version'], $request->user()->id);
        return response()->json(['ok' => true]);
    }

    public function revisions(Page $page): JsonResponse
    {
        Gate::authorize('view', $page);
        return response()->json(['revisions' => $page->revisions()->orderByDesc('version')->limit(50)->get(['id', 'version', 'user_id', 'created_at'])]);
    }

    public function revision(Page $page, int $revisionVersion): JsonResponse
    {
        Gate::authorize('view', $page);
        $revision = $page->revisions()->where('version', $revisionVersion)->firstOrFail();
        $current = $page->only(['title', 'slug', 'summary', 'seo_title', 'seo_description', 'show_in_search', 'document', 'extensions']);
        $changed = array_values(array_filter(array_keys($current), fn ($key) => ($revision->snapshot[$key] ?? null) !== $current[$key]));
        return response()->json(['revision' => $revision, 'current' => $current, 'changed_fields' => $changed]);
    }

    public function schedule(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('publish', $page);
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'scheduled_at' => ['required', 'date', 'after:now']]);
        return response()->json(['page' => $writer->schedule($page, $input['scheduled_at'], (int) $input['version'], $request->user()->id)]);
    }

    public function review(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        return response()->json(['page' => $writer->changeState($page, 'review', $request->user()->id, (int) $input['version'])]);
    }

    public function archive(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        return response()->json(['page' => $writer->changeState($page, 'archive', $request->user()->id, (int) $input['version'])]);
    }

    public function previewLink(Page $page): JsonResponse
    {
        Gate::authorize('update', $page);
        $expires = now()->addMinutes(30);
        return response()->json(['url' => URL::temporarySignedRoute('pages.preview', $expires, ['page' => $page->id, 'version' => $page->version]), 'expires_at' => $expires->toIso8601String()]);
    }

    public function restore(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('edit-content');
        $data = $request->validate(['revision_version' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer']]);
        return response()->json(['page' => $writer->restore($page, $data['revision_version'], $data['version'], $request->user()->id)]);
    }

    public function navigation(): JsonResponse
    {
        return response()->json(['navigation' => NavigationItem::orderBy('position')->get()]);
    }

    public function saveNavigation(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $request->validate([
            'items' => ['present', 'array', 'max:20'],
            'items.*.label' => ['required', 'string', 'max:90'],
            'items.*.url' => ['required', 'string', 'max:500'],
            'items.*.visible' => ['required', 'boolean'],
        ]);
        foreach ($data['items'] as $item) {
            $url = $item['url'];
            if (!SafeUrl::valid($url, localOnly: true)) {
                throw ValidationException::withMessages(['items' => 'Navigation links must be relative to this site.']);
            }
        }
        DB::transaction(function () use ($data, $request) {
            NavigationItem::query()->delete();
            foreach ($data['items'] as $index => $item) NavigationItem::create([...$item, 'position' => $index]);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'navigation.saved', 'subject' => 'site']);
        });
        return $this->navigation();
    }

    public function media(Request $request): JsonResponse
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = MediaAsset::latest();
        if (!empty($input['q'])) $query->where('filename', 'like', '%'.str_replace(['%', '_'], '', $input['q']).'%');
        return response()->json(['media' => $query->limit(100)->get()]);
    }

    public function upload(Request $request, MediaLibrary $library): JsonResponse
    {
        Gate::authorize('edit-content');
        $input = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif'], 'alt' => ['nullable', 'string', 'max:255']]);
        $asset = $library->upload($input['file'], $input['alt'] ?? '', $request->user()->id);
        return response()->json(['asset' => $asset], 201);
    }

    public function deleteMedia(Request $request, MediaAsset $asset, MediaLibrary $library): JsonResponse
    {
        Gate::authorize('manage-site');
        $library->delete($asset, $request->user()->id);
        return response()->json(['ok' => true]);
    }

    public function settings(): JsonResponse
    {
        Gate::authorize('manage-site');
        return response()->json(['brand' => SiteSetting::find('brand')?->value ?? ['name' => 'FastFind', 'tagline' => 'Find the extraordinary in the everyday.']]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $brand = $request->validate(['name' => ['required', 'string', 'max:80'], 'tagline' => ['nullable', 'string', 'max:180']]);
        SiteSetting::updateOrCreate(['key' => 'brand'], ['value' => $brand]);
        AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'settings.saved', 'subject' => 'site']);
        return response()->json(['brand' => $brand]);
    }

    public function users(): JsonResponse
    {
        Gate::authorize('manage-users');
        return response()->json(['users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'created_at'])]);
    }

    public function createUser(Request $request): JsonResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])],
        ]);
        $user = User::create([...$data, 'is_active' => true]);
        AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'user.created', 'subject' => 'user', 'subject_id' => $user->id]);
        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role'])], 201);
    }

    public function updateUser(Request $request, User $user, UserManager $manager): JsonResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])], 'is_active' => ['required', 'boolean']]);
        $user = $manager->update($request->user()->id, $user->id, $data);
        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role', 'is_active'])]);
    }

    public function themes(ThemeManager $themes): JsonResponse
    {
        Gate::authorize('manage-site');
        return response()->json(['themes' => $themes->all(), ...$themes->current()]);
    }

    public function saveTheme(Request $request, ThemeManager $themes): JsonResponse
    {
        Gate::authorize('manage-site');
        $input = $request->validate(['id' => ['required', 'string'], 'settings' => ['sometimes', 'array']]);
        return response()->json($themes->activate($input['id'], $input['settings'] ?? [], $request->user()->id));
    }
}
