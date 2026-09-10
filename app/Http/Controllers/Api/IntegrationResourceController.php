<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\IntegrationResourceListRequest;
use App\Http\Requests\Resources\IntegrationResourceRequest;
use App\Models\GroupResourceImage;
use App\Services\Groups\Resources\ResourceCommandService;
use App\Services\Groups\Resources\ResourceImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IntegrationResourceController extends Controller
{
    public function index(IntegrationResourceListRequest $request, ResourceCommandService $commands): JsonResponse
    {
        $discordGuildId = $request->validated('discord_guild_id');
        $group = $commands->group($request, $discordGuildId);
        $page = $commands->available($group)->orderBy('name')->paginate(
            perPage: (int) $request->validated('per_page', 25),
            columns: ['name', 'embed'],
            page: (int) $request->validated('page', 1),
        );

        return response()->json([
            'data' => $page->getCollection()
                ->map(fn ($command) => ['command_name' => $command->name, 'title' => $command->embed['title'] ?? null]),
            'meta' => [
                'group_id' => $group->id,
                'discord_guild_id' => $discordGuildId,
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(IntegrationResourceRequest $request, string $commandName, ResourceCommandService $commands): JsonResponse
    {
        $discordGuildId = $request->validated('discord_guild_id');
        $group = $commands->group($request, $discordGuildId);

        return response()->json(['data' => $commands->payload($commands->find($group, $commandName), $discordGuildId)])->header('Cache-Control', 'no-store');
    }

    public function image(Request $request, string $discordGuildId, string $commandName, GroupResourceImage $image, ResourceCommandService $commands, ResourceImageService $images): StreamedResponse
    {
        $group = $commands->group($request, $discordGuildId);
        $command = $commands->find($group, $commandName);
        abort_unless((int) $image->resource_id === (int) $command->resource_id && (int) $image->group_id === (int) $group->id && in_array($image->uuid, [data_get($command->embed, 'image.asset_id'), data_get($command->embed, 'thumbnail.asset_id')], true), 404);

        return $images->response($image);
    }
}
