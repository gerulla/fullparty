<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->string('forked_tower_magic_data_source')->default('fflogs')->after('data_source');
        });
    }

    public function down(): void
    {
        Schema::table('occult_progress', function (Blueprint $table) {
            $table->dropColumn('forked_tower_magic_data_source');
        });
    }
};
