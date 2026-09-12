<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\ResourceMutationRequest;
use App\Models\Group;
use App\Models\GroupResource;
use App\Services\Groups\Resources\ResourceLibraryDeletionService;
use App\Services\Groups\Resources\ResourceReaderService;
use App\Services\Groups\Resources\ResourceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ResourceMutationController extends Controller
{
    public function store(ResourceMutationRequest $request, Group $group, ResourceWorkflowService $workflow, ResourceReaderService $reader): JsonResponse
    {
        $resource = $workflow->create($group, $request->user(), $request->validated());

        return response()->json(['data' => $reader->managementDetail($group, $resource->refresh(), $request->user())], 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(ResourceMutationRequest $request, Group $group, GroupResource $resource, string $operation, ResourceWorkflowService $workflow, ResourceReaderService $reader): JsonResponse
    {
        $result = $workflow->mutate($group, $resource, $request->user(), $operation, $request->validated());

        return response()->json(['data' => $result + (in_array($operation, ['acquire', 'autosave', 'save', 'publish', 'restore', 'archive', 'unarchive', 'unpublish', 'pin'], true)
            ? ['resource' => $reader->managementDetail($group, $resource->refresh(), $request->user())] : [])])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(ResourceMutationRequest $request, Group $group, GroupResource $resource, ResourceLibraryDeletionService $deletion): Response
    {
        $deletion->deleteResource($group, $resource, $request->user(), $request->validated());

        return response()->noContent();
    }

    public function revision(Request $request, Group $group, GroupResource $resource, int $revisionId, ResourceReaderService $reader): JsonResponse
    {
        return response()->json(['data' => $reader->revision($group, $resource, $request->user(), $revisionId)])->header('Cache-Control', 'private, no-store');
    }
}
