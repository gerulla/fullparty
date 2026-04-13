<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\ScheduledRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GroupRunController extends Controller
{
    public function index(Group $group): Response
    {
        $group->load([
            'memberships',
            'scheduledRuns.organizer',
        ]);

        if (!$group->hasMember(auth()->id())) {
            abort(403);
        }

        return Inertia::render('Dashboard/Groups/Runs/Index', [
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'current_user_role' => $group->memberships
                    ->firstWhere('user_id', auth()->id())
                    ?->role,
                'permissions' => [
                    'can_manage_runs' => $group->hasModeratorAccess(auth()->id()),
                ],
            ],
            'runs' => $group->scheduledRuns
                ->sortByDesc('updated_at')
                ->values()
                ->map(fn (ScheduledRun $scheduledRun) => [
                    'id' => $scheduledRun->id,
                    'status' => $scheduledRun->status,
                    'organized_by' => $scheduledRun->organizer ? [
                        'id' => $scheduledRun->organizer->id,
                        'name' => $scheduledRun->organizer->name,
                        'avatar_url' => $scheduledRun->organizer->avatar_url,
                    ] : null,
                    'created_at' => $scheduledRun->created_at,
                    'updated_at' => $scheduledRun->updated_at,
                ]),
        ]);
    }

    public function store(Request $request, Group $group): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);

        $validated = $request->validate($this->rules($group));

        $group->scheduledRuns()->create([
            'organized_by_user_id' => $validated['organized_by_user_id'] ?? auth()->id(),
            'status' => $validated['status'],
        ]);

        return redirect()->back()->with('success', 'scheduled_run_created');
    }

    public function show(Group $group, ScheduledRun $scheduledRun): RedirectResponse
    {
        $this->ensureScheduledRunBelongsToGroup($group, $scheduledRun);

        return redirect()->route('groups.dashboard.runs.index', $group);
    }

    public function update(Request $request, Group $group, ScheduledRun $scheduledRun): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureScheduledRunBelongsToGroup($group, $scheduledRun);

        $validated = $request->validate($this->rules($group));

        $scheduledRun->update([
            'organized_by_user_id' => $validated['organized_by_user_id'] ?? $scheduledRun->organized_by_user_id,
            'status' => $validated['status'],
        ]);

        return redirect()->back()->with('success', 'scheduled_run_updated');
    }

    public function destroy(Group $group, ScheduledRun $scheduledRun): RedirectResponse
    {
        $group->loadMissing('memberships');
        $this->authorizeModeratorAccess($group);
        $this->ensureScheduledRunBelongsToGroup($group, $scheduledRun);

        $scheduledRun->delete();

        return redirect()->back()->with('success', 'scheduled_run_deleted');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function rules(Group $group): array
    {
        $moderatorIds = $group->memberships
            ->filter(fn (GroupMembership $membership) => in_array($membership->role, [
                GroupMembership::ROLE_OWNER,
                GroupMembership::ROLE_MODERATOR,
            ], true))
            ->pluck('user_id')
            ->all();

        return [
            'organized_by_user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::in($moderatorIds),
            ],
            'status' => ['required', Rule::in(ScheduledRun::STATUSES)],
        ];
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    private function ensureScheduledRunBelongsToGroup(Group $group, ScheduledRun $scheduledRun): void
    {
        if ($scheduledRun->group_id !== $group->id) {
            abort(404);
        }
    }
}
