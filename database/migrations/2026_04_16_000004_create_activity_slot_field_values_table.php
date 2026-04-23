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
        Schema::create('activity_slot_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_slot_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->json('field_label');
            $table->string('field_type');
            $table->string('source')->nullable();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['activity_slot_id', 'field_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_slot_field_values');
    }
};
