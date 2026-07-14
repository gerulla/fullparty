<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bozja_items', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('category', 40);
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('classification', 40);
            $table->unsignedSmallInteger('cache_weight')->default(0);
            $table->string('icon_url')->nullable();
            $table->json('source_payload')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active', 'sort_order']);
            $table->index('classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bozja_items');
    }
};
