<?php

namespace App\Http\Controllers\Resources;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Policies\GroupResourcePolicy;
use App\Services\Groups\Resources\XivGearImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResourceGearsetController extends Controller
{
    public function store(Request $request, Group $group, GroupResourcePolicy $policy, XivGearImportService $importer): JsonResponse
    {
        abort_unless($policy->manageLibrary($request->user(), $group), 403);
        $data = $request->validate(['url' => ['required', 'string', 'max:2048'], 'set_indices' => ['nullable', 'array', 'min:1', 'max:20'], 'set_indices.*' => ['required', 'integer', 'distinct', 'between:0,99']]);

        return response()->json($importer->import(trim($data['url']), $data['set_indices'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function icon(int $icon): StreamedResponse
    {
        $disk = Storage::disk('local');
        $path = 'xivgear-icons/'.$icon.'.png';
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }
}
