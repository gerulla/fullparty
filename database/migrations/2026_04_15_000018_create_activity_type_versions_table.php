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
        Schema::create('activity_type_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('name');
            $table->json('description')->nullable();
            $table->json('layout_schema');
            $table->json('slot_schema');
            $table->json('application_schema');
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['activity_type_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_type_versions');
    }
};
