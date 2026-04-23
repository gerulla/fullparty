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
        Schema::create('activity_application_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_application_id')->constrained()->cascadeOnDelete();
            $table->string('question_key');
            $table->json('question_label');
            $table->string('question_type');
            $table->string('source')->nullable();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['activity_application_id', 'question_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_application_answers');
    }
};
