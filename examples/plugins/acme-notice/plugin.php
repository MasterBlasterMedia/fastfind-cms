<?php

return new class extends \App\Plugins\AbstractPlugin {
    public function register(\App\Plugins\PluginContext $context): void
    {
        $definition = json_decode(file_get_contents(__DIR__.'/block.json'), true, 32, JSON_THROW_ON_ERROR);
        $context->block('notice', $definition, fn (array $values) => \Illuminate\Support\Facades\Validator::make($values, ['text' => 'required|string|max:200'])->validate());
    }
};
