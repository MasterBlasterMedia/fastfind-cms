<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

final class AuthenticateSession extends LaravelAuthenticateSession
{
    public function handle($request, Closure $next)
    {
        $guard = $this->auth->guard();
        // Persisted sessions from older releases must sign in once to acquire a marker.
        // In-memory actingAs users have no persisted login key and use Laravel's normal path.
        if ($request->hasSession() && $request->session()->has($guard->getName())
            && !$request->session()->has('password_hash_'.$this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
