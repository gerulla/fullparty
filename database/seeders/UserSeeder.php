<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    private const GENERATED_USER_COUNT = 320;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seededUser = $this->seededUserConfig();

        $adminUserId = DB::table('users')->insertGetId([
            'id' => 1,
            'name' => $seededUser['name'],
            'email' => $seededUser['email'],
            'email_verified_at' => '2026-04-13 15:59:47',
            'password' => null,
            'avatar_url' => $seededUser['avatar_url'],
            'last_login_at' => null,
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
            'remember_token' => null,
            'created_at' => '2026-04-13 15:59:47',
            'updated_at' => '2026-04-13 15:59:47',
        ]);

        $this->syncPrimaryKeySequence('users');

        $generatedUsers = User::factory()
            ->count(self::GENERATED_USER_COUNT)
            ->create();

        $generatedUsers->each(function (User $user): void {
            Character::factory()
                ->for($user)
                ->primary()
                ->create();
        });

        DB::table('social_accounts')->insert([
            'id' => 1,
            'user_id' => $adminUserId,
            'provider' => 'discord',
            'provider_user_id' => $seededUser['discord_user_id'],
            'provider_name' => $seededUser['discord_name'],
            'provider_email' => $seededUser['discord_email'],
            'avatar_url' => $seededUser['discord_avatar_url'],
            'access_token' => $seededUser['discord_access_token'],
            'refresh_token' => $seededUser['discord_refresh_token'],
            'provider_data' => json_encode([
                'name' => $seededUser['discord_name'],
                'avatar' => $seededUser['discord_avatar_url'],
                'nickname' => $seededUser['discord_nickname'],
            ]),
            'expires_at' => '2026-04-20 15:59:47',
            'created_at' => '2026-04-13 15:59:47',
            'updated_at' => '2026-04-13 15:59:47',
        ]);

        $this->syncPrimaryKeySequence('characters');
        $this->syncPrimaryKeySequence('social_accounts');
    }

    /**
     * @return array<string, string|null>
     */
    private function seededUserConfig(): array
    {
        return [
            'name' => env('SEED_USER_1_NAME', 'name'),
            'email' => env('SEED_USER_1_EMAIL', 'email@test.com'),
            'avatar_url' => env('SEED_USER_1_AVATAR_URL', 'laugh.png'),
            'discord_user_id' => env('SEED_USER_1_DISCORD_USER_ID', '12312232213221213123221'),
            'discord_name' => env('SEED_USER_1_DISCORD_NAME', 'name'),
            'discord_email' => env('SEED_USER_1_DISCORD_EMAIL', 'email@test.com'),
            'discord_avatar_url' => env('SEED_USER_1_DISCORD_AVATAR_URL', 'laugh.png'),
            'discord_access_token' => $this->nullableEnv('SEED_USER_1_DISCORD_ACCESS_TOKEN'),
            'discord_refresh_token' => $this->nullableEnv('SEED_USER_1_DISCORD_REFRESH_TOKEN'),
            'discord_nickname' => env('SEED_USER_1_DISCORD_NICKNAME', 'name'),
        ];
    }

    private function nullableEnv(string $key): ?string
    {
        $value = env($key);

        return $value === false || $value === '' ? null : $value;
    }

    private function syncPrimaryKeySequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('{$table}', 'id'),
                COALESCE((SELECT MAX(id) FROM {$table}), 1),
                true
            )
        ");
    }
}
