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
        Schema::create('activity_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('group_key');
            $table->json('group_label');
            $table->string('slot_key');
            $table->json('slot_label')->nullable();
            $table->unsignedInteger('position_in_group');
            $table->unsignedInteger('sort_order');
            $table->foreignId('assigned_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['activity_id', 'slot_key']);
            $table->index(['activity_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_slots');
    }
};
