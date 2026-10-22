<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(\App\Plugins\ExtensionRegistry::class);
        $this->app->singleton(\App\Plugins\PluginManager::class);
        $this->app->singleton(\App\Services\ContentRegistry::class);
    }

    public function boot(): void
    {
        Gate::define('view-content', fn ($user) => $user->is_active);
        Gate::define('manage-plugins', fn ($user) => $user->is_active && $user->role === 'owner');
        Gate::define('edit-content', fn ($user) => $user->is_active && in_array($user->role, ['owner', 'admin', 'editor'], true));
        Gate::define('manage-site', fn ($user) => $user->is_active && in_array($user->role, ['owner', 'admin'], true));
        Gate::define('manage-users', fn ($user) => $user->is_active && $user->role === 'owner');
    }
}
