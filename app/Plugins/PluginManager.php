<?php

namespace App\Plugins;

use App\Models\AuditEvent;
use App\Models\PluginInstallation;
use App\Plugins\Contracts\Plugin;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PluginManager
{
    private array $booted = [];
    private array $loaded = [];
    private static array $processReleases = [];
    private bool $booting = false;

    public function __construct(private Manifest $manifests, private ExtensionRegistry $extensions) {}

    public function available(): array
    {
        $available = [];
        foreach (config('plugins.bundled_paths', []) as $path) {
            if (!is_file($path.'/plugin.json')) continue;
            $manifest = $this->manifests->read($path.'/plugin.json');
            if (isset($available[$manifest['id']])) $this->fail('Duplicate bundled plugin ID.');
            $available[$manifest['id']] = ['manifest' => $manifest, 'path' => realpath($path)];
        }
        return $available;
    }

    public function listing(): array
    {
        $available = $this->available();
        $installed = PluginInstallation::all()->keyBy('id');
        $ids = array_unique([...array_keys($available), ...$installed->keys()->all()]);
        return array_values(array_map(function ($id) use ($available, $installed) {
            $record = $installed->get($id);
            $manifest = $available[$id]['manifest'] ?? $record->manifest;
            $issues = $this->manifests->issues($manifest);
            return [...$manifest, 'installed_version' => $record?->installed_version, 'status' => $record?->status ?? 'available',
                'source' => $record?->source ?? 'bundled', 'compatible' => !$issues, 'issues' => $issues,
                'last_error' => $record?->last_error, 'update_available' => $record && version_compare($manifest['version'], $record->installed_version, '>')];
        }, $ids));
    }

    public function installBundled(string $id, int $actor): PluginInstallation
    {
        $candidate = $this->available()[$id] ?? null;
        if (!$candidate) $this->fail('No reviewed bundled plugin with that ID exists.');
        return $this->installVerified($candidate['path'], $candidate['manifest'], 'bundled', $actor);
    }

    /** Only call with reviewed source or after signature verification and archive inspection. */
    public function installVerified(string $path, array $manifest, string $source, int $actor): PluginInstallation
    {
        $this->authorize($actor);
        return $this->locked(function () use ($path, $manifest, $source, $actor) {
            if (PluginInstallation::find($manifest['id'])) $this->fail('Plugin already installed. Deactivate and use the upgrade operation.');
            $this->compatible($manifest);
            $files = $this->digests($path);
            if (!isset($files[$manifest['entry']])) $this->fail('Plugin entry is missing.');
            return DB::transaction(function () use ($path, $manifest, $source, $actor, $files) {
                $plugin = PluginInstallation::create(['id' => $manifest['id'], 'installed_version' => $manifest['version'], 'status' => 'inactive',
                    'source' => $source, 'path' => $path, 'manifest' => $manifest, 'files' => $files, 'settings' => \App\Models\SiteSetting::find('plugin-preserved:'.$manifest['id'])?->value ?? []]);
                $this->audit($actor, 'plugin.installed', $plugin->id);
                return $plugin;
            });
        });
    }

    public function activate(string $id, int $actor): PluginInstallation
    {
        $this->authorize($actor);
        return $this->locked(function () use ($id, $actor) {
            $record = PluginInstallation::findOrFail($id);
            if ($record->status === 'active') return $record;
            $this->compatible($record->manifest);
            $this->bootActive();
            $this->dependencies($record->manifest);
            try {
                [$plugin, $context] = $this->load($record);
                $plugin->activate($context);
                $plugin->register($context);
                $record->update(['status' => 'active', 'last_error' => null]);
                $this->booted[$id] = true;
                $this->audit($actor, 'plugin.activated', $id);
                return $record->fresh();
            } catch (\Throwable $error) {
                $this->extensions->forget($id);
                $record->update(['status' => 'error', 'last_error' => 'Activation failed; inspect the private application log.']);
                $this->report($id, $error, 'activation');
                $this->fail('Plugin activation failed. It remains disabled; inspect the application log.');
            }
        });
    }

    public function deactivate(string $id, int $actor): PluginInstallation
    {
        $this->authorize($actor);
        return $this->locked(function () use ($id, $actor) {
            $record = PluginInstallation::findOrFail($id);
            foreach (PluginInstallation::where('status', 'active')->get() as $other) {
                if ($other->id !== $id && isset($other->manifest['dependencies'][$id])) $this->fail('An active plugin depends on this plugin. Deactivate dependents first.');
            }
            // Disable first, even when the plugin has been damaged or its cleanup hook fails.
            $record->update(['status' => 'inactive']);
            $this->extensions->forget($id);
            unset($this->booted[$id]);
            try { [$plugin, $context] = $this->load($record); $plugin->deactivate($context); }
            catch (\Throwable $error) { $this->report($id, $error, 'deactivation'); $record->update(['last_error' => 'Disabled, but deactivation hook failed.']); }
            $this->audit($actor, 'plugin.deactivated', $id);
            return $record->fresh();
        });
    }

    public function uninstall(string $id, int $actor, bool $purge = false): void
    {
        $this->authorize($actor);
        $this->locked(function () use ($id, $actor, $purge) {
            $record = PluginInstallation::findOrFail($id);
            if ($record->status === 'active') $this->fail('Deactivate the plugin before uninstalling it.');
            $this->assertNoActiveDependents($id);
            if ($purge) {
                [$plugin, $context] = $this->load($record);
                $plugin->uninstall($context, true);
            }
            // Preserve settings for reinstall and retain files/data unless purge was requested.
            if (!$purge) \App\Models\SiteSetting::updateOrCreate(['key' => 'plugin-preserved:'.$id], ['value' => $record->settings ?? []]);
            else \App\Models\SiteSetting::where('key', 'plugin-preserved:'.$id)->delete();
            $record->delete();
            $this->extensions->forget($id);
            unset($this->booted[$id]);
            $this->audit($actor, 'plugin.uninstalled', $id, ['purge' => $purge]);
        });
    }

    public function upgradeBundled(string $id, int $actor): PluginInstallation
    {
        $candidate = $this->available()[$id] ?? null;
        if (!$candidate) $this->fail('Upload a verified new package for this plugin.');
        return $this->upgradeVerified($candidate['path'], $candidate['manifest'], $actor);
    }

    public function upgradeVerified(string $path, array $manifest, int $actor): PluginInstallation
    {
        $this->authorize($actor);
        return $this->locked(function () use ($path, $manifest, $actor) {
            $record = PluginInstallation::findOrFail($manifest['id']);
            if ($record->status === 'active') $this->fail('Deactivate before upgrading.');
            $this->assertNoActiveDependents($record->id);
            if (!version_compare($manifest['version'], $record->installed_version, '>')) $this->fail('Upgrade requires a newer version.');
            $this->compatible($manifest);
            $this->bootActive();
            $this->dependencies($manifest);
            $previous = $record->only(['path', 'manifest', 'files', 'installed_version', 'status']);
            $candidate = clone $record;
            $candidate->fill(['path' => $path, 'manifest' => $manifest, 'files' => $this->digests($path)]);
            try {
                [$plugin, $context] = $this->load($candidate);
                $plugin->upgrade($context, $record->installed_version);
                $record->update(['path' => $path, 'manifest' => $manifest, 'files' => $candidate->files, 'installed_version' => $manifest['version'], 'status' => 'inactive', 'last_error' => null]);
                $this->audit($actor, 'plugin.upgraded', $record->id, ['from' => $previous['installed_version'], 'previous_path' => $previous['path']]);
                return $record->fresh();
            } catch (\Throwable $error) {
                $record->update(['last_error' => 'Upgrade failed; previous package remains selected. Review migrations before retry.']);
                $this->report($record->id, $error, 'upgrade');
                $this->fail('Upgrade failed. Previous files remain selected; review migration effects before retrying.');
            }
        });
    }

    public function bootActive(): void
    {
        if ($this->booting || !Schema::hasTable('plugin_installations')) return;
        $this->booting = true;
        try {
            $records = PluginInstallation::where('status', 'active')->orderBy('id')->get()->keyBy('id')->all();
            $visiting = [];
            foreach ($records as $record) {
                if ($record->status !== 'active') continue;
                try { $this->bootRecord($record, $records, $visiting); }
                catch (\Throwable) { /* Each failed node and its dependents are disabled by bootRecord. */ }
            }
        } finally { $this->booting = false; }
    }

    public function activeIds(): array { return PluginInstallation::where('status', 'active')->pluck('id')->all(); }

    private function load(PluginInstallation $record): array
    {
        $digests = $this->digests($record->path);
        if ($digests !== $record->files) $this->fail('Plugin files changed after verification. Reinstall a verified release.');
        $php = array_filter($digests, fn ($name) => str_ends_with(strtolower($name), '.php'), ARRAY_FILTER_USE_KEY);
        $release = ['path' => realpath($record->path), 'php' => $php];
        // PHP classes cannot be unloaded. Fail before requiring a second release, including upgrades.
        if (isset(self::$processReleases[$record->id]) && self::$processReleases[$record->id] !== $release) {
            $this->fail('Different PHP code for this plugin is already loaded in this process. Retry in a fresh request or restart the worker before upgrading.');
        }
        $context = new PluginContext($record->id, $record->path, $record->manifest, $this->extensions);
        if (isset($this->loaded[$record->id])) return [$this->loaded[$record->id], $context];
        self::$processReleases[$record->id] = $release;
        $plugin = require $record->path.'/'.$record->manifest['entry'];
        if (!$plugin instanceof Plugin) $this->fail('Plugin entry must return a Plugin implementation.');
        $this->loaded[$record->id] = $plugin;
        return [$plugin, $context];
    }

    private function bootRecord(PluginInstallation $record, array $records, array &$visiting): void
    {
        if (isset($visiting[$record->id])) $this->fail('Circular plugin dependencies cannot be started.');
        if (isset($this->booted[$record->id])) return;
        $visiting[$record->id] = true;
        try {
            $this->compatible($record->manifest);
            $this->dependencies($record->manifest);
            foreach ($record->manifest['dependencies'] ?? [] as $id => $constraint) {
                $dependency = $records[$id] ?? null;
                if (!$dependency || $dependency->status !== 'active') $this->fail("Dependency {$id} failed to start.");
                $this->bootRecord($dependency, $records, $visiting);
            }
            [$plugin, $context] = $this->load($record);
            $plugin->register($context);
            $this->booted[$record->id] = true;
        } catch (\Throwable $error) {
            $this->extensions->forget($record->id);
            unset($this->booted[$record->id]);
            $record->update(['status' => 'error', 'last_error' => 'Plugin startup or dependency failed; plugin disabled.']);
            $this->report($record->id, $error, 'startup');
            throw $error;
        } finally {
            unset($visiting[$record->id]);
        }
    }

    private function assertNoActiveDependents(string $id): void
    {
        foreach (PluginInstallation::where('status', 'active')->get() as $other) {
            if ($other->id !== $id && isset($other->manifest['dependencies'][$id])) $this->fail('An active plugin depends on this plugin. Deactivate dependents first.');
        }
    }

    public function digests(string $path): array
    {
        if (!is_dir($path) || is_link($path)) $this->fail('Plugin directory is unavailable.');
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || !$file->isFile()) $this->fail('Plugin files must be ordinary files.');
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
            $files[$relative] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function compatible(array $manifest): void { if ($issues = $this->manifests->issues($manifest)) $this->fail(implode(' ', $issues)); }
    private function dependencies(array $manifest): void
    {
        foreach ($manifest['dependencies'] ?? [] as $id => $constraint) {
            $dependency = PluginInstallation::find($id);
            if (!$dependency || $dependency->status !== 'active' || !Semver::satisfies($dependency->installed_version, $constraint)) $this->fail("Activate compatible dependency {$id} first.");
        }
    }
    private function locked(callable $callback): mixed { return Cache::lock('fastfind:plugin-lifecycle', 300)->block(5, $callback); }
    private function authorize(int $actor): void { \Illuminate\Support\Facades\Gate::forUser(\App\Models\User::findOrFail($actor))->authorize('manage-plugins'); }
    public function audit(?int $actor, string $action, string $id, array $context = []): void { AuditEvent::create(['user_id' => $actor, 'action' => $action, 'subject' => 'plugin', 'context' => ['plugin_id' => $id, ...$context]]); }
    public function report(string $id, \Throwable $error, string $phase): void
    {
        Log::error('Plugin operation failed', ['plugin' => $id, 'phase' => $phase, 'exception' => $error]);
        $this->audit(null, 'plugin.failed', $id, ['phase' => $phase, 'exception' => get_class($error)]);
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['plugin' => $message]); }
}
