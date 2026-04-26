<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DevelopmentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's development/demo data.
     */
    public function run(): void
    {
        fake()->seed(20260425);
        mt_srand(20260425);

        $this->clearDevelopmentData();

        $this->call([
            UserSeeder::class,
            ProductionSeeder::class,
            GroupSeeder::class,
            ActivitySeeder::class,
        ]);
    }

    private function clearDevelopmentData(): void
    {
        DB::transaction(function () {
            DB::table('activity_slot_field_values')->delete();
            DB::table('activity_slots')->delete();
            DB::table('activity_application_answers')->delete();
            DB::table('activity_applications')->delete();
            DB::table('activity_progress_milestones')->delete();
            DB::table('activities')->delete();
            DB::table('scheduled_runs')->delete();
            DB::table('group_invites')->delete();
            DB::table('group_bans')->delete();
            DB::table('group_memberships')->delete();
            DB::table('groups')->delete();
            DB::table('social_accounts')->delete();
            DB::table('occult_progress')->delete();
            DB::table('character_class_character')->delete();
            DB::table('character_phantom_job')->delete();
            DB::table('character_field_values')->delete();
            DB::table('characters')->delete();
            DB::table('users')->delete();
        });
    }
}
