<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->boolean('is_host')->default(false)->after('assigned_by_user_id');
            $table->boolean('is_raid_leader')->default(false)->after('is_host');
        });
    }

    public function down(): void
    {
        Schema::table('activity_slots', function (Blueprint $table) {
            $table->dropColumn(['is_host', 'is_raid_leader']);
        });
    }
};
