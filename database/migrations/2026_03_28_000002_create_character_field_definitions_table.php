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
        Schema::create('character_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Display label, e.g., "Phantom Level"
            $table->string('slug')->unique(); // Key for storage, e.g., "phantom_level"
            $table->enum('type', ['text', 'number', 'boolean'])->default('text');
            $table->text('description')->nullable();
            $table->json('validation_rules')->nullable(); // Store validation config as JSON
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('slug');
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_field_definitions');
    }
};
