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
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function ($user, string $token): string {
            $base = rtrim((string) config('app.url'), '/');
            $parts = parse_url($base);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new \LogicException('Configure a valid trusted APP_URL for password recovery.');
            }
            return $base.'/studio/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);
        });
        \Illuminate\Support\Facades\RateLimiter::for('password-recovery', fn (\Illuminate\Http\Request $request) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(6)->by('recovery-ip:'.$request->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perHour(5)->by('recovery-email:'.hash('sha256', strtolower(is_string($request->input('email')) ? $request->input('email') : ''))),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('password-reset', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('reset-ip:'.$request->ip()));
        Gate::define('view-content', fn ($user) => $user->is_active);
        Gate::define('manage-plugins', fn ($user) => $user->is_active && $user->role === 'owner');
        Gate::define('edit-content', fn ($user) => $user->is_active && in_array($user->role, ['owner', 'admin', 'editor'], true));
        Gate::define('manage-site', fn ($user) => $user->is_active && in_array($user->role, ['owner', 'admin'], true));
        Gate::define('manage-users', fn ($user) => $user->is_active && $user->role === 'owner');
    }
}
