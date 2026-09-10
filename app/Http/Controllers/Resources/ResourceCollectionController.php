<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\ResourceCollectionRequest;
use App\Models\Group;
use App\Models\GroupResourceCollection;
use App\Services\Groups\Resources\ResourceCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResourceCollectionController extends Controller
{
    public function store(ResourceCollectionRequest $request, Group $group, ResourceCollectionService $collections): JsonResponse
    {
        return response()->json(['data' => $collections->save($group, $request->user(), $request->validated())], 201);
    }

    public function update(ResourceCollectionRequest $request, Group $group, GroupResourceCollection $collection, ResourceCollectionService $collections): JsonResponse
    {
        return response()->json(['data' => $collections->save($group, $request->user(), $request->validated(), $collection)]);
    }

    public function destroy(Request $request, Group $group, GroupResourceCollection $collection, ResourceCollectionService $collections): Response
    {
        $data = $request->validate(['destination_id' => ['nullable', 'integer']]);
        $collections->delete($group, $request->user(), $collection, $data['destination_id'] ?? null);

        return response()->noContent();
    }
}
