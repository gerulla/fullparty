<?php

namespace App\Services;

class DeploymentVersion
{
    /** @return array{version: string, commit: ?string, deployed_at: ?string} */
    public function metadata(): array
    {
        $path = storage_path('app/version.json');
        $payload = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $payload = is_array($payload) ? $payload : [];

        return [
            'version' => is_string($payload['version'] ?? null) && filled($payload['version']) ? trim($payload['version']) : 'dev',
            'commit' => is_string($payload['commit'] ?? null) && filled($payload['commit']) ? trim($payload['commit']) : null,
            'deployed_at' => is_string($payload['deployed_at'] ?? null) && filled($payload['deployed_at']) ? $payload['deployed_at'] : null,
        ];
    }

    public function release(): ?array
    {
        $metadata = $this->metadata();
        if (! preg_match('/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $metadata['version']) || strlen($metadata['version']) > 50) {
            return null;
        }

        return [...$metadata, 'version' => ltrim($metadata['version'], 'v')];
    }
}
