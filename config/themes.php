<?php

return [
    'bundled_path' => resource_path('themes'),
    'package_path' => storage_path('app/private/themes'),
    'output_path' => storage_path('app/private/theme-packages'),
    'max_archive_bytes' => 256 * 1024,
    'max_expanded_bytes' => 192 * 1024,
];
