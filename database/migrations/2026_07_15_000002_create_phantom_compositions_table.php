<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phantom_compositions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('content_key', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('rules');
            $table->timestamps();

            $table->index(['group_id', 'content_key', 'is_active']);
            $table->index(['group_id', 'content_key', 'is_default']);
            $table->index(['group_id', 'content_key', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phantom_compositions');
    }
};
