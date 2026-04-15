<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupSettingsController extends Controller
{
    private const IMAGE_DIRECTORY = 'groups';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger
    ) {}

    public function show(Group $group): Response
    {
        $group->load([
            'owner',
            'memberships.user',
            'invites.creator',
        ]);

        $this->authorizeModeratorAccess($group);

        return Inertia::render('Dashboard/Groups/Settings/Index', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'profile_picture_url' => $group->profile_picture_url,
                'discord_invite_url' => $group->discord_invite_url,
                'datacenter' => $group->datacenter,
                'is_public' => $group->is_public,
                'is_visible' => $group->is_visible,
                'slug' => $group->slug,
                'owner' => [
                    'id' => $group->owner?->id,
                    'name' => $group->owner?->name,
                    'avatar_url' => $group->owner?->avatar_url,
                ],
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'permissions' => [
                    'can_manage_group' => $group->isOwnedBy(auth()->id()),
                    'can_manage_members' => $group->hasModeratorAccess(auth()->id()),
                    'can_manage_invites' => $group->hasModeratorAccess(auth()->id()),
                    'can_transfer_ownership' => $group->isOwnedBy(auth()->id()),
                ],
                'members' => $group->memberships
                    ->sortBy(function (GroupMembership $membership) {
                        return array_search($membership->role, GroupMembership::ROLES, true);
                    })
                    ->values()
                    ->map(fn (GroupMembership $membership) => [
                        'id' => $membership->user->id,
                        'name' => $membership->user->name,
                        'avatar_url' => $membership->user->avatar_url,
                        'role' => $membership->role,
                        'joined_at' => $membership->joined_at,
                    ]),
                'invites' => $group->invites
                    ->sortByDesc('created_at')
                    ->values()
                    ->map(fn ($invite) => [
                        'id' => $invite->id,
                        'token' => $invite->token,
                        'is_system' => $invite->is_system,
                        'uses' => $invite->uses,
                        'max_uses' => $invite->max_uses,
                        'expires_at' => $invite->expires_at,
                        'created_by' => $invite->creator?->name,
                        'created_at' => $invite->created_at,
                    ]),
            ],
        ]);
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships', 'invites');

        $this->authorizeOwnerAccess($group);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'profile_picture' => ['nullable', 'image', 'max:5120'],
            'discord_invite_url' => ['nullable', 'url', 'max:500'],
            'datacenter' => ['required', 'string', Rule::in(config('datacenters.values', []))],
            'is_public' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
        ]);

        $profilePictureUrl = $this->managedImageStorage->replaceUploadedImageIfPresent(
            currentUrl: $group->profile_picture_url,
            file: $request->file('profile_picture'),
            directory: self::IMAGE_DIRECTORY,
            shouldProcess: true
        );

        $originalValues = [
            'name' => $group->name,
            'description' => $group->description,
            'profile_picture_url' => $group->profile_picture_url,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'is_public' => $group->is_public,
            'is_visible' => $group->is_visible,
        ];

        DB::transaction(function () use ($group, $validated, $profilePictureUrl) {
            $group->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'profile_picture_url' => $profilePictureUrl,
                'discord_invite_url' => $validated['discord_invite_url'] ?? null,
                'datacenter' => $validated['datacenter'],
                'is_public' => $validated['is_public'],
                'is_visible' => $validated['is_visible'],
            ]);

            if ($group->is_public) {
                $group->ensureSystemInvite();
            } else {
                $group->removeSystemInvite();
            }
        });

        $updatedValues = [
            'name' => $group->name,
            'description' => $group->description,
            'profile_picture_url' => $group->profile_picture_url,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'is_public' => $group->is_public,
            'is_visible' => $group->is_visible,
        ];

        $changedFields = collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->values()
            ->all();

        if ($changedFields !== []) {
            $this->auditLogger->log(
                action: 'group.updated',
                severity: AuditSeverity::MODERATION_CHANGE,
                scopeType: AuditScope::GROUP,
                scopeId: $group->id,
                message: 'audit_log.events.group.updated',
                actor: auth()->user(),
                subject: $group,
                metadata: [
                    'changed_fields' => $changedFields,
                    'changes' => $this->buildChangeMetadata($originalValues, $updatedValues),
                ],
            );
        }

        return redirect()->back()->with('success', 'group_updated');
    }

    private function authorizeOwnerAccess(Group $group): void
    {
        if (!$group->isOwnedBy(auth()->id())) {
            abort(403);
        }
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    /**
     * @param  array<string, mixed>  $originalValues
     * @param  array<string, mixed>  $updatedValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildChangeMetadata(array $originalValues, array $updatedValues): array
    {
        return collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $originalValues[$field],
                    'new' => $updatedValues[$field],
                ],
            ])
            ->all();
    }
}
