<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Resources\IntegrationResourceListRequest;
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

        return response()->json($commands->listing(
            $group, $discordGuildId, (int) $request->validated('page', 1), (int) $request->validated('per_page', 25),
        ))->header('Cache-Control', 'no-store');
    }

    public function show(IntegrationResourceListRequest $request, string $commandName, ResourceCommandService $commands): JsonResponse
    {
        $discordGuildId = $request->validated('discord_guild_id');
        $group = $commands->group($request, $discordGuildId);

        return response()->json($commands->lookup(
            $group, $commandName, $discordGuildId, (int) $request->validated('page', 1), (int) $request->validated('per_page', 25),
        ))->header('Cache-Control', 'no-store');
    }

    public function image(Request $request, string $discordGuildId, string $commandName, GroupResourceImage $image, ResourceCommandService $commands, ResourceImageService $images): StreamedResponse
    {
        $group = $commands->group($request, $discordGuildId);
        $command = $commands->find($group, $commandName);
        abort_unless((int) $image->group_id === (int) $group->id && in_array($image->uuid, [data_get($command->embed, 'image.asset_id'), data_get($command->embed, 'thumbnail.asset_id')], true), 404);

        return $images->response($image);
    }
}
