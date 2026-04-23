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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('activity_type_version_id')->constrained('activity_type_versions')->restrictOnDelete();
            $table->foreignId('organized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->json('settings')->nullable();
            $table->string('progress_entry_mode')->nullable();
            $table->text('progress_link_url')->nullable();
            $table->text('progress_notes')->nullable();
            $table->string('furthest_progress_key')->nullable();
            $table->decimal('furthest_progress_percent', 5, 2)->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('progress_recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('progress_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
