<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('method');
            $table->string('history_choice')->nullable();
            $table->string('upcoming_choice')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['to_user_id', 'completed_at']);
        });
        Schema::create('character_transfer_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('activity_applications')->nullOnDelete();
            $table->string('category');
            $table->string('choice')->nullable();
            $table->json('slot_ids');
            $table->json('assignment_ids');
            $table->unique(['character_transfer_id', 'activity_id']);
            $table->index(['character_id', 'activity_id', 'choice']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_transfer_runs');
        Schema::dropIfExists('character_transfers');
    }
};
