<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $development = app()->environment('local') && is_file(public_path('hot'));
        $scripts = $development ? "'self' http://127.0.0.1:5173" : "'self'";
        $connections = $development ? "'self' http://127.0.0.1:5173 ws://127.0.0.1:5173" : "'self'";
        $response->headers->set('Content-Security-Policy', "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; img-src 'self' https: data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src {$scripts}; connect-src {$connections}; frame-src https://www.youtube-nocookie.com https://player.vimeo.com");
        if ($request->is('studio', 'studio/*', 'api/v1/studio/*', 'api/v1/session', 'api/v1/plugins/*', 'api/v1/preview/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        return $response;
    }
}
