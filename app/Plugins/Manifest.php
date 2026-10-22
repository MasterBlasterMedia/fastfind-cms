<?php

namespace App\Plugins;

use Composer\Semver\Semver;
use Illuminate\Validation\ValidationException;

final class Manifest
{
    public const CAPABILITIES = ['pages.read', 'pages.write', 'settings.manage', 'public.routes', 'content.extend', 'events.listen', 'schedule.run'];

    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 65536) $this->fail('A bounded plugin.json manifest is required.');
        try { $manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->fail('The plugin manifest is not valid JSON.'); }
        if (!is_array($manifest)) $this->fail('The plugin manifest must be an object.');
        return $this->validate($manifest);
    }

    public function validate(array $manifest): array
    {
        if (!is_string($manifest['id'] ?? null) || !preg_match('~^[a-z][a-z0-9-]{1,39}/[a-z][a-z0-9-]{1,59}$~D', $manifest['id'])) $this->fail('Plugin IDs use vendor/name.');
        foreach (['name' => 100, 'description' => 1000, 'version' => 50, 'entry' => 180] as $field => $max) {
            if (!is_string($manifest[$field] ?? null) || $manifest[$field] === '' || strlen($manifest[$field]) > $max) $this->fail("Invalid manifest {$field}.");
        }
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D', $manifest['version'])) $this->fail('A semantic plugin version is required.');
        if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\.php$~D', $manifest['entry'])) $this->fail('The plugin entry must be a relative PHP file.');
        if (!is_array($manifest['permissions'] ?? null) || !array_is_list($manifest['permissions'])) $this->fail('Unknown plugin capabilities.');
        foreach ($manifest['permissions'] as $permission) if (!is_string($permission) || !in_array($permission, self::CAPABILITIES, true)) $this->fail('Unknown plugin capabilities.');
        foreach (['fastfind', 'php'] as $key) {
            if (!is_string($manifest['requires'][$key] ?? null)) $this->fail("Missing {$key} requirement.");
            try { Semver::satisfies('1.0.0', $manifest['requires'][$key]); }
            catch (\Throwable) { $this->fail("Invalid {$key} version constraint."); }
        }
        $dependencies = $manifest['dependencies'] ?? [];
        if (!is_array($dependencies) || count($dependencies) > 30) $this->fail('Invalid plugin dependencies.');
        foreach ($dependencies as $id => $constraint) {
            if (!is_string($id) || !preg_match('~^[a-z][a-z0-9-]{1,39}/[a-z][a-z0-9-]{1,59}$~D', $id) || $id === $manifest['id'] || !is_string($constraint)) $this->fail('Invalid plugin dependency.');
            try { Semver::satisfies('1.0.0', $constraint); } catch (\Throwable) { $this->fail('Invalid dependency constraint.'); }
        }
        return [...$manifest, 'dependencies' => $dependencies];
    }

    public function issues(array $manifest): array
    {
        $issues = [];
        if (!Semver::satisfies(config('plugins.platform_version'), $manifest['requires']['fastfind'])) $issues[] = 'Incompatible FastFind version.';
        if (!Semver::satisfies(PHP_VERSION, $manifest['requires']['php'])) $issues[] = 'Incompatible PHP version.';
        return $issues;
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['plugin' => $message]); }
}
