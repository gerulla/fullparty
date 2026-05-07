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
        Schema::create('system_banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('message');
            $table->string('action_label', 40)->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_banners');
    }
};
