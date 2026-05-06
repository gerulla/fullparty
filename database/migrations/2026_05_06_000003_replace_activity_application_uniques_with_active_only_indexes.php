<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_applications', function ($table) {
            $table->dropUnique('activity_applications_activity_id_user_id_unique');
            $table->dropUnique('activity_applications_activity_applicant_unique');
        });

        $driver = DB::getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                "create unique index activity_applications_active_user_unique
                on activity_applications (activity_id, user_id)
                where user_id is not null and status <> 'withdrawn'"
            );

            DB::statement(
                "create unique index activity_applications_active_applicant_unique
                on activity_applications (activity_id, applicant_lodestone_id)
                where applicant_lodestone_id is not null and status <> 'withdrawn'"
            );

            return;
        }

        DB::statement(
            'create index activity_applications_active_user_unique on activity_applications (activity_id, user_id)'
        );
        DB::statement(
            'create index activity_applications_active_applicant_unique on activity_applications (activity_id, applicant_lodestone_id)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists activity_applications_active_user_unique');
        DB::statement('drop index if exists activity_applications_active_applicant_unique');

        Schema::table('activity_applications', function ($table) {
            $table->unique(['activity_id', 'user_id']);
            $table->unique(['activity_id', 'applicant_lodestone_id'], 'activity_applications_activity_applicant_unique');
        });
    }
};
