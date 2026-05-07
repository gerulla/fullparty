<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_follows', function (Blueprint $table) {
            $table->boolean('notifications_enabled')
                ->default(true)
                ->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_follows', function (Blueprint $table) {
            $table->dropColumn('notifications_enabled');
        });
    }
};
