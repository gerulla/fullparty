<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_quick_create_shortcuts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('time_of_day', 5);
            $table->string('time_mode', 16);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->unique(['group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_quick_create_shortcuts');
    }
};
