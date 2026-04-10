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
        Schema::create('occult_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete()->unique();
            $table->unsignedInteger('knowledge_level')->default(0);
            $table->unsignedInteger('demon_tablet_kills')->default(0);
            $table->unsignedInteger('dead_stars_kills')->default(0);
            $table->unsignedInteger('marble_dragon_kills')->default(0);
            $table->unsignedInteger('magitaur_kills')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('occult_progress');
    }
};
