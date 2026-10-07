<?php

namespace App\Http\Controllers\Api\Members;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends Controller
{
    public function show(Request $request, NotificationPreferenceSettingsService $preferences): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            ...$user->only(['id', 'name', 'avatar_url', 'public_profile', 'public_characters', 'time_display_mode', 'homepage_group_id',
                'application_notifications', 'run_and_reminder_notifications', 'group_update_notifications', 'assignment_notifications',
                'account_character_notifications', 'system_notice_notifications', 'email_notifications', 'discord_notifications']),
            'notification_preferences' => $preferences->serializeUserPreferences($user),
            'profile' => $user->homeProfile?->only(['display_character_class_id', 'description', 'background_image_url']),
        ]]);
    }
}
