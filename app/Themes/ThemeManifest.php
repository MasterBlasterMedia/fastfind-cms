<?php

namespace App\Themes;

use Composer\Semver\Semver;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public data contract shared by shipped themes, ZIP packages and catalogs. */
final class ThemeManifest
{
    public const DEFAULTS = [
        'accent' => '#3159d9', 'font' => 'sans', 'width' => 'wide',
        'background' => '#f8f9f6', 'surface' => '#ffffff', 'text' => '#172431', 'muted' => '#52665c',
        'radius' => 'soft', 'spacing' => 'normal',
    ];

    public function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > 65536) $this->fail('A bounded theme.json manifest is required.');
        try { $manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->fail('The theme manifest is not valid JSON.'); }
        if (!is_array($manifest)) $this->fail('The theme manifest must be an object.');
        return $this->validate($manifest);
    }

    public function validate(array $manifest): array
    {
        if (array_diff(array_keys($manifest), ['id', 'name', 'version', 'description', 'layout', 'requires', 'defaults'])) $this->fail('Unknown theme manifest fields.');
        if (!is_string($manifest['id'] ?? null) || !preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $manifest['id'])) $this->fail('Theme IDs must be lowercase slugs.');
        foreach (['name' => 100, 'description' => 1000, 'version' => 50] as $field => $maximum) {
            if (!is_string($manifest[$field] ?? null) || trim($manifest[$field]) === '' || strlen($manifest[$field]) > $maximum) $this->fail("Invalid theme {$field}.");
        }
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D', $manifest['version'])) $this->fail('A semantic theme version is required.');
        $layout = $manifest['layout'] ?? 'editorial';
        if (!in_array($layout, ['editorial', 'minimal'], true)) $this->fail('Unknown theme layout.');
        $requires = $manifest['requires'] ?? ['fastfind' => '^1.0'];
        if (!is_array($requires) || array_diff(array_keys($requires), ['fastfind']) || !is_string($requires['fastfind'] ?? null) || strlen($requires['fastfind']) > 100) $this->fail('Invalid FastFind theme requirement.');
        try { Semver::satisfies('1.0.0', $requires['fastfind']); }
        catch (\Throwable) { $this->fail('Invalid FastFind theme version constraint.'); }
        if (!is_array($manifest['defaults'] ?? null)) $this->fail('Theme defaults must be an object.');
        return [...$manifest, 'layout' => $layout, 'requires' => $requires, 'defaults' => $this->settings($manifest['defaults'])];
    }

    public function issues(array $manifest): array
    {
        return Semver::satisfies((string) config('plugins.platform_version', '1.0.0'), $manifest['requires']['fastfind'])
            ? [] : ['Incompatible FastFind version.'];
    }

    public function settings(array $defaults, array $overrides = []): array
    {
        if (array_diff(array_keys($defaults), array_keys(self::DEFAULTS)) || array_diff(array_keys($overrides), array_keys(self::DEFAULTS))) $this->fail('Unsupported theme setting.');
        $rules = [];
        foreach (['accent', 'background', 'surface', 'text', 'muted'] as $color) $rules[$color] = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'];
        $rules += [
            'font' => ['required', 'string', Rule::in(['sans', 'serif'])],
            'width' => ['required', 'string', Rule::in(['normal', 'wide'])],
            'radius' => ['required', 'string', Rule::in(['square', 'soft', 'rounded'])],
            'spacing' => ['required', 'string', Rule::in(['compact', 'normal', 'relaxed'])],
        ];
        return Validator::make([...self::DEFAULTS, ...$defaults, ...$overrides], $rules)->validate();
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['theme' => $message]); }
}
