<?php

namespace App\Services\Groups\Resources;

use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceCollection;
use App\Models\GroupResourceImage;
use App\Models\GroupResourceLibrary;
use App\Models\GroupResourceRevision;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class ResourceAudit
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function record(Group $group, User $user, Model $subject, string $action, ?GroupResourceRevision $revision = null): void
    {
        // ResourceAuditContext checks both current and recorded audience permissions
        // before any snapshot is exposed to an audit reader.
        $metadata = ['resource_audit_version' => 1];
        if ($subject instanceof GroupResource) {
            $snapshot = $revision?->snapshot ?? $subject->working_copy ?? $subject->publishedRevision?->snapshot ?? [];
            $metadata += [
                'resource_id' => $subject->id,
                'resource_uuid' => $subject->uuid,
                'resource_title' => $snapshot['title'] ?? $subject->slug,
                'resource_slug' => $snapshot['slug'] ?? $subject->slug,
                'resource_access_level' => $snapshot['access_level'] ?? $subject->access_level,
                'resource_management_access_level' => $subject->management_access_level,
                'resource_version' => $subject->version ?? 1,
                'resource_status' => $subject->status ?? 'draft',
            ];
            if ($revision) {
                $metadata += ['revision_id' => $revision->id, 'revision_summary' => $revision->summary];
            }
        } elseif ($subject instanceof GroupResourceCollection) {
            $metadata += ['collection_id' => $subject->id, 'collection_name' => $subject->name];
        } elseif ($subject instanceof GroupResourceImage) {
            $metadata += [
                'image_id' => $subject->id, 'image_name' => $subject->original_name,
                'image_access_level' => $subject->access_level, 'image_uploader_user_id' => $subject->uploader_user_id,
            ];
        } elseif ($subject instanceof GroupResourceLibrary) {
            $metadata += ['library_title' => $subject->customization['title'] ?? $group->name];
        }
        $this->logger->log('group.resources.'.$action, 'info', 'group', $group->id, 'audit_log.events.group.resources.'.$action, $user, $subject, $metadata);
    }
}
