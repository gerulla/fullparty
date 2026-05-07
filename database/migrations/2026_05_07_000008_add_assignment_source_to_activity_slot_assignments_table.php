<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_slot_assignments', function (Blueprint $table) {
            $table->string('assignment_source')->default('application')->after('application_id');
        });

        DB::table('activity_slot_assignments')
            ->whereNull('application_id')
            ->update(['assignment_source' => 'manual']);

        DB::table('activity_slot_assignments')
            ->whereNotNull('application_id')
            ->update(['assignment_source' => 'application']);
    }

    public function down(): void
    {
        Schema::table('activity_slot_assignments', function (Blueprint $table) {
            $table->dropColumn('assignment_source');
        });
    }
};
