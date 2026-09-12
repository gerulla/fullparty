<?php

return [
    'public_host' => env('RESOURCE_PUBLIC_HOST', 'resources.'.(parse_url(env('APP_URL', 'http://fullparty.test'), PHP_URL_HOST) ?: 'fullparty.test')),
    'disk' => 'local',
    'image_max_bytes' => 5 * 1024 * 1024,
    'quota_bytes' => 1024 * 1024 * 1024,
    'editing_lease_minutes' => 15,
    'abandoned_upload_hours' => 24,
];
