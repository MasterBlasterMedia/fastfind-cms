<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DocumentSchema
{
    public function __construct(private readonly ContentRegistry $registry) {}

    public static function starter(): array
    {
        return ['sections' => [[
            'id' => (string) Str::uuid(), 'layout' => 'stack',
            'settings' => ['background' => 'plain', 'width' => 'normal'],
            'blocks' => [['id' => (string) Str::uuid(), 'type' => 'heading', 'props' => ['text' => 'Your next great page', 'level' => 1]]],
        ]]];
    }

    public function validate(array $document): array
    {
        $sections = $document['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections) || count($sections) > 40 || array_diff(array_keys($document), ['sections'])) {
            $this->fail('A page must contain only an array of at most 40 sections.');
        }
        $ids = [];
        $valid = [];
        $count = 0;
        foreach ($sections as $section) {
            if (!is_array($section)) $this->fail('Each section must be an object.');
            $this->validateId($section['id'] ?? null, $ids);
            $layoutId = $section['layout'] ?? 'stack';
            if (!is_string($layoutId) || !($layout = $this->registry->layout($layoutId))) $this->fail('Unsupported section layout.');
            $blocks = $section['blocks'] ?? [];
            if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > min(80, $layout['maxBlocks'] ?? 80)
                || count($blocks) < ($layout['minBlocks'] ?? 0)) {
                $this->fail('The section contains an unsupported number of blocks.');
            }
            $settings = $section['settings'] ?? [];
            if (!is_array($settings)) $this->fail('Section settings must be an object.');
            $allowedSettings = [
                'background' => ['plain', 'soft', 'dark', 'accent'], 'width' => ['normal', 'wide'],
                'spacing' => ['normal', 'compact', 'relaxed'], 'align' => ['left', 'center', 'right'],
            ];
            if (array_diff(array_keys($settings), array_keys($allowedSettings))) $this->fail('Unsupported section setting.');
            $cleanSettings = [];
            foreach ($allowedSettings as $key => $options) {
                $value = $settings[$key] ?? $layout['defaultSettings'][$key] ?? $options[0];
                if (!in_array($value, $options, true)) $this->fail('Invalid section styling.');
                $cleanSettings[$key] = $value;
            }
            $cleanBlocks = [];
            foreach ($blocks as $block) {
                if (++$count > 300) $this->fail('Page exceeds the 300-block limit.');
                if (!is_array($block)) $this->fail('Each block must be an object.');
                $this->validateId($block['id'] ?? null, $ids);
                $type = $block['type'] ?? null;
                if (!is_string($type) || !$this->registry->block($type)) $this->fail('Unsupported block type.');
                if (!empty($layout['allowedBlocks']) && !in_array($type, $layout['allowedBlocks'], true)) {
                    $this->fail("The {$type} block is not supported by the {$layoutId} layout.");
                }
                $props = $block['props'] ?? [];
                if (!is_array($props) || (array_is_list($props) && $props !== []) || strlen(json_encode($props, JSON_THROW_ON_ERROR)) > 16000) {
                    $this->fail('Block props must be an object of at most 16,000 bytes.');
                }
                foreach ($props as $key => $value) {
                    if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9_]{0,39}$/', $key)
                        || !(is_scalar($value) || is_null($value)) || (is_string($value) && mb_strlen($value) > 5000)) {
                        $this->fail('Block properties must be bounded simple values.');
                    }
                }
                $cleanBlocks[] = ['id' => $block['id'], 'type' => $type, 'props' => $this->registry->validateProps($type, $props)];
            }
            $valid[] = ['id' => $section['id'], 'layout' => $layoutId, 'settings' => $cleanSettings, 'blocks' => $cleanBlocks];
        }
        return ['sections' => $valid];
    }

    private function validateId(mixed $id, array &$ids): void
    {
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id) || isset($ids[$id])) {
            $this->fail('Every section and block requires a unique, valid ID.');
        }
        $ids[$id] = true;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
