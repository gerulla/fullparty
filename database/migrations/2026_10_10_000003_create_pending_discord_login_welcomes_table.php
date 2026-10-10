<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_discord_login_welcomes', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('delivery_id')->unique();
            $table->string('discord_user_id');
            $table->string('locale', 5);
            $table->timestamp('logged_in_at');
            $table->timestamp('available_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_discord_login_welcomes');
    }
};
