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
        Schema::table('activity_types', function (Blueprint $table) {
            $table->json('draft_prog_points')->nullable()->after('draft_progress_schema');
        });

        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->json('prog_points')->nullable()->after('progress_schema');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->dropColumn('prog_points');
        });

        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropColumn('draft_prog_points');
        });
    }
};
