<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupResourceCollection;
use App\Services\Groups\Resources\ResourceOrganizationService;
use App\Services\Groups\Resources\ResourceReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceOrganizationController extends Controller
{
    public function __invoke(Request $request, Group $group, ResourceOrganizationService $organization, ResourceReaderService $reader): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:collection,resource'], 'id' => ['required', 'integer'],
            'parent_id' => ['present', 'nullable', 'integer'], 'before_id' => ['sometimes', 'nullable', 'integer'],
            'version' => ['required_if:kind,resource', 'integer', 'min:1'],
        ]);
        $organization->move($group, $request->user(), $data);

        return response()->json([
            'collections' => GroupResourceCollection::where('group_id', $group->id)->orderBy('sort_order')->orderBy('id')->get(),
            'resources' => $reader->workspace($group, $request->user())['resources'],
        ]);
    }
}
