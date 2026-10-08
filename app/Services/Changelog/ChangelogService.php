<?php

namespace App\Services\Changelog;

use App\Models\ChangelogEntry;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChangelogService
{
    public function __construct(private readonly ChangelogVersions $versions, private readonly AuditLogger $audit) {}

    public function save(User $actor, array $data, ?ChangelogEntry $entry = null): ChangelogEntry
    {
        return DB::transaction(function () use ($actor, $data, $entry) {
            $this->lockPublication();
            $record = $entry ? ChangelogEntry::query()->lockForUpdate()->findOrFail($entry->id) : new ChangelogEntry;
            if ($record->exists) {
                $this->checkRevision($record, $data['revision']);
            }
            if ($record->published_at === null) {
                $record->fill($this->versions->snapshot($data['version_mode'], $data['version_context']));
            }
            $record->fill([
                'translations' => $data['translations'],
                'created_by' => $record->exists ? $record->created_by : $actor->id,
                'updated_by' => $actor->id,
                'revision' => $record->exists ? $record->revision + 1 : 1,
            ]);
            $created = ! $record->exists;
            $record->save();
            $this->recordChange($actor, $record, $created ? 'created' : 'updated');

            return $record;
        });
    }

    public function visibility(User $actor, ChangelogEntry $entry, int $revision, bool $published): void
    {
        DB::transaction(function () use ($actor, $entry, $revision, $published) {
            $this->lockPublication();
            $record = ChangelogEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $this->checkRevision($record, $revision);
            if ($record->is_published === $published) {
                return;
            }
            if ($published) {
                $this->versions->ensurePublishable($record);
            }
            $record->update([
                'is_published' => $published,
                'published_at' => $published ? ($record->published_at ?? now()) : $record->published_at,
                'updated_by' => $actor->id,
                'revision' => $record->revision + 1,
            ]);
            $this->recordChange($actor, $record, $published ? 'published' : 'unpublished');
        });
    }

    private function lockPublication(): void
    {
        DB::table('changelog_publication_state')->where('id', 1)->lockForUpdate()->first();
    }

    private function checkRevision(ChangelogEntry $entry, int $revision): void
    {
        if ($entry->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => __('changelog.errors.conflict')]);
        }
    }

    private function recordChange(User $actor, ChangelogEntry $entry, string $action): void
    {
        DB::table('changelog_publication_state')->where('id', 1)->increment('revision');
        $this->audit->log(
            action: 'admin.changelog.'.$action,
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'changelog.audit.'.$action,
            actor: $actor,
            subject: $entry,
            metadata: ['version_from' => $entry->version_from, 'version_to' => $entry->version_to],
        );
    }
}
