<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupUserNote;
use App\Models\User;
use Illuminate\Support\Collection;

class GroupUserNoteVisibilityService
{
    public function canViewNote(GroupUserNote $note, User $viewer): bool
    {
        if ($note->user_id === $viewer->id) {
            return false;
        }
        if (! $note->group->isBanned($viewer->id) && $note->group->hasModeratorAccess($viewer->id)) {
            return true;
        }
        if (! $note->is_shared_with_groups) {
            return false;
        }

        // Shared notes appear only when reviewing a member, banned member, or applicant
        // in another group the viewer moderates. Knowing a note ID grants no access.
        return Group::query()
            ->where(fn ($query) => $query->where('owner_id', $viewer->id)
                ->orWhereHas('memberships', fn ($memberships) => $memberships->where('user_id', $viewer->id)->whereIn('role', ['admin', 'moderator'])))
            ->whereDoesntHave('bans', fn ($bans) => $bans->where('user_id', $viewer->id))
            ->where(fn ($query) => $query->where('owner_id', $note->user_id)
                ->orWhereHas('memberships', fn ($memberships) => $memberships->where('user_id', $note->user_id))
                ->orWhereHas('bans', fn ($bans) => $bans->where('user_id', $note->user_id))
                ->orWhereHas('activities.applications', fn ($applications) => $applications->where('user_id', $note->user_id)))
            ->exists();
    }

    /**
     * @return array{group_notes_by_user_id: Collection<int, Collection<int, GroupUserNote>>, shared_notes_by_user_id: Collection<int, Collection<int, GroupUserNote>>}
     */
    public function loadVisibleNotesForTargets(Group $group, int $currentUserId, Collection $targetUserIds): array
    {
        if (! $group->hasModeratorAccess($currentUserId) || $targetUserIds->isEmpty()) {
            return [
                'group_notes_by_user_id' => collect(),
                'shared_notes_by_user_id' => collect(),
            ];
        }

        $groupNotesByUserId = GroupUserNote::query()
            ->with(['author', 'addenda.author'])
            ->where('group_id', $group->id)
            ->whereIn('user_id', $targetUserIds)
            ->latest()
            ->get()
            ->groupBy('user_id');

        $sharedNotesByUserId = GroupUserNote::query()
            ->with(['author', 'group', 'addenda.author'])
            ->where('group_id', '!=', $group->id)
            ->where('is_shared_with_groups', true)
            ->whereIn('user_id', $targetUserIds)
            ->latest()
            ->get()
            ->groupBy('user_id');

        return [
            'group_notes_by_user_id' => $groupNotesByUserId,
            'shared_notes_by_user_id' => $sharedNotesByUserId,
        ];
    }

    /**
     * @param  Collection<int, Collection<int, GroupUserNote>>  $groupNotesByUserId
     * @param  Collection<int, Collection<int, GroupUserNote>>  $sharedNotesByUserId
     * @return array{can_view: bool, current_group_count: int, shared_count: int, highest_severity: ?string, severities: list<string>}
     */
    public function serializeVisibleNoteSummaryForUser(
        Group $group,
        ?User $user,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId
    ): array {
        if (
            $user === null
            || ! $group->hasModeratorAccess($currentUserId)
            || $user->id === $currentUserId
        ) {
            return $this->emptyVisibleNoteSummary();
        }

        $visibleNotes = collect($groupNotesByUserId->get($user->id, []))->concat($sharedNotesByUserId->get($user->id, []));

        return [
            'can_view' => true,
            'current_group_count' => count($groupNotesByUserId->get($user->id, [])),
            'shared_count' => count($sharedNotesByUserId->get($user->id, [])),
            'highest_severity' => $this->highestVisibleSeverity($visibleNotes),
            'severities' => $this->visibleSeverities($visibleNotes),
        ];
    }

