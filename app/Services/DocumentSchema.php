<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class DocumentSchema
{
    public const LAYOUTS = ['stack', 'two-columns', 'three-columns', 'grid', 'split', 'hero', 'feature'];
    public const BLOCKS = ['heading', 'text', 'image', 'button', 'quote', 'callout', 'divider', 'list', 'stat', 'faq'];

    public static function starter(): array
    {
        return ['sections' => [[
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'layout' => 'stack',
            'settings' => ['background' => 'plain', 'width' => 'normal'],
            'blocks' => [[
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'heading',
                'props' => ['text' => 'Your next great page', 'level' => 1],
            ]],
        ]]];
    }

    public function validate(array $document): array
    {
        $sections = $document['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || count($sections) > 40) {
            throw ValidationException::withMessages(['document' => 'A page must have an array of at most 40 sections.']);
        }
        $ids = [];
        $valid = [];
        $count = 0;
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $section['id']) || isset($ids[$section['id']])) {
                throw ValidationException::withMessages(['document' => 'Every section requires a unique, valid ID.']);
            }
            $ids[$section['id']] = true;
            $layout = $section['layout'] ?? 'stack';
            if (!in_array($layout, self::LAYOUTS, true)) {
                throw ValidationException::withMessages(['document' => 'Unsupported section layout.']);
            }
            $blocks = $section['blocks'] ?? [];
            if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > 80) {
                throw ValidationException::withMessages(['document' => 'Each section may contain at most 80 blocks.']);
            }
            $settings = $section['settings'] ?? [];
            if (!is_array($settings)) $settings = [];
            $cleanBlocks = [];
            foreach ($blocks as $block) {
                $count++;
                if ($count > 300) throw ValidationException::withMessages(['document' => 'Page exceeds the 300-block limit.']);
                if (!is_array($block) || !is_string($block['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $block['id']) || isset($ids[$block['id']]) || !in_array($block['type'] ?? null, self::BLOCKS, true)) {
                    throw ValidationException::withMessages(['document' => 'A block has an invalid ID or type.']);
                }
                $ids[$block['id']] = true;
                $props = $block['props'] ?? [];
                if (!is_array($props)) throw ValidationException::withMessages(['document' => 'Block props must be an object.']);
                if (strlen(json_encode($props) ?: '') > 16000) throw ValidationException::withMessages(['document' => 'Block content is too large.']);
                foreach ($props as $key => $value) {
                    if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9_]{0,39}$/', $key) || !(is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_null($value))) {
                        throw ValidationException::withMessages(['document' => 'Block properties must be simple values.']);
                    }
                    if (is_string($value) && mb_strlen($value) > 5000) throw ValidationException::withMessages(['document' => 'Text exceeds the 5,000-character limit.']);
                }
                $this->checkUrl($props['url'] ?? null);
                $this->checkUrl($props['src'] ?? null);
                $cleanBlocks[] = ['id' => $block['id'], 'type' => $block['type'], 'props' => $props];
            }
            $background = $settings['background'] ?? 'plain';
            $width = $settings['width'] ?? 'normal';
            if (!in_array($background, ['plain', 'soft', 'dark', 'accent'], true) || !in_array($width, ['normal', 'wide'], true)) {
                throw ValidationException::withMessages(['document' => 'Invalid section styling.']);
            }
            $valid[] = ['id' => $section['id'], 'layout' => $layout, 'settings' => ['background' => $background, 'width' => $width], 'blocks' => $cleanBlocks];
        }
        return ['sections' => $valid];
    }

    private function checkUrl(mixed $url): void
    {
        if ($url === null || $url === '') return;
        if (!is_string($url) || mb_strlen($url) > 1500 || (!str_starts_with($url, '/') && !str_starts_with($url, '#') && !preg_match('~^https?://[^\s<>"\']+$~i', $url)) || str_starts_with($url, '//')) {
            throw ValidationException::withMessages(['document' => 'Only root-relative, anchor, HTTPS or HTTP links are allowed.']);
        }
    }
}
