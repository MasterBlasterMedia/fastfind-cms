<?php

namespace App\Plugins;

use App\Plugins\Contracts\Plugin;

abstract class AbstractPlugin implements Plugin
{
    public function activate(PluginContext $context): void {}
    public function deactivate(PluginContext $context): void {}
    public function upgrade(PluginContext $context, string $fromVersion): void {}
    public function uninstall(PluginContext $context, bool $purge): void {}
}
