<?php

namespace App\Services\Changelog;

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ChangelogReader
{
    public function latestId(): ?int
    {
        return Cache::remember($this->cacheKey('latest'), 3600, fn () => ChangelogEntry::published()->latest('published_at')->latest('id')->value('id'));
    }

    public function summary(?User $user): array
    {
        $id = $this->latestId();

        return [
            'latest_id' => $id,
            'unread' => $id !== null && ($user === null || ! DB::table('changelog_reads')->where('user_id', $user->id)->where('changelog_entry_id', $id)->exists()),
        ];
    }

    public function entry(ChangelogEntry $entry): array
    {
        return Cache::remember($this->cacheKey('entry:'.$entry->id.':'.app()->getLocale()), 3600, function () use ($entry) {
            $entry = ChangelogEntry::published()->findOrFail($entry->id);
            $translation = $entry->translations[app()->getLocale()] ?? $entry->translations['en'];

            return [...$this->summaryEntry($entry), ...$translation];
        });
    }

    public function summaryEntry(ChangelogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => ($entry->translations[app()->getLocale()] ?? $entry->translations['en'])['title'],
            'version_from' => $entry->version_from,
            'version_to' => $entry->version_to,
            'published_at' => $entry->published_at?->toIso8601String(),
        ];
    }

    public function markRead(User $user, ChangelogEntry $entry): void
    {
        abort_unless($entry->is_published, 404);
        if ($entry->id === $this->latestId()) {
            DB::table('changelog_reads')->upsert([
                'user_id' => $user->id,
                'changelog_entry_id' => $entry->id,
            ], ['user_id'], ['changelog_entry_id']);
        }
    }

    private function cacheKey(string $suffix): string
    {
        $revision = DB::table('changelog_publication_state')->where('id', 1)->value('revision');

        return 'changelog:'.$revision.':'.$suffix;
    }
}
