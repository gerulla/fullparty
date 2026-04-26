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
            $table->unsignedInteger('draft_bench_size')->default(0)->after('draft_progress_schema');
        });

        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->unsignedInteger('bench_size')->default(0)->after('progress_schema');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_type_versions', function (Blueprint $table) {
            $table->dropColumn('bench_size');
        });

        Schema::table('activity_types', function (Blueprint $table) {
            $table->dropColumn('draft_bench_size');
        });
    }
};
