<?php

namespace App\Plugins;

use App\Models\PluginInstallation;
use App\Services\ContentRegistry;

final class PluginContext
{
    public function __construct(public readonly string $id, public readonly string $path, private readonly array $manifest, private readonly ExtensionRegistry $registry) {}

    private function requireCapability(string $capability): void
    {
        if (!in_array($capability, $this->manifest['permissions'], true)) throw new \LogicException("Plugin {$this->id} did not declare {$capability}.");
    }

    public function pageFields(string $label, array $fields, callable $validator): void { $this->requireCapability('pages.write'); $this->registry->pageFields($this->id, $label, $fields, $validator); }
    public function settingsFields(array $fields, callable $validator): void { $this->requireCapability('settings.manage'); $this->registry->settings($this->id, $fields, $validator); }
    public function settings(): array { return PluginInstallation::find($this->id)?->settings ?? []; }
    public function head(callable $provider): void { $this->requireCapability('pages.read'); $this->registry->head($this->id, $provider); }
    public function route(string $method, string $path, string $capability, callable $handler): void
    {
        $this->requireCapability($capability);
        if (!in_array($capability, ['pages.read', 'pages.write', 'settings.manage'], true) || !in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) || !preg_match('~^[a-z][a-z0-9/_-]*$~D', $path)) throw new \InvalidArgumentException('Invalid extension route.');
        $this->registry->route($this->id, $method, $path, $capability, $handler);
    }
    public function publicRoute(string $path, callable $handler): void { $this->requireCapability('public.routes'); $this->registry->publicRoute($this->id, $path, $handler); }
    public function publicResponse(callable $handler): void { $this->requireCapability('public.routes'); $this->registry->publicResponse($this->id, $handler); }
    public function adminView(string $view, string $label): void { $this->requireCapability('settings.manage'); $this->registry->adminView($this->id, $view, $label); }
    public function block(string $name, array $definition, callable $validator): void { $this->requireCapability('content.extend'); app(ContentRegistry::class)->registerBlock($this->id.'/'.$name, $definition, $validator); }
    public function layout(string $name, array $definition): void { $this->requireCapability('content.extend'); app(ContentRegistry::class)->registerLayout($this->id.'/'.$name, $definition); }
    public function listen(string $event, callable $handler): void
    {
        $this->requireCapability('events.listen');
        $this->registry->listen($this->id, $event, $handler);
    }
    /** Runs once per scheduler invocation (each minute); queue expensive work from the callback. */
    public function schedule(string $name, callable $handler): void { $this->requireCapability('schedule.run'); $this->registry->task($this->id, $name, $handler); }
    public function migrate(): void
    {
        $directory = $this->path.'/migrations';
        if (is_dir($directory)) app('migrator')->run([$directory], ['force' => true]);
    }
}
