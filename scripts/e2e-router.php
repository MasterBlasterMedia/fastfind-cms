<?php

// Test server only. Never follow the development public/storage symlink.
$storageRoot = getenv('LARAVEL_STORAGE_PATH');
if (getenv('APP_ENV') !== 'testing' || !$storageRoot) {
    http_response_code(500);
    exit('The isolated browser router requires a testing environment.');
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($path) ? rawurldecode($path) : '';
if ($uri === '' || preg_match('/[\\\\\x00-\x1f\x7f]/', $uri)) {
    http_response_code(400);
    exit;
}
$within = static function (string $file, string $directory): bool {
    $prefix = rtrim(str_replace('\\', '/', $directory), '/').'/';
    $candidate = str_replace('\\', '/', $file);
    return DIRECTORY_SEPARATOR === '\\'
        ? str_starts_with(strtolower($candidate), strtolower($prefix))
        : str_starts_with($candidate, $prefix);
};
if ($uri === '/storage' || str_starts_with($uri, '/storage/')) {
    $mediaRoot = realpath($storageRoot.'/app/public');
    $file = $mediaRoot ? realpath($mediaRoot.'/'.substr($uri, strlen('/storage/'))) : false;
    if (!$file || !$mediaRoot || !$within($file, $mediaRoot) || !is_file($file)) {
        http_response_code(404);
        exit;
    }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    header('Content-Type: '.(in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true) ? $mime : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { readfile($file); }
    exit;
}
$publicRoot = realpath(__DIR__.'/../public');
$asset = $publicRoot ? realpath($publicRoot.$uri) : false;
if ($asset && $within($asset, $publicRoot) && is_file($asset) && strtolower(pathinfo($asset, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
// With variables_order=GPCS, the HTTP SAPI omits process variables from $_ENV/$_SERVER.
// Bind the same explicit root used above before Laravel loads filesystem configuration.
$app->useStoragePath($storageRoot);
$app->handleRequest(\Illuminate\Http\Request::capture());
