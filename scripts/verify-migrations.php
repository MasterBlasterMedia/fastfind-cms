<?php

// Test a new, disposable SQLite database. Never use the configured app database.
$database = tempnam(sys_get_temp_dir(), 'fastfind-schema-');
if ($database === false) {
    fwrite(STDERR, "Cannot allocate temporary schema database.\n");
    exit(1);
}
putenv('APP_ENV=testing');
putenv('APP_CONFIG_CACHE=storage/framework/testing/migrations-'.bin2hex(random_bytes(8)).'-config.php');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$database);
putenv('DB_URL=');
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('database.default', 'sqlite');
$app['config']->set('database.connections.sqlite.database', $database);
$app['config']->set('database.connections.sqlite.url', null);
\Illuminate\Support\Facades\DB::purge('sqlite');
$status = 1;
try {
    $artisan = \Illuminate\Support\Facades\Artisan::class;
    foreach (['migrate', 'migrate:rollback', 'migrate'] as $command) {
        if ($artisan::call($command, ['--force' => true]) !== 0) {
            throw new RuntimeException($artisan::output());
        }
        echo $artisan::output();
        $count = \Illuminate\Support\Facades\DB::table('migrations')->count();
        if (($command === 'migrate:rollback' && $count !== 0) || ($command === 'migrate' && $count < 1)) {
            throw new RuntimeException('Unexpected migration state after '.$command);
        }
    }
    echo "Fresh SQLite migration, complete rollback, and repeat migration passed.\n";
    $status = 0;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
} finally {
    \Illuminate\Support\Facades\DB::disconnect('sqlite');
    foreach ([$database, $database.'-shm', $database.'-wal'] as $path) {
        if (is_file($path)) { unlink($path); }
    }
}
exit($status);
