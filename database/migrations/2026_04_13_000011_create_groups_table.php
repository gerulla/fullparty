<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->string('discord_invite_url')->nullable();
            $table->string('datacenter');
            $table->boolean('is_public')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->string('slug', 8)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
