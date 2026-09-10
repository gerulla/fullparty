<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discord_guild_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('integration_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('discord_guild_integrations', function (Blueprint $table) {
            $table->foreignId('integration_client_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
