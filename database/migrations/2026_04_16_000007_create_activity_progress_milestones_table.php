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
        Schema::create('activity_progress_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('milestone_key');
            $table->json('milestone_label');
            $table->unsignedInteger('sort_order');
            $table->unsignedInteger('kills')->default(0);
            $table->decimal('best_progress_percent', 5, 2)->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'milestone_key']);
            $table->index(['activity_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_progress_milestones');
    }
};
