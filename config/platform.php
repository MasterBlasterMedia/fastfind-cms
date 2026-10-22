<?php

return [
    'backups' => [
        'path' => storage_path('app/private/backups'),
        'scheduled' => (bool) env('FASTFIND_BACKUP_SCHEDULED', false),
        'max_files' => 20000,
        'max_bytes' => 2 * 1024 * 1024 * 1024,
    ],
];
