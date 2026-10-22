<?php
\Illuminate\Support\Facades\Schedule::command('fastfind:backup')->dailyAt('02:00')->withoutOverlapping()
    ->when(fn () => (bool) config('platform.backups.scheduled', false));
\Illuminate\Support\Facades\Schedule::command('fastfind:publish-scheduled')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::call(function () {
    $manager = app(\App\Plugins\PluginManager::class);
    $manager->bootActive();
    foreach (app(\App\Plugins\ExtensionRegistry::class)->tasks() as $id => $tasks) {
        foreach ($tasks as $task) {
            try { $task(); } catch (\Throwable $error) { $manager->report($id, $error, 'scheduled-task'); }
        }
    }
})->name('fastfind:plugin-tasks')->everyMinute()->withoutOverlapping();
