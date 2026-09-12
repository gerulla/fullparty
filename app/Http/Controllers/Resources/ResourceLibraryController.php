<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\DeleteLibraryResourcesRequest;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceLibraryDeletionService;
use App\Services\Groups\Resources\ResourceLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceLibraryController extends Controller
{
    public function destroyResources(DeleteLibraryResourcesRequest $request, Group $group, ResourceLibraryDeletionService $deletion, ResourceLibraryService $libraries): JsonResponse
    {
        $deleted = $deletion->deleteResources($group, $request->user());

        return response()->json(['data' => $libraries->payload($group, true), 'deleted_count' => $deleted]);
    }

    public function update(Request $request, Group $group, ResourceLibraryService $libraries): JsonResponse
    {
        $libraries->settings($group, $request->user(), $request->all());

        return response()->json(['data' => $libraries->payload($group, true)]);
    }
}
