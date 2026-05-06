<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('applicant_lodestone_id')->nullable()->after('selected_character_id');
            $table->string('applicant_character_name')->nullable()->after('applicant_lodestone_id');
            $table->string('applicant_world')->nullable()->after('applicant_character_name');
            $table->string('applicant_datacenter')->nullable()->after('applicant_world');
            $table->string('applicant_avatar_url')->nullable()->after('applicant_datacenter');
        });

        $applications = DB::table('activity_applications')
            ->leftJoin('characters', 'characters.id', '=', 'activity_applications.selected_character_id')
            ->select([
                'activity_applications.id',
                'characters.lodestone_id',
                'characters.name',
                'characters.world',
                'characters.datacenter',
                'characters.avatar_url',
            ])
            ->get();

        foreach ($applications as $application) {
            if (!$application->lodestone_id) {
                continue;
            }

            DB::table('activity_applications')
                ->where('id', $application->id)
                ->update([
                    'applicant_lodestone_id' => $application->lodestone_id,
                    'applicant_character_name' => $application->name,
                    'applicant_world' => $application->world,
                    'applicant_datacenter' => $application->datacenter,
                    'applicant_avatar_url' => $application->avatar_url,
                ]);
        }

        Schema::table('activity_applications', function (Blueprint $table) {
            $table->unique(['activity_id', 'applicant_lodestone_id'], 'activity_applications_activity_applicant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_applications', function (Blueprint $table) {
            $table->dropUnique('activity_applications_activity_applicant_unique');
            $table->dropColumn([
                'applicant_lodestone_id',
                'applicant_character_name',
                'applicant_world',
                'applicant_datacenter',
                'applicant_avatar_url',
            ]);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
