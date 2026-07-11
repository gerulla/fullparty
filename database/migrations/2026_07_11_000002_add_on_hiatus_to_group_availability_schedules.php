<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_availability_schedules', function (Blueprint $table) {
            $table->boolean('on_hiatus')->default(false)->after('lock_weekends');
        });
    }

    public function down(): void
    {
        Schema::table('group_availability_schedules', function (Blueprint $table) {
            $table->dropColumn('on_hiatus');
        });
    }
};