    /**
     * @param  Collection<int, Collection<int, GroupUserNote>>  $groupNotesByUserId
     * @param  Collection<int, Collection<int, GroupUserNote>>  $sharedNotesByUserId
     * @return array<string, mixed>
     */
    public function serializeVisibleNotesForUser(
        Group $group,
        ?User $user,
        int $currentUserId,
        Collection $groupNotesByUserId,
        Collection $sharedNotesByUserId
    ): array {
        if (
            $user === null
            || ! $group->hasModeratorAccess($currentUserId)
            || $user->id === $currentUserId
        ) {
            return $this->emptyVisibleNotes();
        }

        $currentGroupNotes = collect($groupNotesByUserId->get($user->id, []))
            ->map(fn (GroupUserNote $note) => $this->serializeNote($note, false, $currentUserId))
            ->values()
            ->all();

        $sharedNotes = collect($sharedNotesByUserId->get($user->id, []))
            ->map(fn (GroupUserNote $note) => $this->serializeNote($note, true, $currentUserId))
            ->values()
            ->all();

        return [
            'can_view' => true,
            'can_add' => true,
            'current_group_count' => count($currentGroupNotes),
            'shared_count' => count($sharedNotes),
            'current_group' => $currentGroupNotes,
            'shared' => $sharedNotes,
            'highest_severity' => $this->highestVisibleSeverity(collect($currentGroupNotes)->concat($sharedNotes)),
            'severities' => $this->visibleSeverities(collect($currentGroupNotes)->concat($sharedNotes)),
        ];
    }

    /**
     * @return array{can_view: bool, current_group_count: int, shared_count: int, highest_severity: ?string, severities: list<string>}
     */
    public function emptyVisibleNoteSummary(): array
    {
        return [
            'can_view' => false,
            'current_group_count' => 0,
            'shared_count' => 0,
            'highest_severity' => null,
            'severities' => [],
        ];
    }

    /**
     * @return array{can_view: bool, can_add: bool, current_group_count: int, shared_count: int, highest_severity: ?string, severities: list<string>, current_group: array<int, array<string, mixed>>, shared: array<int, array<string, mixed>>}
     */
    public function emptyVisibleNotes(): array
    {
        return [
            'can_view' => false,
            'can_add' => false,
            'current_group_count' => 0,
            'shared_count' => 0,
            'current_group' => [],
            'shared' => [],
            'highest_severity' => null,
            'severities' => [],
        ];
    }

    /** @return list<string> */
    private function visibleSeverities(Collection $notes): array
    {
        return array_values(array_filter(
            [GroupUserNote::SEVERITY_INFO, GroupUserNote::SEVERITY_COMMENDATION, GroupUserNote::SEVERITY_WARNING, GroupUserNote::SEVERITY_CRITICAL],
            fn (string $severity) => $notes->contains('severity', $severity),
        ));
    }

    private function highestVisibleSeverity(Collection $notes): ?string
    {
        foreach ([GroupUserNote::SEVERITY_CRITICAL, GroupUserNote::SEVERITY_WARNING, GroupUserNote::SEVERITY_COMMENDATION] as $severity) {
            if ($notes->contains('severity', $severity)) {
                return $severity;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeNote(GroupUserNote $note, bool $includeSourceGroup, int $currentUserId): array
    {
        return [
            'id' => $note->id,
            'severity' => $note->severity,
            'body' => $note->body,
            'is_shared_with_groups' => $note->is_shared_with_groups,
            'created_at' => $note->created_at?->toIso8601String(),
            'permissions' => [
                'can_edit_body' => ! $includeSourceGroup && $note->author_user_id === $currentUserId,
                'can_delete' => ! $includeSourceGroup && $note->author_user_id === $currentUserId,
                'can_add_addendum' => ! $includeSourceGroup,
            ],
            'author' => $note->author ? [
                'id' => $note->author->id,
                'name' => $note->author->name,
                'avatar_url' => $note->author->avatar_url,
            ] : null,
            'addenda' => $note->addenda
                ->map(fn ($addendum) => [
                    'id' => $addendum->id,
                    'body' => $addendum->body,
                    'created_at' => $addendum->created_at?->toIso8601String(),
                    'permissions' => [
                        'can_edit_body' => ! $includeSourceGroup && $addendum->author_user_id === $currentUserId,
                        'can_delete' => ! $includeSourceGroup && $addendum->author_user_id === $currentUserId,
                    ],
                    'author' => $addendum->author ? [
                        'id' => $addendum->author->id,
                        'name' => $addendum->author->name,
                        'avatar_url' => $addendum->author->avatar_url,
                    ] : null,
                ])
                ->values()
                ->all(),
            'source_group' => $includeSourceGroup ? [
                'id' => $note->group?->id,
                'name' => $note->group?->name,
                'slug' => $note->group?->slug,
            ] : null,
        ];
    }
}
