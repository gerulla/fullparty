<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ActivityGroupFollowUp;
use App\Models\Group;
use App\Services\Groups\ActivityGroupFollowUpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityGroupFollowUpController extends Controller
{
    public function __construct(private readonly ActivityGroupFollowUpService $followUps) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->followUps->pendingFor($request->user())]);
    }

    public function show(Request $request, Group $group, Activity $activity): JsonResponse
    {
        return response()->json(['data' => $this->followUps->forRun($group, $activity, $request->user())]);
    }

    public function store(Request $request, Group $group, Activity $activity): JsonResponse
    {
        $this->authorize('manageDashboard', [$activity, $group]);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:200'],
            'user_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json(['data' => $this->followUps->send($group, $activity, $request->user(), $validated['user_ids'])]);
    }

    public function presented(Request $request, ActivityGroupFollowUp $followUp): JsonResponse
    {
        return response()->json(['show_prompt' => $this->followUps->acknowledge($followUp, $request->user())]);
    }

    public function dismiss(Request $request, ActivityGroupFollowUp $followUp): JsonResponse
    {
        $this->followUps->acknowledge($followUp, $request->user(), dismiss: true);

        return response()->json(['dismissed' => true]);
    }
}
