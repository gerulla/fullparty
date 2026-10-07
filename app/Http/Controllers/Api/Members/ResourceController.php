<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\Groups\Resources\ResourceReaderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResourceController extends Controller
{
    public function __construct(private readonly ResourceReaderService $reader) {}

    public function index(Request $request, Group $group): JsonResponse
    {
        return response()->json($this->reader->index($group, $request));
    }

    public function collection(Request $request, Group $group, string $collectionSlug): JsonResponse
    {
        return response()->json($this->reader->index($group, $request, collectionSlug: $collectionSlug));
    }

    public function show(Request $request, Group $group, string $slug): JsonResponse
    {
        $resource = $this->reader->resolve($group, $slug, $request->user());

        $detail = $this->reader->detail($resource, $request);
        $assetPrefix = $request->getSchemeAndHttpHost().'/api/integrations/v1/resource-assets/';
        $detail['images'] = collect($detail['images'])->map(fn ($image) => [
            ...$image, 'url' => $assetPrefix.$image['uuid'],
        ])->all();
        $detail['body'] = $this->imageSources($detail['body'], $assetPrefix);
        $detail['body_html'] = str_replace('src="/resource-assets/', 'src="'.$assetPrefix, $detail['body_html']);

        return response()->json(['data' => $detail]);
    }

    private function imageSources(array $node, string $prefix): array
    {
        if (in_array($node['type'] ?? null, ['image', 'inlineImage'], true)
            && str_starts_with($node['attrs']['src'] ?? '', '/resource-assets/')) {
            $node['attrs']['src'] = $prefix.substr($node['attrs']['src'], strlen('/resource-assets/'));
        }
        if (isset($node['content'])) {
            $node['content'] = array_map(fn ($child) => $this->imageSources($child, $prefix), $node['content']);
        }

        return $node;
    }
}
