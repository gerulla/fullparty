<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raid_plan_mechanics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raid_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('raid_plan_mechanics')
                ->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('type', 16)->default('fixed');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('selection_weight')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->json('timeline')->default('{}');
            $table->unsignedSmallInteger('timeline_schema_version')->default(1);
            $table->timestamps();

            $table->index(['raid_plan_id', 'parent_id', 'sort_order']);
            $table->index(['raid_plan_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raid_plan_mechanics');
    }
};
