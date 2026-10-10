<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('discord_login_welcome_recorded_at')->nullable();
        });

        // Do not send retrospective welcomes to accounts already connected to Discord.
        DB::table('users')
            ->whereIn('id', DB::table('social_accounts')->select('user_id')->where('provider', 'discord'))
            ->orWhereIn('id', DB::table('audit_logs')->select('actor_user_id')
                ->where('action', 'user.logged_in')->where('metadata->provider', 'discord'))
            ->update(['discord_login_welcome_recorded_at' => now()]);

        // Preserve the existing Accounts capability when adding its new event.
        DB::table('integration_clients')->where('type', 'discord_bot')->orderBy('id')->each(function ($client) {
            $events = json_decode($client->allowed_events ?? '[]', true) ?? [];
            if (in_array('discord.user_app.installed', $events, true) && ! in_array('user.discord_login', $events, true)) {
                $events[] = 'user.discord_login';
                DB::table('integration_clients')->where('id', $client->id)->update(['allowed_events' => json_encode($events)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('integration_clients')->where('type', 'discord_bot')->orderBy('id')->each(function ($client) {
            $events = json_decode($client->allowed_events ?? '[]', true) ?? [];
            DB::table('integration_clients')->where('id', $client->id)->update([
                'allowed_events' => json_encode(array_values(array_diff($events, ['user.discord_login']))),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('discord_login_welcome_recorded_at');
        });
    }
};
