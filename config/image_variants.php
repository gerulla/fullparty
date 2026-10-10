<?php

return [
    // Only already-public, managed images may be transformed by the public endpoint.
    'source_directories' => ['activity-types', 'groups'],
    'max_source_bytes' => 10 * 1024 * 1024,
    'max_dimension' => 2048,
    'quality' => 84,
    'cache_disk' => 'local',
    'cache_directory' => 'image-variants',
    'max_variants_per_image' => 32,
    'cache_days' => 7,
    'cache_max_bytes' => 512 * 1024 * 1024,
    'generations_per_minute' => 30,
    'generations_per_ip_per_minute' => 10,
];
