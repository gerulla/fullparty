<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Services\Groups\Resources\ResourceImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceImageController extends Controller
{
    public function store(Request $request, Group $group, ResourceImageService $images): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:5120'], 'resource_id' => ['nullable', 'integer'],
            'version' => ['required_with:resource_id', 'integer', 'min:1'], 'editing_token' => ['required_with:resource_id', 'string', 'size:64'],
            'alt_text' => ['present', 'nullable', 'string', 'max:500'], 'caption' => ['nullable', 'string', 'max:1000'],
        ]);
        $resource = isset($data['resource_id']) ? GroupResource::where('group_id', $group->id)->findOrFail($data['resource_id']) : null;
        $image = $images->upload($group, $resource, $request->user(), $request->file('image'), $data);

        return response()->json(['data' => $image->toArray() + ['url' => '/resource-assets/'.$image->uuid]], 201);
    }

    public function show(Request $request, GroupResourceImage $image, ResourceImageService $images): StreamedResponse
    {
        $public = $request->getHost() === config('group_resources.public_host');
        abort_unless($images->canRead($image, $public ? null : $request->user(), $public), 404);

        return $images->response($image);
    }
}
