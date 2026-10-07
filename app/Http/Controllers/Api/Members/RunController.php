<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\ActivityApplication;
use App\Models\Group;
use App\Services\Integrations\MemberRunReader;
use App\Services\Runs\RunDiscoveryService;
use App\Support\Integrations\MemberPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class RunController extends Controller
{
    public function __construct(private readonly MemberRunReader $reader) {}

    public function lookups(RunDiscoveryService $discovery): JsonResponse
    {
        return response()->json(['data' => $discovery->buildLookups()]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['view' => ['sometimes', Rule::in(['upcoming', 'history', 'saved'])]]);
        $view = $filters['view'] ?? 'upcoming';
        $user = $request->user();
        $query = $this->reader->query($user);
        if ($view === 'saved') {
            $query->whereIn('id', $user->savedActivities()->select('activities.id'));
        } else {
            $query->where(fn ($q) => $q
                ->whereHas('applications', fn ($apps) => $apps->where('user_id', $user->id)->where('status', '!=', ActivityApplication::STATUS_WITHDRAWN))
                ->orWhereHas('slots.assignedCharacter', fn ($chars) => $chars->where('user_id', $user->id)));
            $view === 'history'
                ? $query->whereIn('status', Activity::ARCHIVED_STATUSES)
                : $query->whereNotIn('status', Activity::ARCHIVED_STATUSES)->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now())->orWhere('status', Activity::STATUS_ONGOING));
        }

        return response()->json(MemberPagination::payload($query->orderBy('starts_at')->orderBy('id')->paginate(MemberPagination::size($request)), $this->reader->present(...)));
    }

    public function group(Request $request, Group $group): JsonResponse
    {
        abort_if($group->isBanned($request->user()->id), 404);
        abort_unless($group->is_visible || $group->hasMember($request->user()->id), 404);
        $query = $this->reader->query($request->user())->where('group_id', $group->id)->whereNotIn('status', Activity::ARCHIVED_STATUSES);

        return response()->json(MemberPagination::payload($query->orderBy('starts_at')->orderBy('id')->paginate(MemberPagination::size($request)), $this->reader->present(...)));
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        $run = $this->reader->query($request->user())->findOrFail($activity->id);

        return response()->json(['data' => $this->reader->present($run)]);
    }

    public function applications(Request $request): JsonResponse
    {
        $request->validate(['status' => ['sometimes', Rule::in(ActivityApplication::STATUSES)]]);
        $query = $this->reader->applications($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')));

        return response()->json(MemberPagination::payload($query->orderByDesc('id')->paginate(MemberPagination::size($request)), $this->reader->application(...)));
    }

    public function application(Request $request, ActivityApplication $application): JsonResponse
    {
        $application = $this->reader->applications($request->user())->findOrFail($application->id);

        return response()->json(['data' => $this->reader->application($application)]);
    }
}
