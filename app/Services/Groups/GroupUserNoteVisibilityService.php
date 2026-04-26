<?php

namespace App\Services\Groups;

use App\Models\Group;
use App\Models\GroupUserNote;
use App\Models\User;
use Illuminate\Support\Collection;

class GroupUserNoteVisibilityService
{
    /**
     * @return array{group_notes_by_user_id: Collection<int, Collection<int, GroupUserNote>>, shared_notes_by_user_id: Collection<int, Collection<int, GroupUserNote>>}
     */
    public function loadVisibleNotesForTargets(Group $group, int $currentUserId, Collection $targetUserIds): array
    {
        if (!$group->hasModeratorAccess($currentUserId) || $targetUserIds->isEmpty()) {
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
            || !$group->hasModeratorAccess($currentUserId)
            || $user->id === $currentUserId
        ) {
            return [
                'can_view' => false,
                'can_add' => false,
                'current_group_count' => 0,
                'shared_count' => 0,
                'current_group' => [],
                'shared' => [],
            ];
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
        ];
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
                'can_edit_body' => !$includeSourceGroup && $note->author_user_id === $currentUserId,
                'can_delete' => !$includeSourceGroup && $note->author_user_id === $currentUserId,
                'can_add_addendum' => !$includeSourceGroup,
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
