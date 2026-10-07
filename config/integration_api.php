<?php

return [
    'docs_host' => env('API_DOCS_HOST', 'api.'.(parse_url(env('APP_URL', 'http://fullparty.test'), PHP_URL_HOST) ?: 'fullparty.test')),
];
