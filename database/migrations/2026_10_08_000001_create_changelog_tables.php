<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->json('translations');
            $table->string('version_mode', 20);
            $table->string('version_from', 50)->nullable();
            $table->string('version_to', 50);
            $table->string('commit', 100)->nullable();
            $table->foreignId('baseline_id')->nullable()->constrained('changelog_entries')->nullOnDelete();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_published', 'published_at', 'id']);
        });
        // A single row serializes publishing and versions the public cache.
        Schema::create('changelog_publication_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('revision')->default(0);
        });
        DB::table('changelog_publication_state')->insert(['id' => 1, 'revision' => 0]);
        Schema::create('changelog_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('changelog_entry_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_reads');
        Schema::dropIfExists('changelog_publication_state');
        Schema::dropIfExists('changelog_entries');
    }
};
