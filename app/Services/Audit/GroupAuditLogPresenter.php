<?php

namespace App\Services\Audit;

use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\ActivitySlot;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\User;
use App\Services\Groups\Resources\ResourceAuditContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class GroupAuditLogPresenter
{
    public function __construct(
        private readonly AuditValuePresenter $values,
        private readonly ResourceAuditContext $resourceContext,
    ) {}

    public function rows(Collection $logs, Group $group): array
    {
        $activities = $this->activities($logs, $group);
        $owners = $this->owners($logs);
        $resources = $this->resourceContext->forLogs($logs, $group, auth()->user());

        return $logs->map(fn (AuditLog $log) => $this->row($log, $group, $activities, $owners, $resources))->all();
    }

    public function detail(AuditLog $log, Group $group): array
    {
        $log->loadMissing(['actor', 'subject']);
        $resources = $this->resourceContext->forLogs(collect([$log]), $group, auth()->user());
        $metadata = $this->metadata($log, $resources->get($log->id));
        $owners = $this->owners(collect([$log]));
        $changes = $this->changes($metadata, $owners);
        $facts = [];
        foreach ($metadata as $key => $value) {
            if (in_array($key, ['changes', 'activity_id', 'activity_title', 'resource_id', 'resource_title', 'resource_uuid', 'resource_slug', 'resource_access_level', 'resource_management_access_level'])
                || ($key === 'changed_fields' && $changes !== [])
                || $this->values->isSensitive((string) $key)) {
                continue;
            }
            $facts[] = [
                'key' => (string) $key,
                'label' => $this->values->label((string) $key),
                'value' => $key === 'changed_fields' && is_array($value)
                    ? ['type' => 'list', 'value' => array_map(fn ($field) => ['type' => 'text', 'value' => $this->values->label((string) $field)], $value)]
                    : $this->values->value((string) $key, $value),
            ];
        }

        return [
            ...$this->row($log, $group, $this->activities(collect([$log]), $group), $owners, $resources),
            'formatted_changes' => $changes,
            'facts' => $facts,
        ];
    }

    private function row(AuditLog $log, Group $group, Collection $activities, Collection $owners, Collection $resources): array
    {
        $resourceContext = $resources->get($log->id);
        $metadata = $this->metadata($log, $resourceContext);
        $actor = [
            'id' => $log->actor_user_id,
            'name' => $log->actor?->name ?? ($log->actor_user_id ? __('audit_log.presentation.deleted_actor') : __('audit_log.defaults.system')),
            'avatar_url' => $log->actor?->avatar_url,
            'is_system' => $log->actor_user_id === null,
        ];
        $activityId = $this->activityId($log);
        $activity = $activities->get($activityId);
        $activityTitle = $metadata['activity_title'] ?? $activity?->title;
        $subjectName = null;
        // Resource names come only from the access-checked context, never the raw subject.
        if (! str_starts_with($log->action, 'group.resources.')) {
            $subjectName = $log->subject instanceof Activity ? ($metadata['activity_title'] ?? $activity?->title) : ($log->subject?->name ?? $metadata['applicant_name'] ?? $metadata['subject_name'] ?? null);
        }
        $subject = [
            'type' => $log->subject_type,
            'id' => $log->subject_id,
            'name' => str_starts_with($log->action, 'group.resources.') ? ($resourceContext['resource']['title'] ?? __('audit_log.categories.resources')) : ($subjectName ?: ($log->subject_id && ! $log->subject ? __('audit_log.presentation.deleted_subject') : __('audit_log.presentation.unknown_subject'))),
            'avatar_url' => $log->subject instanceof User ? $log->subject->avatar_url : null,
            'is_system' => false,
        ];
        $run = ($activityId || filled($activityTitle)) ? [
            'id' => $activityId,
            'title' => $activityTitle ?: __('audit_log.presentation.run').' #'.$activityId,
            'url' => $activity ? route('groups.dashboard.activities.show', ['group' => $group, 'activity' => $activity]) : null,
        ] : null;
        $parts = $this->summary($log, $metadata, $actor, $subject, $run, $owners);
        if ($resourceContext) {
            $parts[] = ['kind' => 'text', 'text' => ' '];
            $parts[] = ['kind' => 'resource', 'text' => $resourceContext['resource']['title']];
        }

        return [
            'id' => $log->id,
            'action' => $log->action,
            'severity' => $log->severity,
            'category' => GroupAuditCategory::forAction($log->action),
            'icon' => $this->icon($log->action, $metadata),
            'actor' => $actor,
            'subject' => $subject,
            'activity' => $run,
            'resource' => $resourceContext['resource'] ?? null,
            'title' => __($log->message),
            'summary' => implode('', array_column($parts, 'text')),
            'summary_parts' => $parts,
            'change_count' => count($this->changes($metadata)),
            'changes' => [],
            'details' => [],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function summary(AuditLog $log, array $metadata, array $actor, array $subject, ?array $run, Collection $owners): array
    {
        $action = $log->action;
        $characterName = $metadata['selected_character_name'] ?? $metadata['character_name'] ?? $metadata['source_character_name'] ?? null;
        $subjectName = str_starts_with($action, 'group.activity.')
            ? ($characterName ?: ($log->subject_type === User::class ? $subject['name'] : __('audit_log.presentation.anonymous_participant')))
            : $subject['name'];
        if ($action === 'group.ownership.transferred') {
            $newOwnerId = $metadata['changes']['owner_user_id']['new'] ?? null;
            $subjectName = $owners->get($newOwnerId) ?? ($newOwnerId ? __('audit_log.presentation.deleted_actor').' #'.$newOwnerId : __('audit_log.presentation.unknown_subject'));
        }
        $position = implode(' · ', array_filter([$metadata['group_label'] ?? null, $metadata['slot_label'] ?? null]));
        $roleChange = $metadata['changes']['role'] ?? null;
        $timeChange = $metadata['changes']['starts_at'] ?? [];
        $statusChange = $metadata['changes']['status'] ?? [];
        preg_match('/\.roster\.(host|raid_leader|duelist|trapper|darter)_(marked|cleared)$/', $action, $designation);
        $template = match ($action) {
            'group.activity.created' => 'run_created',
            'group.activity.updated' => isset($metadata['changes']['starts_at']) ? 'run_time_updated' : (isset($metadata['changes']['status']) ? 'run_status_updated' : 'run_updated'),
            'group.activity.deleted' => 'run_deleted',
            'group.activity.roster.assigned', 'group.activity.roster.manual_assigned' => $position ? 'assigned' : null,
            'group.activity.roster.updated', 'group.activity.roster.manual_updated' => $position ? 'assignment_updated' : null,
            'group.activity.roster.discord_participant_synced' => 'discord_synced',
            'group.activity.roster.reassigned' => $position ? 'reassigned' : null,
            'group.activity.roster.replaced' => $position ? 'replaced' : null,
            'group.activity.roster.swapped' => 'swapped',
            'group.activity.roster.returned_to_queue' => 'returned_to_queue',
            'group.activity.roster.manual_removed' => 'manual_removed',
            'group.activity.attendance.checked_in' => 'checked_in',
            'group.activity.attendance.check_in_reverted' => 'check_in_reverted',
            'group.activity.attendance.group_checked_in' => $position ? 'group_checked_in' : null,
            'group.activity.attendance.marked_late' => 'marked_late',
            'group.activity.attendance.marked_missing' => 'marked_missing',
            'group.activity.attendance.missing_reverted' => 'missing_reverted',
            'group.activity.application.submitted' => 'application_submitted',
            'group.activity.application.updated' => 'application_updated',
            'group.activity.application.withdrawn' => 'application_withdrawn',
            'group.activity.application.declined' => 'application_declined',
            'group.activity.application.cancelled' => 'application_cancelled',
            'group.member.promoted' => is_array($roleChange) ? 'promoted' : null,
            'group.member.demoted' => is_array($roleChange) ? 'demoted' : null,
            'group.member.removed' => 'member_removed',
            'group.member.banned' => 'member_banned',
            'group.member.unbanned' => 'member_unbanned',
            'group.member.note.created' => 'note_created',
            'group.member.note.updated' => 'note_updated',
            'group.member.note.deleted' => 'note_deleted',
            'group.ownership.transferred' => 'ownership_transferred',
            default => isset($designation[2]) ? 'designation_'.$designation[2] : null,
        };
        $tokens = [
            'actor' => ['kind' => 'actor', 'text' => $actor['name']],
            'subject' => ['kind' => 'subject', 'text' => $subjectName],
            'activity' => ['kind' => 'activity', 'text' => $run['title'] ?? __('audit_log.presentation.run')],
            'position' => ['kind' => 'value', 'text' => $position],
            'designation' => ['kind' => 'value', 'text' => isset($designation[1]) ? __('audit_log.presentation.designations.'.$designation[1]) : ''],
            'old_time' => ['kind' => 'value', 'text' => $this->values->text('starts_at', $timeChange['old'] ?? null), 'display_value' => $this->values->value('starts_at', $timeChange['old'] ?? null)],
            'new_time' => ['kind' => 'value', 'text' => $this->values->text('starts_at', $timeChange['new'] ?? null), 'display_value' => $this->values->value('starts_at', $timeChange['new'] ?? null)],
            'old_status' => ['kind' => 'value', 'text' => $this->values->text('status', $statusChange['old'] ?? null)],
            'new_status' => ['kind' => 'value', 'text' => $this->values->text('status', $statusChange['new'] ?? null)],
            'old_role' => ['kind' => 'value', 'text' => $this->values->text('role', $roleChange['old'] ?? null)],
            'new_role' => ['kind' => 'value', 'text' => $this->values->text('role', $roleChange['new'] ?? null)],
        ];
        if ($template) {
            $text = __('audit_log.presentation.templates.'.$template);
            $parts = array_map(fn (string $part) => $tokens[trim($part, '{}:')] ?? ['kind' => 'text', 'text' => $part],
                preg_split('/(:[a-z_]+|\{[a-z_]+\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY));
        } else {
            $key = 'audit_log.activity.'.$action;
            $description = __($key);
            if ($description === $key) {
                $description = __($log->message);
                if ($description === $log->message) {
                    $description = Str::headline(str_replace('.', ' ', $action));
                }
            }
            $parts = [$tokens['actor'], ['kind' => 'text', 'text' => ' '.$description]];
            if ($run) {
                $parts[] = ['kind' => 'text', 'text' => ' '];
                $parts[] = $tokens['activity'];
            } elseif ($log->subject_type === User::class && $log->subject_id !== $log->actor_user_id) {
                $parts[] = ['kind' => 'text', 'text' => ' '];
                $parts[] = $tokens['subject'];
            }
        }
        if (str_starts_with($action, 'group.member.note.') && filled($metadata['note_excerpt'] ?? null)) {
            $parts[] = ['kind' => 'text', 'text' => ' “'.Str::limit($metadata['note_excerpt'], 120).'”'];
        }

        return $parts;
    }

    private function changes(array $metadata, ?Collection $owners = null): array
    {
        $changes = [];
        foreach (is_array($metadata['changes'] ?? null) ? $metadata['changes'] : [] as $key => $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change) || $this->values->isSensitive((string) $key)) {
                continue;
            }
            $changes[] = ['key' => (string) $key, 'label' => $this->values->label((string) $key),
                'old_value' => $this->changeValue((string) $key, $change['old'], $owners),
                'new_value' => $this->changeValue((string) $key, $change['new'], $owners)];
        }

        return $changes;
    }

    private function changeValue(string $key, mixed $value, ?Collection $owners): array
    {
        if ($key === 'owner_user_id' && $owners?->has($value)) {
            return ['type' => 'text', 'value' => $owners->get($value).' (#'.$value.')'];
        }

        return $this->values->value($key, $value);
    }

    private function owners(Collection $logs): Collection
    {
        $ids = $logs->flatMap(function (AuditLog $log) {
            $change = $log->metadata['changes']['owner_user_id'] ?? [];

            return is_array($change) ? array_values($change) : [];
        })
            ->filter(fn ($id) => is_numeric($id))->unique();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->pluck('name', 'id');
    }

    private function metadata(AuditLog $log, ?array $resourceContext = null): array
    {
        return str_starts_with($log->action, 'group.resources.') ? ($resourceContext['metadata'] ?? []) : ($log->metadata ?? []);
    }

    private function activityId(AuditLog $log): ?int
    {
        if (str_starts_with($log->action, 'group.resources.')) {
            return null;
        }
        $id = $log->metadata['activity_id'] ?? match ($log->subject_type) {
            Activity::class => $log->subject_id,
            ActivityApplication::class, ActivitySlot::class => $log->subject?->activity_id,
            default => null,
        };

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function activities(Collection $logs, Group $group): Collection
    {
        $ids = $logs->map(fn (AuditLog $log) => $this->activityId($log))->filter()->unique();

        return Activity::query()->where('group_id', $group->id)->whereIn('id', $ids)
            ->get(['id', 'group_id', 'title'])->keyBy('id');
    }

    private function icon(string $action, array $metadata): string
    {
        return 'i-lucide-'.match (true) {
            isset($metadata['changes']['starts_at']) => 'calendar-clock',
            str_contains($action, '.note.') => 'message-square-text',
            str_contains($action, '.invite.') || str_contains($action, '.personal_invite.') => str_ends_with($action, 'revoked') ? 'link-2-off' : 'link',
            str_contains($action, '.application.') => 'clipboard-list',
            str_contains($action, '.attendance.marked_missing') => 'user-x',
            str_contains($action, '.attendance.') => 'clipboard-check',
            str_contains($action, '.roster.returned_to_queue') => 'undo-2',
            str_contains($action, '.roster.') => 'users-round',
            str_contains($action, '.activity.') => 'calendar-days',
            str_contains($action, '.resources.') => 'book-open',
            str_contains($action, '.ownership.') => 'crown',
            str_contains($action, '.discord') => 'plug',
            str_contains($action, '.banned') => 'ban',
            str_contains($action, '.promoted'), str_contains($action, '.demoted') => 'shield',
            GroupAuditCategory::forAction($action) === 'members' => 'user-round',
            default => 'settings',
        };
    }
}
