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

    Route::middleware(['auth', \App\Http\Middleware\EnsureActive::class])->prefix('studio')->group(function () {
        Route::get('/dashboard', [StudioController::class, 'dashboard']);
        Route::get('/pages', [StudioController::class, 'pages']);
        Route::post('/pages', [StudioController::class, 'create']);
        Route::get('/pages/{page}', [StudioController::class, 'show']);
        Route::put('/pages/{page}', [StudioController::class, 'update']);
        Route::post('/pages/{page}/publish', [StudioController::class, 'publish']);
        Route::post('/pages/{page}/unpublish', [StudioController::class, 'unpublish']);
        Route::delete('/pages/{page}', [StudioController::class, 'delete']);
        Route::get('/pages/{page}/revisions', [StudioController::class, 'revisions']);
        Route::post('/pages/{page}/restore', [StudioController::class, 'restore']);
        Route::get('/navigation', [StudioController::class, 'navigation']);
        Route::put('/navigation', [StudioController::class, 'saveNavigation']);
        Route::get('/media', [StudioController::class, 'media']);
        Route::post('/media', [StudioController::class, 'upload'])->middleware('throttle:30,1');
        Route::delete('/media/{asset}', [StudioController::class, 'deleteMedia']);
        Route::get('/settings', [StudioController::class, 'settings']);
        Route::put('/settings', [StudioController::class, 'saveSettings']);
        Route::get('/users', [StudioController::class, 'users']);
        Route::post('/users', [StudioController::class, 'createUser']);
        Route::patch('/users/{user}', [StudioController::class, 'updateUser']);
    });
});

Route::get('/p/{slug}', function (string $slug) {
    $page = \App\Models\Page::where('status', 'published')->where('published_slug', $slug)->firstOrFail();
    $snapshot = $page->publishedView();
    return view('app', ['seo' => [
        'title' => (($snapshot['seo_title'] ?? null) ?: ($snapshot['title'] ?? $page->title)).' | FastFind',
        'description' => ($snapshot['seo_description'] ?? null) ?: ($snapshot['summary'] ?? ''),
    ]]);
})->where('slug', '[a-z0-9-]+');

Route::get('/{path?}', fn () => view('app'))->where('path', '^(?!api/|storage/|build/).*$');
