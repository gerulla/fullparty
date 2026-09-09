<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->boolean('is_duelist')->default(false);
            $table->boolean('is_trapper')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->dropColumn(['is_duelist', 'is_trapper']);
        });
    }
};
