<?php

namespace App\Http\Middleware;

use App\Plugins\ExtensionRegistry;
use App\Plugins\PluginManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PluginExtensions
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app(PluginManager::class);
        $manager->bootActive();
        if ($request->isMethod('GET') && !preg_match('~^/(api|studio|storage|build|up)(/|$)~', $request->getPathInfo())) {
            $extensions = app(ExtensionRegistry::class);
            foreach ($extensions->publicRoutes() as $id => $routes) {
                if (isset($routes[$request->getPathInfo()])) {
                    try { return $routes[$request->getPathInfo()]($request); }
                    catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { throw $error; }
                    catch (\Throwable $error) { $manager->report($id, $error, 'public-route'); abort(503, 'This extension is temporarily unavailable.'); }
                }
            }
            foreach ($extensions->responses() as $id => $handler) {
                try { if ($response = $handler($request)) return $response; }
                catch (\Throwable $error) { $manager->report($id, $error, 'public-response'); }
            }
        }
        return $next($request);
    }
}
