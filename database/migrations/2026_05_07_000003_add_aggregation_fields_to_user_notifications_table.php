<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->string('aggregate_key')->nullable()->after('user_id');
            $table->unsignedInteger('aggregate_count')->default(1)->after('aggregate_key');

            $table->index(['user_id', 'aggregate_key', 'read_at'], 'user_notifications_aggregate_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_aggregate_lookup_idx');
            $table->dropColumn(['aggregate_key', 'aggregate_count']);
        });
    }
};
