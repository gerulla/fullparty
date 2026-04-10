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
        Schema::create('character_phantom_job', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->foreignId('phantom_job_id')->constrained()->onDelete('cascade');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['character_id', 'phantom_job_id'], 'character_phantom_job_unique');
            $table->index(['character_id', 'is_preferred']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_phantom_job');
    }
};
