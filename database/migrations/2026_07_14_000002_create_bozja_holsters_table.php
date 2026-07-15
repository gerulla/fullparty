<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bozja_holsters', function (Blueprint $table) {
            $table->id();
            $table->json('name')->nullable();
            $table->unsignedTinyInteger('max_capacity')->default(99);
            $table->text('notes')->nullable();
            $table->longText('guide')->nullable();
            $table->timestamps();
        });

        Schema::create('bozja_holster_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bozja_holster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bozja_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quantity');
            $table->timestamps();

            $table->unique(['bozja_holster_id', 'bozja_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bozja_holster_items');
        Schema::dropIfExists('bozja_holsters');
    }
};
