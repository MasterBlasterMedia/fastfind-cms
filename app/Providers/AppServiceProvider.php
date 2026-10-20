<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::define('edit-content', fn ($user) => in_array($user->role, ['owner', 'admin', 'editor'], true));
        Gate::define('manage-site', fn ($user) => in_array($user->role, ['owner', 'admin'], true));
        Gate::define('manage-users', fn ($user) => $user->role === 'owner');
    }
}
