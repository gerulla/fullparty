<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\ResourceMutationRequest;
use App\Models\Group;
use App\Models\GroupResource;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceMutationController extends Controller
{
    public function store(ResourceMutationRequest $request, Group $group, ResourceWorkflowService $workflow): JsonResponse
    {
        $resource = $workflow->create($group, $request->user(), $request->validated());

        return response()->json(['data' => $resource->only(['id', 'slug', 'version', 'status'])], 201);
    }

    public function update(ResourceMutationRequest $request, Group $group, GroupResource $resource, string $operation, ResourceWorkflowService $workflow): JsonResponse
    {
        return response()->json(['data' => $workflow->mutate($group, $resource, $request->user(), $operation, $request->validated())]);
    }

    public function revision(Request $request, Group $group, GroupResource $resource, int $revisionId, ResourceReaderService $reader): JsonResponse
    {
        return response()->json(['data' => $reader->revision($group, $resource, $request->user(), $revisionId)])->header('Cache-Control', 'private, no-store');
    }
}
