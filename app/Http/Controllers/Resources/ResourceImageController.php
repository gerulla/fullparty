<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupResource;
use App\Models\GroupResourceImage;
use App\Services\Groups\Resources\ResourceImageLibraryService;
use App\Services\Groups\Resources\ResourceImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceImageController extends Controller
{
    public function index(Request $request, Group $group, ResourceImageLibraryService $images): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'], 'type' => ['nullable', 'in:all,image,gif'],
            'resource_id' => ['nullable', 'integer'], 'page' => ['sometimes', 'integer', 'min:1'],
            'library_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);

        return response()->json($images->listing($group, $request->user(), $data))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, Group $group, GroupResourceImage $image, ResourceImageLibraryService $images): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'alt_text' => ['present', 'nullable', 'string', 'max:500'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $images->update($group, $image, $request->user(), $data)]);
    }

    public function destroy(Request $request, Group $group, GroupResourceImage $image, ResourceImageLibraryService $images): Response
    {
        $images->delete($group, $image, $request->user());

        return response()->noContent();
    }

    public function store(Request $request, Group $group, ResourceImageService $images): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:5120'], 'resource_id' => ['nullable', 'integer'],
            'version' => ['required_with:resource_id', 'integer', 'min:1'], 'editing_token' => ['required_with:resource_id', 'string', 'size:64'],
            'alt_text' => ['present', 'nullable', 'string', 'max:500'], 'caption' => ['nullable', 'string', 'max:1000'],
            'library_upload' => ['sometimes', 'boolean'],
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
