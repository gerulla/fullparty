<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductionAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('SEED_ADMIN_EMAIL', 'admin@fullparty.local');
        $password = env('SEED_ADMIN_PASSWORD');

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('SEED_ADMIN_NAME', 'FullParty Admin'),
                'email_verified_at' => now(),
                'password' => filled($password) ? Hash::make((string) $password) : null,
                'avatar_url' => env('SEED_ADMIN_AVATAR_URL'),
                'is_admin' => true,
                'public_profile' => true,
                'public_characters' => true,
                'run_and_reminder_notifications' => true,
                'application_notifications' => true,
                'group_update_notifications' => true,
                'assignment_notifications' => true,
                'account_character_notifications' => true,
                'system_notice_notifications' => false,
                'email_notifications' => false,
                'discord_notifications' => false,
            ],
        );
    }
}
