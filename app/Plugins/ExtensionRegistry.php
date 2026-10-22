<?php

namespace App\Plugins;

use Illuminate\Validation\ValidationException;

/** Public, namespaced extension points. No official plugin has a privileged path. */
final class ExtensionRegistry
{
    private array $pageFields = [];
    private array $settings = [];
    private array $heads = [];
    private array $routes = [];
    private array $publicRoutes = [];
    private array $responses = [];
    private array $views = [];
    private array $tasks = [];
    private array $listeners = [];
    private array $listening = [];

    public function pageFields(string $id, string $label, array $fields, callable $validator): void { $this->pageFields[$id] = compact('label', 'fields', 'validator'); }
    public function settings(string $id, array $fields, callable $validator): void { $this->settings[$id] = compact('fields', 'validator'); }
    public function head(string $id, callable $provider): void { $this->heads[$id] = $provider; }
    public function route(string $id, string $method, string $path, string $permission, callable $handler): void { $this->routes[$id][strtoupper($method).':'.trim($path, '/')] = compact('permission', 'handler'); }
    public function publicRoute(string $id, string $path, callable $handler): void
    {
        if (!preg_match('~^/[a-zA-Z0-9._/-]+$~D', $path) || preg_match('~^/(api|studio|storage|build|up)(/|$)~', $path) || str_contains($path, '..')) throw new \InvalidArgumentException('Invalid public extension route.');
        foreach ($this->publicRoutes as $owner => $routes) if ($owner !== $id && isset($routes[$path])) throw new \LogicException('Public extension route collision.');
        $this->publicRoutes[$id][$path] = $handler;
    }
    public function publicResponse(string $id, callable $handler): void { $this->responses[$id] = $handler; }
    public function adminView(string $id, string $view, string $label): void { $this->views[$id][$view] = ['plugin_id' => $id, 'id' => $view, 'label' => $label]; }
    public function task(string $id, string $name, callable $handler): void { $this->tasks[$id][$name] = $handler; }
    public function listen(string $id, string $event, callable $handler): void
    {
        $this->listeners[$id][$event][] = $handler;
        if (isset($this->listening[$event])) return;
        $this->listening[$event] = true;
        \Illuminate\Support\Facades\Event::listen($event, function (...$arguments) use ($event) {
            foreach ($this->listeners as $id => $events) {
                if (\App\Models\PluginInstallation::find($id)?->status !== 'active') continue;
                foreach ($events[$event] ?? [] as $listener) {
                    try { $listener(...$arguments); } catch (\Throwable $error) { app(PluginManager::class)->report($id, $error, 'event'); }
                }
            }
        });
    }

    public function forget(string $id): void
    {
        foreach (['pageFields', 'settings', 'heads', 'routes', 'publicRoutes', 'responses', 'views', 'tasks', 'listeners'] as $registry) unset($this->{$registry}[$id]);
        app(\App\Services\ContentRegistry::class)->forgetPlugin($id);
    }

    public function descriptors(): array
    {
        app(PluginManager::class)->bootActive();
        return ['page_fields' => collect($this->pageFields)->map(fn ($entry, $id) => ['plugin_id' => $id, 'label' => $entry['label'], 'fields' => $entry['fields']])->values()->all(),
            'admin_views' => array_merge([], ...array_values(array_map('array_values', $this->views))), 'active_plugins' => app(PluginManager::class)->activeIds()];
    }

    public function validatePageData(array $incoming, array $existing = []): array
    {
        app(PluginManager::class)->bootActive();
        $this->validatePayloadSize($incoming);
        // A disabled plugin retains its data; neither writes nor omission may erase it.
        $result = $existing;
        foreach ($incoming as $id => $values) {
            if (!is_array($values)) throw ValidationException::withMessages(['extensions' => 'Extension fields must be objects.']);
            if (!isset($this->pageFields[$id])) {
                if (!array_key_exists($id, $existing) || $values !== $existing[$id]) throw ValidationException::withMessages(['extensions' => 'Unknown or disabled page extension.']);
                continue;
            }
            $validated = ($this->pageFields[$id]['validator'])($values);
            if (!is_array($validated)) throw ValidationException::withMessages(['extensions' => 'The '.$id.' extension returned invalid page data.']);
            $result[$id] = $validated;
        }
        // Retained inactive values count toward the limit; small writes must not grow an unbounded object.
        $this->validatePayloadSize($result);
        return $result;
    }

    private function validatePayloadSize(array $values): void
    {
        try { $size = strlen(json_encode($values, JSON_THROW_ON_ERROR, 32)); }
        catch (\JsonException) { throw ValidationException::withMessages(['extensions' => 'Page extension data must be bounded JSON.']); }
        if ($size > 65536) throw ValidationException::withMessages(['extensions' => 'Page extension data is too large.']);
    }

    public function headFor(array $published): array
    {
        app(PluginManager::class)->bootActive();
        $result = [];
        foreach ($this->heads as $id => $provider) {
            try { $result = array_replace_recursive($result, $provider($published)); }
            catch (\Throwable $error) { app(PluginManager::class)->report($id, $error, 'metadata'); }
        }
        return $result;
    }

    public function settingDefinition(string $id): ?array { return $this->settings[$id] ?? null; }
    public function routeDefinition(string $id, string $method, string $path): ?array { return $this->routes[$id][strtoupper($method).':'.trim($path, '/')] ?? null; }
    public function publicRoutes(): array { return $this->publicRoutes; }
    public function responses(): array { return $this->responses; }
    public function tasks(): array { return $this->tasks; }
}
