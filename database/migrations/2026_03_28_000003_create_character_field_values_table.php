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
        Schema::create('character_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->foreignId('character_field_definition_id')->constrained()->onDelete('cascade');
            $table->text('value')->nullable(); // Store all values as text, cast in model
            $table->timestamps();

            // Composite unique to prevent duplicate field values per character
            $table->unique(['character_id', 'character_field_definition_id'], 'character_field_unique');

            // Indexes
            $table->index('character_id');
            $table->index('character_field_definition_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_field_values');
    }
};
