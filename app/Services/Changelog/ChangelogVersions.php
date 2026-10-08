<?php

namespace App\Services\Changelog;

use App\Models\ChangelogEntry;
use App\Services\DeploymentVersion;
use Illuminate\Validation\ValidationException;

class ChangelogVersions
{
    public function __construct(private readonly DeploymentVersion $deployment) {}

    public function choices(): array
    {
        $release = $this->deployment->release();
        $previous = ChangelogEntry::published()->latest('published_at')->latest('id')->first(['id', 'version_to']);

        return [
            'current_version' => $release['version'] ?? null,
            'current_commit' => $release['commit'] ?? null,
            'previous_entry_id' => $previous?->id,
            'previous_version' => $previous?->version_to,
            'can_use_range' => $release && $previous && version_compare($release['version'], $previous->version_to, '>'),
        ];
    }

    public function snapshot(string $mode, array $expected): array
    {
        $choices = $this->choices();
        if (! $choices['current_version']) {
            throw ValidationException::withMessages(['version_mode' => __('changelog.errors.no_version')]);
        }
        foreach (['current_version', 'current_commit', 'previous_entry_id', 'previous_version'] as $key) {
            if (($expected[$key] ?? null) !== $choices[$key]) {
                throw ValidationException::withMessages(['version_mode' => __('changelog.errors.version_changed')]);
            }
        }
        if ($mode === 'since_last' && ! $choices['can_use_range']) {
            throw ValidationException::withMessages(['version_mode' => __('changelog.errors.no_range')]);
        }
        if ($choices['previous_version'] && version_compare($choices['current_version'], $choices['previous_version'], '<')) {
            throw ValidationException::withMessages(['version_mode' => __('changelog.errors.older_version')]);
        }

        return [
            'version_mode' => $mode,
            'version_from' => $mode === 'since_last' ? $choices['previous_version'] : null,
            'version_to' => $choices['current_version'],
            'commit' => $choices['current_commit'],
            'baseline_id' => $choices['previous_entry_id'],
        ];
    }

    public function ensurePublishable(ChangelogEntry $entry): void
    {
        if ($entry->published_at !== null) {
            return; // Previously published ranges are immutable, including after unpublishing.
        }
        $choices = $this->choices();
        $this->snapshot($entry->version_mode, [
            'current_version' => $entry->version_to,
            'current_commit' => $entry->commit,
            'previous_entry_id' => $entry->baseline_id,
            'previous_version' => $entry->version_mode === 'since_last' ? $entry->version_from : $choices['previous_version'],
        ]);
    }
}
