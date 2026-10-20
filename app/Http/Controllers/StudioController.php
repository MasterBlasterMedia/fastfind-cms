<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\DocumentSchema;
use App\Services\PageWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StudioController extends Controller
{
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
        $pages = Page::query()->when(in_array($status, ['draft', 'published'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')->paginate(30, ['id', 'title', 'slug', 'published_slug', 'status', 'version', 'updated_at', 'published_at']);
        return response()->json($pages);
    }

    public function create(Request $request, DocumentSchema $schema, PageWriter $writer): JsonResponse
    {
        Gate::authorize('edit-content');
        $input = $request->validate(['title' => ['required', 'string', 'max:180']]);
        $page = DB::transaction(function () use ($input, $request, $schema, $writer) {
            $title = trim($input['title']);
            $root = Str::slug($title) ?: 'untitled';
            $slug = $root;
            for ($i = 2; Page::where('slug', $slug)->exists(); $i++) $slug = $root.'-'.$i;
            $page = Page::create(['title' => $title, 'slug' => $slug, 'status' => 'draft',
                'document' => $schema->starter(), 'author_id' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $writer->snapshot($page, $request->user()->id);
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'page.created', 'subject' => 'page', 'subject_id' => $page->id]);
            return $page;
        });
        return response()->json(['page' => $page], 201);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json(['page' => $page]);
    }

    private function pageRules(Page $page): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['required', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('pages', 'slug')->ignore($page->id)],
            'summary' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'show_in_search' => ['required', 'boolean'],
            'document' => ['required', 'array'],
        ];
    }

    public function update(Request $request, Page $page, PageWriter $writer): JsonResponse
    {
        Gate::authorize('edit-content');
        $data = $request->validate($this->pageRules($page));
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

    public function delete(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('edit-content');
        DB::transaction(function () use ($request, $page) {
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'page.deleted', 'subject' => 'page', 'subject_id' => $page->id, 'context' => ['title' => $page->title]]);
            $page->delete();
        });
        return response()->json(['ok' => true]);
    }

    public function revisions(Page $page): JsonResponse
    {
        return response()->json(['revisions' => $page->revisions()->orderByDesc('version')->limit(50)->get(['id', 'version', 'user_id', 'created_at'])]);
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
            'items' => ['required', 'array', 'max:20'],
            'items.*.label' => ['required', 'string', 'max:90'],
            'items.*.url' => ['required', 'string', 'max:500'],
            'items.*.visible' => ['required', 'boolean'],
        ]);
        foreach ($data['items'] as $item) {
            $url = $item['url'];
            if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
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

    public function media(): JsonResponse
    {
        return response()->json(['media' => MediaAsset::latest()->limit(100)->get()]);
    }

    public function upload(Request $request): JsonResponse
    {
        Gate::authorize('edit-content');
        $input = $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif'], 'alt' => ['nullable', 'string', 'max:255']]);
        $file = $input['file'];
        $path = $file->store('images/'.date('Y/m'), 'public');
        $asset = MediaAsset::create(['filename' => Str::limit($file->getClientOriginalName(), 240, ''),
            'path' => $path, 'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(), 'alt' => $input['alt'] ?? '', 'uploaded_by' => $request->user()->id]);
        return response()->json(['asset' => $asset], 201);
    }

    public function deleteMedia(Request $request, MediaAsset $asset): JsonResponse
    {
        Gate::authorize('manage-site');
        $path = $asset->path;
        $asset->delete();
        Storage::disk('public')->delete($path);
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

    public function updateUser(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])], 'is_active' => ['required', 'boolean']]);
        if ($request->user()->is($user) && ($data['role'] !== 'owner' || !$data['is_active'])) {
            throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own owner account.']);
        }
        if ($user->role === 'owner' && ($data['role'] !== 'owner' || !$data['is_active']) && User::where('role', 'owner')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'There must be at least one active owner.']);
        }
        $user->update($data);
        AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'user.updated', 'subject' => 'user', 'subject_id' => $user->id]);
        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role', 'is_active'])]);
    }
}
