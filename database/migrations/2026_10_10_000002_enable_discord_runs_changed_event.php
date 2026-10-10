<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $runEvents = [
            'discord.guild.run_starting_soon', 'discord.guild.run_starting_now',
            'discord.guild.run_completed', 'discord.guild.run_cancelled', 'discord.guild.run_participant_sync',
        ];

        // Existing clients with the Runs capability also receive list refreshes.
        DB::table('integration_clients')->where('type', 'discord_bot')->orderBy('id')->each(function ($client) use ($runEvents) {
            $events = json_decode($client->allowed_events ?? '[]', true) ?? [];
            if (array_intersect($events, $runEvents) !== [] && ! in_array('discord.guild.runs_changed', $events, true)) {
                $events[] = 'discord.guild.runs_changed';
                DB::table('integration_clients')->where('id', $client->id)->update(['allowed_events' => json_encode($events)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('integration_clients')->where('type', 'discord_bot')->orderBy('id')->each(function ($client) {
            $events = json_decode($client->allowed_events ?? '[]', true) ?? [];
            DB::table('integration_clients')->where('id', $client->id)->update([
                'allowed_events' => json_encode(array_values(array_diff($events, ['discord.guild.runs_changed']))),
            ]);
        });
    }
};
