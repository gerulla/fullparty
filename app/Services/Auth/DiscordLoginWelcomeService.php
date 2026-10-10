<?php

namespace App\Services\Auth;

use App\Jobs\SendDiscordLoginWelcomeJob;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class DiscordLoginWelcomeService
{
    public function recordFirstLogin(User $user, string $discordUserId): void
    {
        if ($user->banned_at !== null || ! $user->hasVerifiedEmail()
            || ! $user->socialAccounts()->where('provider', 'discord')->where('provider_user_id', $discordUserId)->exists()) {
            return;
        }

        DB::transaction(function () use ($user, $discordUserId): void {
            $recordedAt = now();
            $claimed = User::query()->whereKey($user->id)->whereNull('discord_login_welcome_recorded_at')
                ->update(['discord_login_welcome_recorded_at' => $recordedAt]);

            if ($claimed === 1) {
                // Keep the intent atomic with the claim; a queue outage must not consume it.
                DB::table('pending_discord_login_welcomes')->insert([
                    'user_id' => $user->id,
                    'delivery_id' => (string) Str::uuid(),
                    'discord_user_id' => $discordUserId,
                    'locale' => app()->getLocale(),
                    'logged_in_at' => $recordedAt,
                    'available_at' => $recordedAt,
                ]);

                DB::afterCommit(fn () => $this->enqueuePending($user->id));
            }
        });
    }

    public function dispatchPending(): int
    {
        $queued = 0;

        DB::table('pending_discord_login_welcomes')->where('available_at', '<=', now())
            ->orderBy('user_id')->chunkById(100, function ($welcomes) use (&$queued): void {
                foreach ($welcomes as $welcome) {
                    $queued += (int) $this->enqueuePending($welcome->user_id);
                }
            }, 'user_id');

        return $queued;
    }

    private function enqueuePending(int $userId): bool
    {
        try {
            // A short lease prevents competing schedulers from enqueueing the same intent.
            // If this process stops, the pending intent becomes eligible again automatically.
            $claimed = DB::table('pending_discord_login_welcomes')->where('user_id', $userId)
                ->where('available_at', '<=', now())->update(['available_at' => now()->addMinutes(5)]);
            if ($claimed !== 1) {
                return false;
            }

            $welcome = DB::table('pending_discord_login_welcomes')->where('user_id', $userId)->first();
            if (! $welcome) {
                return false;
            }

            Bus::dispatch((new SendDiscordLoginWelcomeJob(
                $welcome->user_id,
                $welcome->discord_user_id,
                Carbon::parse($welcome->logged_in_at)->toIso8601String(),
                $welcome->locale,
                $welcome->delivery_id,
            ))->afterCommit());

            DB::table('pending_discord_login_welcomes')->where('user_id', $userId)
                ->where('delivery_id', $welcome->delivery_id)->delete();

            return true;
        } catch (Throwable $exception) {
            // Login succeeds independently; the scheduler retries after the lease expires.
            Log::warning('Discord login welcome could not be queued; its pending intent will be retried.', [
                'user_id' => $userId,
                'exception_type' => $exception::class,
            ]);

            return false;
        }
    }
}
