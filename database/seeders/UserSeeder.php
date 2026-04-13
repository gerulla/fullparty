<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $seededUser = $this->seededUserConfig();

        DB::table('users')->upsert([
            [
                'id' => 1,
                'name' => $seededUser['name'],
                'email' => $seededUser['email'],
                'email_verified_at' => '2026-04-13 15:59:47',
                'password' => null,
                'avatar_url' => $seededUser['avatar_url'],
                'last_login_at' => null,
                'is_admin' => false,
                'public_profile' => true,
                'public_characters' => true,
                'run_reminders' => true,
                'application_notifications' => true,
                'group_updates' => true,
                'assignment_updates' => true,
                'email_notifications' => false,
                'discord_notifications' => false,
                'remember_token' => null,
                'created_at' => '2026-04-13 15:59:47',
                'updated_at' => '2026-04-13 15:59:47',
            ],
            $this->randomUserRow(2, $now->copy()->subMinutes(14)),
            $this->randomUserRow(3, $now->copy()->subMinutes(7)),
        ], ['id'], [
            'name',
            'email',
            'email_verified_at',
            'password',
            'avatar_url',
            'last_login_at',
            'is_admin',
            'public_profile',
            'public_characters',
            'run_reminders',
            'application_notifications',
            'group_updates',
            'assignment_updates',
            'email_notifications',
            'discord_notifications',
            'remember_token',
            'created_at',
            'updated_at',
        ]);

        DB::table('social_accounts')->upsert([
            [
                'id' => 1,
                'user_id' => 1,
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
            ],
        ], ['id'], [
            'user_id',
            'provider',
            'provider_user_id',
            'provider_name',
            'provider_email',
            'avatar_url',
            'access_token',
            'refresh_token',
            'provider_data',
            'expires_at',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function randomUserRow(int $id, $timestamp): array
    {
        return [
            'id' => $id,
            'name' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => $timestamp,
            'password' => Hash::make('password'),
            'avatar_url' => fake()->imageUrl(256, 256, 'people'),
            'last_login_at' => $timestamp,
            'is_admin' => false,
            'public_profile' => true,
            'public_characters' => true,
            'run_reminders' => true,
            'application_notifications' => true,
            'group_updates' => true,
            'assignment_updates' => true,
            'email_notifications' => fake()->boolean(),
            'discord_notifications' => fake()->boolean(),
            'remember_token' => Str::random(10),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function seededUserConfig(): array
    {
        return [
            'name' => env('SEED_USER_1_NAME', 'yenpress'),
            'email' => env('SEED_USER_1_EMAIL', 'egidiufarcas@maze.ws'),
            'avatar_url' => env('SEED_USER_1_AVATAR_URL', 'https://cdn.discordapp.com/avatars/182520880277094400/253a7174a3523a566d5728ed8b9c59c4.jpg'),
            'discord_user_id' => env('SEED_USER_1_DISCORD_USER_ID', '182520880277094400'),
            'discord_name' => env('SEED_USER_1_DISCORD_NAME', 'yenpress'),
            'discord_email' => env('SEED_USER_1_DISCORD_EMAIL', 'egidiufarcas@maze.ws'),
            'discord_avatar_url' => env('SEED_USER_1_DISCORD_AVATAR_URL', 'https://cdn.discordapp.com/avatars/182520880277094400/253a7174a3523a566d5728ed8b9c59c4.jpg'),
            'discord_access_token' => $this->nullableEnv('SEED_USER_1_DISCORD_ACCESS_TOKEN'),
            'discord_refresh_token' => $this->nullableEnv('SEED_USER_1_DISCORD_REFRESH_TOKEN'),
            'discord_nickname' => env('SEED_USER_1_DISCORD_NICKNAME', 'yenpress'),
        ];
    }

    private function nullableEnv(string $key): ?string
    {
        $value = env($key);

        return $value === false || $value === '' ? null : $value;
    }
}
