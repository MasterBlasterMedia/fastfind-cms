<?php

return [
    'platform_version' => '1.0.0',
    'bundled_paths' => [base_path('plugins/fastfind-seo')],
    'package_path' => storage_path('app/private/plugins'),
    // Explicitly provision reviewed publisher keys. Never trust a key supplied by an archive.
    'publishers' => array_filter([
        'local-reviewed' => env('FASTFIND_PLUGIN_PUBLISHER_KEY'),
    ]),
    'catalog_url' => env('FASTFIND_PLUGIN_CATALOG_URL'),
    'catalog_hosts' => array_filter(explode(',', env('FASTFIND_PLUGIN_CATALOG_HOSTS', ''))),
    'max_archive_bytes' => 20 * 1024 * 1024,
    'max_expanded_bytes' => 64 * 1024 * 1024,
    'max_files' => 500,
];
