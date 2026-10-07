<?php

namespace App\Services\FFLogs;

use Illuminate\Http\Client\Response;

final class FFLogsPlaygroundClient
{
    public function __construct(private readonly FFLogsClient $client) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload): Response
    {
        return $this->client->query($payload);
    }

    public function endpoint(): string
    {
        return (string) config('services.ff_logs.graphql_url');
    }
}
