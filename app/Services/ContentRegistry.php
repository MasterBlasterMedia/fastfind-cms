<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Shared, declarative content definitions with server-side validators for extensions. */
final class ContentRegistry
{
    private array $blocks = [];
    private array $layouts = [];
    private array $validators = [];

    public function __construct()
    {
        $catalog = json_decode(file_get_contents(resource_path('content/registry.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($catalog['blocks'] as $definition) {
            $this->registerBlock($definition['id'], $definition, fn (array $props) => $props);
        }
        foreach ($catalog['layouts'] as $definition) $this->registerLayout($definition['id'], $definition);
    }

    public function registerBlock(string $id, array $definition, callable $validator): void
    {
        $this->validateId($id);
        if (isset($this->blocks[$id])) throw new InvalidArgumentException("Block {$id} is already registered.");
        $this->blocks[$id] = [...$definition, 'id' => $id];
        $this->validators[$id] = $validator;
    }

    public function registerLayout(string $id, array $definition): void
    {
        $this->validateId($id);
        if (isset($this->layouts[$id])) throw new InvalidArgumentException("Layout {$id} is already registered.");
        $this->layouts[$id] = [...$definition, 'id' => $id];
    }

    public function block(string $id): ?array { return $this->blocks[$id] ?? null; }
    public function layout(string $id): ?array { return $this->layouts[$id] ?? null; }
    public function blocks(): array { return array_values($this->blocks); }
    public function layouts(): array { return array_values($this->layouts); }
    public function catalog(): array { return ['blocks' => $this->blocks(), 'layouts' => $this->layouts()]; }

    public function forgetPlugin(string $id): void
    {
        foreach (['blocks', 'layouts', 'validators'] as $registry) {
            foreach (array_keys($this->{$registry}) as $key) {
                if (str_starts_with($key, $id.'/')) unset($this->{$registry}[$key]);
            }
        }
    }

    public function validateProps(string $id, array $props): array
    {
        $definition = $this->block($id);
        if (!$definition) $this->fail('Unsupported block type.');
        $fields = array_column($definition['fields'] ?? [], null, 'key');
        if (array_diff(array_keys($props), array_keys($fields))) $this->fail("Unsupported property for {$id}.");
        $clean = [];
        foreach ($fields as $key => $field) {
            $value = $props[$key] ?? $definition['defaults'][$key] ?? null;
            if ($value === null || $value === '') {
                if ($field['required'] ?? false) $this->fail("The {$id} {$key} field is required.");
                if ($value !== null) $clean[$key] = $value;
                continue;
            }
            switch ($field['type'] ?? 'text') {
                case 'number':
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                        || $value < ($field['min'] ?? -1000000) || $value > ($field['max'] ?? 1000000)) {
                        $this->fail("The {$id} {$key} field must be a number in the supported range.");
                    }
                    break;
                case 'boolean':
                    if (!is_bool($value)) $this->fail("The {$id} {$key} field must be true or false.");
                    break;
                case 'select':
                    $values = array_map(fn ($option) => is_array($option) ? $option['value'] : $option, $field['options'] ?? []);
                    if (!in_array($value, $values, true)) $this->fail("Unsupported {$id} {$key} option.");
                    break;
                case 'url':
                case 'image':
                    if (!SafeUrl::valid($value)) $this->fail("The {$id} {$key} field must be a safe HTTP, HTTPS or local URL.");
                    break;
                case 'text':
                case 'textarea':
                    if (!is_string($value) || mb_strlen($value) > min(5000, $field['max'] ?? 5000)) {
                        $this->fail("The {$id} {$key} field must be text within its character limit.");
                    }
                    break;
                default:
                    $this->fail("Unsupported field type in {$id}.");
            }
            $this->validateFormat($field['format'] ?? null, $value);
            $clean[$key] = $value;
        }
        $validated = ($this->validators[$id])($clean);
        if (!is_array($validated)) throw new InvalidArgumentException("The {$id} validator must return an array.");
        return $validated;
    }

    private function validateId(string $id): void
    {
        if (!preg_match('~^[a-z][a-z0-9_.:/-]{0,99}$~', $id)) throw new InvalidArgumentException('Invalid content type identifier.');
    }

    private function validateFormat(?string $format, mixed $value): void
    {
        if (!$format) return;
        if (!is_string($value)) $this->fail('Formatted content must be text.');
        if ($format === 'video') {
            $parts = parse_url($value);
            $host = strtolower($parts['host'] ?? '');
            $path = $parts['path'] ?? '';
            parse_str($parts['query'] ?? '', $query);
            $videoId = $query['v'] ?? '';
            $valid = ($host === 'youtu.be' && preg_match('~^/[A-Za-z0-9_-]{11}$~', $path))
                || (in_array($host, ['youtube.com', 'www.youtube.com'], true) && $path === '/watch' && is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId))
                || (in_array($host, ['youtube-nocookie.com', 'www.youtube-nocookie.com'], true) && preg_match('~^/embed/[A-Za-z0-9_-]{11}$~', $path))
                || (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('~^/[0-9]+$~', $path));
            if (!$valid || ($parts['scheme'] ?? '') !== 'https') $this->fail('Video embeds require an HTTPS YouTube or Vimeo video URL.');
            return;
        }
        $urlColumn = match ($format) { 'image-list' => 0, 'link-list' => 1, 'card-list' => 2, default => null };
        if ($urlColumn === null) return;
        $rows = preg_split('/\R/', trim($value));
        if (count($rows) > 40) $this->fail('A collection may contain at most 40 items.');
        foreach ($rows as $row) {
            if (trim($row) === '') continue;
            $columns = array_map('trim', explode('|', $row));
            $url = $columns[$urlColumn] ?? '';
            if ($format === 'card-list' && $url === '') continue;
            if (!SafeUrl::valid($url)) $this->fail('A collection contains an unsafe or missing URL.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
