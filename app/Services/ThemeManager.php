<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Themes\ThemeManifest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Themes are reviewed data manifests; the public renderer interprets only safe design tokens. */
final class ThemeManager
{
    public function __construct(private ThemeManifest $manifests) {}

    public function bundled(): array
    {
        $themes = [];
        foreach (glob(config('themes.bundled_path').'/*/theme.json') ?: [] as $manifest) {
            try { $theme = $this->manifests->read($manifest); }
            catch (\Throwable) { continue; }
            if ($this->manifests->issues($theme) || in_array($theme['id'], array_column($themes, 'id'), true)) continue;
            $themes[] = [...$theme, 'source' => 'bundled', 'publisher' => null, 'installed' => true];
        }
        return $themes;
    }

    public function all(): array
    {
        $themes = $this->bundled();
        $bundled = array_column($themes, 'id');
        $packages = SiteSetting::find('theme_packages')?->value;
        foreach (is_array($packages) ? $packages : [] as $id => $record) {
            if (in_array($id, $bundled, true)) continue;
            if (!is_array($record) || !is_array($record['manifest'] ?? null) || !is_string($record['publisher'] ?? null)) continue;
            try { $theme = $this->manifests->validate($record['manifest']); }
            catch (\Throwable) { continue; }
            if ($theme['id'] !== $id || $this->manifests->issues($theme)) continue;
            $themes[] = [...$theme, 'source' => 'package', 'publisher' => $record['publisher'], 'installed' => true];
        }
        return $themes;
    }

    public function current(): array
    {
        $selection = SiteSetting::find('theme')?->value;
        if (!is_array($selection)) $selection = [];
        $id = is_string($selection['id'] ?? null) ? $selection['id'] : 'professional';
        $themes = collect($this->all());
        $theme = $themes->firstWhere('id', $id) ?? $themes->firstWhere('id', 'professional') ?? $themes->first();
        // Keep public pages readable even if every installed manifest has become unavailable.
        $theme ??= [...$this->manifests->validate([
            'id' => 'professional', 'name' => 'Default theme', 'version' => '1.0.0',
            'description' => 'Safe default design.', 'layout' => 'editorial', 'defaults' => ThemeManifest::DEFAULTS,
        ]), 'source' => 'bundled', 'publisher' => null, 'installed' => true];
        $overrides = $theme['id'] === $id && is_array($selection['settings'] ?? null) ? $selection['settings'] : [];
        // Preserve earlier three-token selections while filling the public contract's new defaults.
        try { $settings = $this->manifests->settings($theme['defaults'], $overrides); }
        catch (ValidationException) { $settings = $theme['defaults']; }
        return ['active' => $theme['id'], 'settings' => $settings, 'theme' => $theme];
    }

    public function activate(string $id, array $settings, int $userId): array
    {
        Gate::forUser(User::findOrFail($userId))->authorize('manage-site');
        // The same lock protects installation checks, so an active theme cannot be upgraded mid-selection.
        return Cache::lock('fastfind:theme-state', 30)->block(5, function () use ($id, $settings, $userId) {
            $theme = collect($this->all())->firstWhere('id', $id);
            if (!$theme) throw ValidationException::withMessages(['id' => 'Unknown or incompatible theme.']);
            $settings = $this->manifests->settings($theme['defaults'], $settings);
            DB::transaction(function () use ($id, $settings, $userId) {
                SiteSetting::updateOrCreate(['key' => 'theme'], ['value' => ['id' => $id, 'settings' => $settings]]);
                AuditEvent::create(['user_id' => $userId, 'action' => 'theme.activated', 'subject' => 'theme', 'context' => ['id' => $id]]);
            });
            return $this->current();
        });
    }
}
