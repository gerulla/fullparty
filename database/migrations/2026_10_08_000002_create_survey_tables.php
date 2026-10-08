<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_forms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_open')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('survey_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->unique(['survey_form_id', 'number']);
        });
        Schema::table('survey_forms', function (Blueprint $table) {
            $table->foreignId('published_version_id')->nullable()->constrained('survey_form_versions')->nullOnDelete();
        });
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('survey_form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('respondent_key', 64);
            $table->uuid('submission_key');
            $table->json('answers');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['survey_form_id', 'submission_key']);
            $table->index(['survey_form_id', 'respondent_key']);
            $table->index(['survey_form_version_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
        Schema::table('survey_forms', fn (Blueprint $table) => $table->dropConstrainedForeignId('published_version_id'));
        Schema::dropIfExists('survey_form_versions');
        Schema::dropIfExists('survey_forms');
    }
};
