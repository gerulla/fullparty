<?php

namespace App\Http\Controllers;

use App\Models\DiscordGuildIntegration;
use App\Services\Integrations\DiscordGuildLinkService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminDiscordGuildIntegrationController extends Controller
{
    public function __construct(
        private readonly DiscordGuildLinkService $discordGuildLinkService,
    ) {}

    public function index(): Response
    {
        $this->authorizeAdminAccess();

        return Inertia::render('Admin/DiscordGuildIntegrations', [
            'links' => DiscordGuildIntegration::query()
                ->whereNotNull('group_id')
                ->whereNull('removed_at')
                ->with('group:id,name,slug,datacenter')
                ->orderBy('name')
                ->orderBy('discord_guild_id')
                ->get()
                ->map(fn (DiscordGuildIntegration $integration): array => [
                    'id' => $integration->id,
                    'discord_guild_id' => $integration->discord_guild_id,
                    'name' => $integration->name,
                    'icon_url' => $integration->icon_url,
                    'guild_installed_at' => $integration->guild_installed_at?->toIso8601String(),
                    'linked_at' => $integration->updated_at?->toIso8601String(),
                    'group' => $integration->group ? [
                        'id' => $integration->group->id,
                        'name' => $integration->group->name,
                        'slug' => $integration->group->slug,
                        'datacenter' => $integration->group->datacenter,
                    ] : null,
                ])
                ->values(),
        ]);
    }

    public function destroy(DiscordGuildIntegration $discordGuildIntegration): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $this->discordGuildLinkService->unlink(
            integration: $discordGuildIntegration,
            actor: auth()->user(),
            forcedByAdmin: true,
        );

        return back()->with('success', 'discord_guild_force_unlinked');
    }

    private function authorizeAdminAccess(): void
    {
        if (! auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
