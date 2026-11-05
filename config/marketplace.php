<?php

return [
    // Catalog and package hosts are operator configuration, never supplied by a catalog.
    'url' => env('FASTFIND_MARKETPLACE_URL'),
    'site_url' => env('FASTFIND_MARKETPLACE_SITE_URL'),
    'hosts' => array_values(array_filter(array_map(fn ($host) => strtolower(trim($host)), explode(',', env('FASTFIND_MARKETPLACE_HOSTS', ''))))),
    'max_catalog_bytes' => 1024 * 1024,
    'max_package_bytes' => 20 * 1024 * 1024,
    'download_path' => storage_path('app/private/marketplace-downloads'),
];
