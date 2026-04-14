<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use App\Services\ManagedImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupController extends Controller
{
    private const IMAGE_DIRECTORY = 'groups';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage
    ) {}

    public function index(Request $request): Response
    {
        $user = auth()->user();

        $ownedGroups = $user->ownedGroups()
            ->with(['memberships', 'scheduledRuns'])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $moderatedGroups = $user->moderatedGroups()
            ->with(['memberships', 'scheduledRuns'])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $memberGroups = $user->memberGroups()
            ->with(['memberships', 'scheduledRuns'])
            ->get()
            ->sortBy('name')
            ->values()
            ->map(fn (Group $group) => $this->serializeGroupListItem($group, $user->id));

        $discoverGroups = $this->serializePaginatedGroups(
            $this->discoverGroupsQuery($user->id)->paginate(
                perPage: 20,
                pageName: 'discover_page',
                page: (int) $request->integer('discover_page', 1)
            ),
            $user->id
        );

        return Inertia::render('Dashboard/Groups/Index', [
            'ownedGroups' => $ownedGroups,
            'moderatedGroups' => $moderatedGroups,
            'memberGroups' => $memberGroups,
            'discoverGroups' => $discoverGroups,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $query = trim((string) ($validated['query'] ?? ''));

        if ($query === '') {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                ],
            ]);
        }

        $like = '%'.$query.'%';

        $paginator = Group::query()
            ->select('groups.*')
            ->selectRaw(
                'CASE
                    WHEN groups.owner_id = ? THEN 0
                    WHEN current_membership.role = ? THEN 1
                    WHEN current_membership.role = ? THEN 2
                    ELSE 3
                END as search_priority',
                [
                    $user->id,
                    GroupMembership::ROLE_MODERATOR,
                    GroupMembership::ROLE_MEMBER,
                ]
            )
            ->leftJoin('group_memberships as current_membership', function ($join) use ($user) {
                $join->on('current_membership.group_id', '=', 'groups.id')
                    ->where('current_membership.user_id', '=', $user->id);
            })
            ->with(['memberships', 'scheduledRuns'])
            ->where(function ($queryBuilder) use ($user) {
                $queryBuilder
                    ->where('groups.owner_id', $user->id)
                    ->orWhereNotNull('current_membership.user_id')
                    ->orWhere('groups.is_visible', true);
            })
            ->where(function ($queryBuilder) use ($like) {
                $queryBuilder
                    ->where('groups.name', 'like', $like)
                    ->orWhere('groups.description', 'like', $like)
                    ->orWhere('groups.slug', 'like', $like);
            })
            ->orderBy('search_priority')
            ->orderBy('groups.name')
            ->paginate(
                perPage: 10,
                page: (int) ($validated['page'] ?? 1)
            );

        return response()->json($this->serializePaginatedGroups($paginator, $user->id));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->storeRules());
        $profilePictureUrl = $this->managedImageStorage->uploadImageIfPresent(
            $request->file('profile_picture'),
            self::IMAGE_DIRECTORY,
            true
        );

        $group = DB::transaction(function () use ($validated, $profilePictureUrl) {
            $group = Group::create([
                'owner_id' => auth()->id(),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'profile_picture_url' => $profilePictureUrl,
                'discord_invite_url' => $validated['discord_invite_url'] ?? null,
                'datacenter' => $validated['datacenter'],
                'is_public' => $validated['is_public'],
                'is_visible' => $validated['is_visible'],
                'slug' => $validated['slug'],
            ]);

            $group->memberships()->create([
                'user_id' => auth()->id(),
                'role' => GroupMembership::ROLE_OWNER,
                'joined_at' => now(),
            ]);

            if ($group->is_public) {
                $group->ensureSystemInvite();
            }

            return $group;
        });

        return redirect()->route('groups.show', $group)->with('success', 'group_created');
    }

    public function show(Group $group): Response
    {
        $group->load([
            'owner',
            'memberships.user',
            'scheduledRuns.organizer',
        ]);

        $currentUserId = auth()->id();

        if (!$group->is_visible && !$group->hasMember($currentUserId)) {
            abort(404);
        }

        return Inertia::render('Groups/Profile', [
            'group' => $this->serializeGroupProfile($group, $currentUserId),
        ]);
    }

    public function destroy(Group $group): RedirectResponse
    {
        if (!$group->isOwnedBy(auth()->id())) {
            abort(403);
        }

        $this->managedImageStorage->deleteManagedImage($group->profile_picture_url, self::IMAGE_DIRECTORY);
        $group->delete();

        return redirect()->route('groups.index')->with('success', 'group_deleted');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function storeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'profile_picture' => ['nullable', 'image', 'max:5120'],
            'discord_invite_url' => ['nullable', 'url', 'max:500'],
            'datacenter' => ['required', 'string', Rule::in(config('datacenters.values', []))],
            'is_public' => ['required', 'boolean'],
            'is_visible' => ['required', 'boolean'],
            'slug' => [
                'required',
                'string',
                'max:8',
                'regex:/^[a-z]{1,8}$/',
                Rule::notIn(['admin', 'api', 'auth', 'groups', 'group', 'invite', 'invites', 'login', 'register', 'settings']),
                Rule::unique('groups', 'slug'),
            ],
        ];
    }

    private function serializeGroupListItem(Group $group, int $currentUserId): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'profile_picture_url' => $group->profile_picture_url,
            'discord_invite_url' => $group->discord_invite_url,
            'datacenter' => $group->datacenter,
            'is_public' => $group->is_public,
            'is_visible' => $group->is_visible,
            'slug' => $group->slug,
            'current_user_role' => $group->memberships
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'stats' => [
                'member_count' => $group->memberships->count(),
                'upcoming_run_count' => $group->scheduledRuns
                    ->whereIn('status', [
                        ScheduledRun::STATUS_SCHEDULED,
                        ScheduledRun::STATUS_UPCOMING,
                        ScheduledRun::STATUS_ONGOING,
                    ])
                    ->count(),
                'run_count' => $group->scheduledRuns->count(),
                'completed_run_count' => $group->scheduledRuns
                    ->where('status', ScheduledRun::STATUS_COMPLETE)
                    ->count(),
                'last_activity_at' => $this->resolveLastActivityAt($group),
            ],
        ];
    }

    private function resolveLastActivityAt(Group $group)
    {
        $runActivity = $group->scheduledRuns->max('updated_at');

        if (!$runActivity) {
            return $group->updated_at;
        }

        return $group->updated_at && $group->updated_at->gt($runActivity)
            ? $group->updated_at
            : $runActivity;
    }

    private function discoverGroupsQuery(int $currentUserId)
    {
        $latestRunActivity = ScheduledRun::query()
            ->selectRaw('group_id, MAX(updated_at) as latest_run_activity_at')
            ->groupBy('group_id');

        return Group::query()
            ->select('groups.*')
            ->leftJoinSub($latestRunActivity, 'latest_run_activity', function ($join) {
                $join->on('latest_run_activity.group_id', '=', 'groups.id');
            })
            ->with(['memberships', 'scheduledRuns'])
            ->visible()
            ->whereDoesntHave('memberships', function ($query) use ($currentUserId) {
                $query->where('user_id', $currentUserId);
            })
            ->orderByRaw(
                'CASE
                    WHEN latest_run_activity.latest_run_activity_at IS NOT NULL
                        AND latest_run_activity.latest_run_activity_at > groups.updated_at
                        THEN latest_run_activity.latest_run_activity_at
                    ELSE groups.updated_at
                END DESC'
            );
    }

    private function serializePaginatedGroups(LengthAwarePaginator $paginator, int $currentUserId): array
    {
        return [
            'data' => $paginator->getCollection()
                ->map(fn (Group $group) => $this->serializeGroupListItem($group, $currentUserId))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private function serializeGroupProfile(Group $group, int $currentUserId): array
    {
        [$currentRuns, $pastRuns] = $this->partitionVisibleRuns($group, $currentUserId);

        return [
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
                ->firstWhere('user_id', $currentUserId)
                ?->role,
            'permissions' => [
                'can_join' => $group->is_public
                    && !$group->hasMember($currentUserId)
                    && !$group->isBanned($currentUserId),
                'can_access_dashboard' => $group->hasMember($currentUserId),
            ],
            'stats' => [
                'member_count' => $group->memberships->count(),
                'moderator_count' => $group->memberships
                    ->where('role', GroupMembership::ROLE_MODERATOR)
                    ->count(),
                'run_count' => $group->scheduledRuns->count(),
                'completed_run_count' => $group->scheduledRuns
                    ->where('status', ScheduledRun::STATUS_COMPLETE)
                    ->count(),
            ],
            'members_preview' => $group->memberships
                ->sortBy(function (GroupMembership $membership) {
                    return array_search($membership->role, GroupMembership::ROLES, true);
                })
                ->take(8)
                ->values()
                ->map(fn (GroupMembership $membership) => [
                    'id' => $membership->user->id,
                    'name' => $membership->user->name,
                    'avatar_url' => $membership->user->avatar_url,
                    'role' => $membership->role,
                ]),
            'runs' => [
                'current' => $currentRuns,
                'past' => $pastRuns,
            ],
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function partitionVisibleRuns(Group $group, int $currentUserId): array
    {
        $canSeeInternalRuns = $group->hasMember($currentUserId);
        $canSeeFutureRuns = $canSeeInternalRuns || $group->is_public;

        $currentRuns = $group->scheduledRuns
            ->filter(function (ScheduledRun $scheduledRun) use ($canSeeFutureRuns, $canSeeInternalRuns) {
                if (in_array($scheduledRun->status, ScheduledRun::PAST_STATUSES, true)) {
                    return false;
                }

                if ($canSeeInternalRuns) {
                    return true;
                }

                if (!$canSeeFutureRuns) {
                    return false;
                }

                return in_array($scheduledRun->status, [
                    ScheduledRun::STATUS_SCHEDULED,
                    ScheduledRun::STATUS_UPCOMING,
                    ScheduledRun::STATUS_ONGOING,
                ], true);
            })
            ->values()
            ->map(fn (ScheduledRun $scheduledRun) => $this->serializeScheduledRun($scheduledRun))
            ->all();

        $pastRuns = $group->scheduledRuns
            ->filter(fn (ScheduledRun $scheduledRun) => in_array($scheduledRun->status, ScheduledRun::PAST_STATUSES, true))
            ->values()
            ->map(fn (ScheduledRun $scheduledRun) => $this->serializeScheduledRun($scheduledRun))
            ->all();

        return [$currentRuns, $pastRuns];
    }

    private function serializeScheduledRun(ScheduledRun $scheduledRun): array
    {
        return [
            'id' => $scheduledRun->id,
            'status' => $scheduledRun->status,
            'organized_by' => $scheduledRun->organizer ? [
                'id' => $scheduledRun->organizer->id,
                'name' => $scheduledRun->organizer->name,
                'avatar_url' => $scheduledRun->organizer->avatar_url,
            ] : null,
            'created_at' => $scheduledRun->created_at,
            'updated_at' => $scheduledRun->updated_at,
        ];
    }
}
