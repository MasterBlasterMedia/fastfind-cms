<?php

// Browser fixtures only. Never reset or reuse a development or production database.
$normalize = static fn (string $path): string => strtolower(str_replace('\\', '/', $path));
$storageRoot = getenv('LARAVEL_STORAGE_PATH');
$expectedParent = realpath(__DIR__.'/../storage/framework/testing');
$resolvedStorage = is_string($storageRoot) ? realpath($storageRoot) : false;
if (getenv('APP_ENV') !== 'testing' || !$expectedParent || !$resolvedStorage
    || $normalize(dirname($resolvedStorage)) !== $normalize($expectedParent)
    || !preg_match('/^run-[a-f0-9-]{36}$/D', basename($resolvedStorage))
    || getenv('DB_URL') !== ''
    || getenv('APP_CONFIG_CACHE') !== 'storage/framework/testing/'.basename($resolvedStorage).'/framework/cache/config.php') {
    fwrite(STDERR, "Browser setup requires a private run-UUID storage directory, isolated config cache, and an empty DB_URL.\n"); exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$root = storage_path('framework/testing');
\Illuminate\Support\Facades\File::ensureDirectoryExists($root);
$database = config('database.connections.sqlite.database');
if (!$app->environment('testing') || config('database.default') !== 'sqlite' || !is_string($database)
    || config('database.connections.sqlite.url')
    || $normalize(storage_path()) !== $normalize($resolvedStorage)
    || strtolower(str_replace('\\', '/', dirname($database))) !== strtolower(str_replace('\\', '/', realpath($root)))
    || !preg_match('/^e2e-[a-zA-Z0-9-]+\.sqlite$/D', basename($database)) || file_exists($database)) {
    fwrite(STDERR, "Browser setup requires APP_ENV=testing and a NEW storage/framework/testing/e2e-<unique>.sqlite database.\n"); exit(1);
}
$handle = fopen($database, 'x');
if (!$handle) { fwrite(STDERR, "Cannot create disposable browser database.\n"); exit(1); }
fclose($handle);
$connection = \Illuminate\Support\Facades\DB::connection();
if ($connection->getDriverName() !== 'sqlite' || $normalize($connection->getDatabaseName()) !== $normalize($database)) {
    fwrite(STDERR, "Resolved browser database does not match the disposable SQLite path.\n"); exit(1);
}
foreach (['migrate', 'db:seed'] as $command) {
    if (\Illuminate\Support\Facades\Artisan::call($command, ['--force' => true]) !== 0) {
        fwrite(STDERR, \Illuminate\Support\Facades\Artisan::output()); exit(1);
    }
}
foreach (['owner', 'editor', 'viewer'] as $role) {
    \App\Models\User::create(['name' => ucfirst($role).' browser fixture', 'email' => $role.'@fastfind.test',
        'password' => 'FastFind-e2e-only-2026', 'role' => $role, 'is_active' => true]);
}
\App\Models\User::create(['name' => 'Recovery browser fixture', 'email' => 'recovery@fastfind.test',
    'password' => 'FastFind-e2e-only-2026', 'role' => 'editor', 'is_active' => true]);
// A fresh per-run publisher signs a real package for the browser installer test.
$themeArchive = $root.'/aurora.zip';
$zip = new ZipArchive;
if ($zip->open($themeArchive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create theme fixture.');
$zip->addFile(base_path('examples/themes/aurora/theme.json'), 'theme.json');
$zip->close();
$signingKey = base64_decode(getenv('FASTFIND_E2E_SIGNING_KEY'), true);
if ($signingKey === false || strlen($signingKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Missing per-run signing key.');
file_put_contents($themeArchive.'.json', json_encode(['publisher' => 'local-reviewed', 'sha256' => hash_file('sha256', $themeArchive),
    'signature' => base64_encode(sodium_crypto_sign_detached(file_get_contents($themeArchive), $signingKey))], JSON_THROW_ON_ERROR));
sodium_memzero($signingKey);
echo "Disposable browser fixtures ready.\n";
