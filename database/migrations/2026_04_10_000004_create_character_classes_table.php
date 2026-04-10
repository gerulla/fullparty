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
        Schema::create('character_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('shorthand', 20)->unique();
            $table->string('icon_url')->nullable();
            $table->string('flaticon_url')->nullable();
            $table->enum('role', ['healer', 'tank', 'melee dps', 'magic ranged dps', 'physical ranged dps']);
            $table->timestamps();

            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_classes');
    }
};
