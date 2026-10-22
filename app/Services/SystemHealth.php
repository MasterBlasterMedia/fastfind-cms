<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SystemHealth
{
    public function report(): array
    {
        $checks = [];
        $this->check($checks, 'database', function () {
            DB::select('SELECT 1');
            return ['status' => 'ok', 'detail' => 'Database responds ('.DB::connection()->getDriverName().').'];
        });
        $this->check($checks, 'storage', function () {
            $paths = [storage_path('app/private'), config('filesystems.disks.public.root'), storage_path('framework/cache/data'), storage_path('framework/sessions'), storage_path('logs')];
            $unwritable = count(array_filter($paths, fn ($path) => !is_dir($path) || !is_writable($path)));
            return ['status' => $unwritable ? 'warning' : 'ok', 'detail' => $unwritable ? "{$unwritable} required storage directories are missing or not writable." : 'Required storage directories are writable.'];
        });
        $this->check($checks, 'cache', function () {
            $key = 'health-'.Str::uuid();
            try {
                Cache::put($key, 'ok', 30);
                $working = Cache::get($key) === 'ok';
            } finally {
                Cache::forget($key);
            }
            return ['status' => $working ? 'ok' : 'error', 'detail' => $working ? 'Cache write/read succeeded.' : 'Cache did not retain a test value.'];
        });
        $this->check($checks, 'php', function () {
            $required = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pdo', 'session', 'sodium', 'tokenizer', 'xml', 'zip'];
            $missing = array_values(array_filter($required, fn ($extension) => !extension_loaded($extension)));
            $supported = version_compare(PHP_VERSION, '8.3.0', '>=');
            return ['status' => !$supported || $missing ? 'error' : 'ok', 'detail' => 'PHP '.PHP_VERSION.($missing ? '; missing: '.implode(', ', $missing) : '; required extensions loaded.')];
        });
        $this->check($checks, 'scheduler', function () {
            $last = SiteSetting::find('scheduler_last_run')?->value;
            $recent = !empty($last['at']) && Carbon::parse($last['at'])->greaterThan(now()->subMinutes(3));
            return ['status' => $recent && empty($last['failed']) ? 'ok' : 'warning', 'detail' => !$recent ? 'No scheduler heartbeat within the last three minutes.' : (!empty($last['failed']) ? 'The last scheduler run had publication failures.' : 'Scheduler heartbeat is recent.'), 'last_run' => $last['at'] ?? null];
        });
        $this->check($checks, 'queue', function () {
            $driver = config('queue.default');
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $status = $failed > 0 ? 'error' : ($driver === 'sync' ? 'ok' : 'warning');
            return ['status' => $status, 'detail' => $driver === 'sync' ? 'Jobs execute synchronously.' : 'External queue selected; worker liveness is not instrumented.', 'failed_jobs' => $failed];
        });
        $this->check($checks, 'plugins', function () {
            $failed = Schema::hasTable('plugin_installations') ? DB::table('plugin_installations')->whereNotNull('last_error')->count() : 0;
            return ['status' => $failed ? 'warning' : 'ok', 'detail' => $failed ? "{$failed} plugin installations report errors." : 'No recorded plugin installation errors.'];
        });
        $this->check($checks, 'environment', function () {
            $issues = [];
            if (app()->environment('production') && config('app.debug')) $issues[] = 'Production debugging is enabled.';
            if (app()->environment('production') && !config('session.secure')) $issues[] = 'Secure session cookies are disabled.';
            if (!config('app.key')) $issues[] = 'Application key is missing.';
            return ['status' => $issues ? 'warning' : 'ok', 'detail' => $issues ? implode(' ', $issues) : 'Basic environment checks passed.'];
        });
        return ['version' => config('plugins.platform_version', '1.0.0'), 'checked_at' => now()->toIso8601String(), 'status' => in_array('error', array_column($checks, 'status'), true) ? 'error' : (in_array('warning', array_column($checks, 'status'), true) ? 'warning' : 'ok'), 'checks' => $checks];
    }

    private function check(array &$checks, string $id, callable $probe): void
    {
        try { $checks[] = ['id' => $id, ...$probe()]; }
        catch (\Throwable) { $checks[] = ['id' => $id, 'status' => 'error', 'detail' => 'Check failed. Inspect application logs and configuration.']; }
    }
}
