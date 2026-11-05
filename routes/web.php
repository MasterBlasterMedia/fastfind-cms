<?php

use App\Http\Controllers\PublicController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    Route::get('/site', [PublicController::class, 'site']);
    Route::get('/home', [PublicController::class, 'landing']);
    Route::get('/pages/{slug}', [PublicController::class, 'page'])->where('slug', '[a-z0-9-]+');
    Route::get('/search', [PublicController::class, 'search'])->middleware('throttle:40,1');
    Route::get('/session', [SessionController::class, 'show']);
    Route::post('/session', [SessionController::class, 'login'])->middleware('throttle:6,1');
    Route::delete('/session', [SessionController::class, 'logout'])->middleware('auth');
    Route::post('/password/forgot', [\App\Http\Controllers\PasswordRecoveryController::class, 'forgot'])->middleware('throttle:password-recovery');
    Route::post('/password/reset', [\App\Http\Controllers\PasswordRecoveryController::class, 'reset'])->middleware('throttle:password-reset');
    Route::get('/preview/{page}', [PublicController::class, 'preview'])->middleware('signed')->name('pages.preview');

    Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/plugins/{vendor}/{name}/{path?}', [\App\Http\Controllers\PluginController::class, 'dispatch'])
        ->where('path', '.*')->middleware(['auth', \App\Http\Middleware\EnsureActive::class]);

    Route::middleware(['auth', \App\Http\Middleware\EnsureActive::class])->prefix('studio')->group(function () {
        Route::get('/content-schema', [StudioController::class, 'contentSchema']);
        Route::get('/extensions', [\App\Http\Controllers\PluginController::class, 'extensions']);
        Route::get('/plugins', [\App\Http\Controllers\PluginController::class, 'index']);
        Route::get('/plugin-catalog', [\App\Http\Controllers\PluginController::class, 'catalog']);
        Route::get('/marketplace', [\App\Http\Controllers\MarketplaceController::class, 'index']);
        Route::post('/marketplace/install', [\App\Http\Controllers\MarketplaceController::class, 'install'])->middleware('throttle:5,1');
        Route::post('/plugins/packages', [\App\Http\Controllers\PluginController::class, 'upload'])->middleware('throttle:5,1');
        Route::match(['GET', 'PUT'], '/plugins/{vendor}/{name}/settings', [\App\Http\Controllers\PluginController::class, 'settings']);
        Route::post('/plugins/{vendor}/{name}/{action}', [\App\Http\Controllers\PluginController::class, 'lifecycle'])->where('action', 'install|activate|deactivate|upgrade|uninstall');
        Route::get('/dashboard', [StudioController::class, 'dashboard']);
        Route::get('/health', [StudioController::class, 'health']);
        Route::get('/pages', [StudioController::class, 'pages']);
        Route::post('/pages', [StudioController::class, 'create']);
        Route::get('/pages/{page}', [StudioController::class, 'show']);
        Route::put('/pages/{page}', [StudioController::class, 'update']);
        Route::post('/pages/{page}/publish', [StudioController::class, 'publish']);
        Route::post('/pages/{page}/unpublish', [StudioController::class, 'unpublish']);
        Route::delete('/pages/{page}', [StudioController::class, 'delete']);
        Route::get('/pages/{page}/revisions', [StudioController::class, 'revisions']);
        Route::post('/pages/{page}/restore', [StudioController::class, 'restore']);
        Route::post('/pages/{page}/schedule', [StudioController::class, 'schedule']);
        Route::post('/pages/{page}/review', [StudioController::class, 'review']);
        Route::post('/pages/{page}/archive', [StudioController::class, 'archive']);
        Route::post('/pages/{page}/preview-link', [StudioController::class, 'previewLink']);
        Route::get('/pages/{page}/revisions/{revisionVersion}', [StudioController::class, 'revision']);
        Route::get('/navigation', [StudioController::class, 'navigation']);
        Route::put('/navigation', [StudioController::class, 'saveNavigation']);
        Route::get('/media', [StudioController::class, 'media']);
        Route::post('/media', [StudioController::class, 'upload'])->middleware('throttle:30,1');
        Route::delete('/media/{asset}', [StudioController::class, 'deleteMedia']);
        Route::get('/settings', [StudioController::class, 'settings']);
        Route::get('/themes', [StudioController::class, 'themes']);
        Route::post('/themes/packages', [\App\Http\Controllers\ThemePackageController::class, 'upload'])->middleware('throttle:5,1');
        Route::put('/themes', [StudioController::class, 'saveTheme']);
        Route::put('/settings', [StudioController::class, 'saveSettings']);
        Route::get('/users', [StudioController::class, 'users']);
        Route::post('/users', [StudioController::class, 'createUser']);
        Route::patch('/users/{user}', [StudioController::class, 'updateUser']);
    });
});

Route::get('/p/{slug}', function (string $slug) {
    $page = \App\Models\Page::where('status', 'published')->where('published_slug', $slug)->firstOrFail();
    $snapshot = $page->publishedView();
    $head = \App\Plugins\HeadMetadata::normalize(app(\App\Plugins\ExtensionRegistry::class)->headFor($snapshot));
    return view('app', ['head' => $head, 'seo' => [
        'title' => (($snapshot['seo_title'] ?? null) ?: ($snapshot['title'] ?? $page->title)).' | FastFind',
        'description' => ($snapshot['seo_description'] ?? null) ?: ($snapshot['summary'] ?? ''),
    ]]);
})->where('slug', '[a-z0-9-]+');

Route::get('/{path?}', function (string $path = '') {
    abort_unless($path === '' || $path === 'search' || $path === 'studio' || str_starts_with($path, 'studio/'), 404);
    return view('app');
})->where('path', '^(?!api/|storage/|build/).*$');
