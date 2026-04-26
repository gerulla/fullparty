<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_types', function (Blueprint $table) {
            $table->unsignedInteger('draft_fflogs_zone_id')->nullable()->after('draft_prog_points');
        });

        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->unsignedInteger('fflogs_zone_id')->nullable()->after('prog_points');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->dropColumn('fflogs_zone_id');
        });

        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropColumn('draft_fflogs_zone_id');
        });
    }
};
