<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('run_and_reminder_notifications')->default(true);
            $table->boolean('group_update_notifications')->default(true);
            $table->boolean('assignment_notifications')->default(true);
            $table->boolean('account_character_notifications')->default(true);
            $table->boolean('system_notice_notifications')->default(false);
        });

        DB::table('users')->update([
            'run_and_reminder_notifications' => DB::raw('run_reminders'),
            'group_update_notifications' => DB::raw('group_updates'),
            'assignment_notifications' => DB::raw('assignment_updates'),
            'account_character_notifications' => true,
            'system_notice_notifications' => false,
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'run_reminders',
                'group_updates',
                'assignment_updates',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('run_reminders')->default(true);
            $table->boolean('group_updates')->default(true);
            $table->boolean('assignment_updates')->default(true);
        });

        DB::table('users')->update([
            'run_reminders' => DB::raw('run_and_reminder_notifications'),
            'group_updates' => DB::raw('group_update_notifications'),
            'assignment_updates' => DB::raw('assignment_notifications'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'run_and_reminder_notifications',
                'group_update_notifications',
                'assignment_notifications',
                'account_character_notifications',
                'system_notice_notifications',
            ]);
        });
    }
};
