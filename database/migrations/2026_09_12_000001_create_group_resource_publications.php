<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_resource_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('group_resource_revisions')->cascadeOnDelete();
            $table->foreignId('publisher_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('publisher');
            $table->timestamp('created_at');
            $table->index(['revision_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_resource_publications');
    }
};
