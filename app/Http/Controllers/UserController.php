<?php

namespace App\Http\Controllers;

use App\Events\DiscordUserAppDisconnected;
use App\Http\Requests\ChangePasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Services\Notifications\NotificationPreferenceSettingsService;
use App\Services\Notifications\NotificationService;
use App\Services\Users\UserAccountDeletionService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\NotificationTopic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private const PROFILE_IMAGE_DIRECTORY = 'users';

    private const ACCOUNT_SETTING_LABEL_KEYS = [
        'name' => 'general.username',
        'avatar_url' => 'general.profile_picture',
        'password' => 'settings.account.password',
    ];

    private const NOTIFICATION_SETTING_LABEL_KEYS = [
        'application_notifications' => 'settings.notifications.applications',
        'run_and_reminder_notifications' => 'settings.notifications.runs_and_reminders',
        'group_update_notifications' => 'settings.notifications.group_updates',
        'assignment_notifications' => 'settings.notifications.assignments',
        'account_character_notifications' => 'settings.notifications.account_character_updates',
        'system_notice_notifications' => 'settings.notifications.system_notices',
    ];

    private const NOTIFICATION_CHANNEL_LABEL_KEYS = [
        'email_notifications' => 'settings.notifications.email_notifications',
        'discord_notifications' => 'settings.notifications.discord_notifications',
    ];

    private const PRIVACY_SETTING_LABEL_KEYS = [
        'public_profile' => 'settings.privacy.profile_visibility',
        'public_characters' => 'settings.privacy.show_character_data',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NotificationService $notificationService,
        private readonly NotificationPreferenceSettingsService $notificationPreferenceSettingsService,
        private readonly UserAccountDeletionService $userAccountDeletionService,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly ManagedImageStorage $managedImageStorage,
    ) {}

    public function changeUsername(Request $request)
    {
        $this->requestTextInputSanitizer->sanitize($request, ['username']);

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $originalValues = [
            'name' => $user->name,
        ];

        $user->update(['name' => $validated['username']]);

        $updatedValues = [
            'name' => $user->fresh()->name,
        ];

        $changes = $this->buildSettingsChanges($originalValues, $updatedValues);

        $this->logUserSettingsChange(
            user: $user->fresh(),
            action: 'user.settings.username_updated',
            message: 'audit_log.events.user.settings.username_updated',
            changes: $changes,
        );

        $this->notifyUserAboutSettingsChange(
            user: $user->fresh(),
            type: 'user.settings.username_updated',
            titleKey: 'notifications.user.settings.username_updated.title',
            bodyKey: 'notifications.user.settings.username_updated.body',
            changes: $changes,
            fieldLabelKeys: self::ACCOUNT_SETTING_LABEL_KEYS,
        );

        return redirect()
            ->route('settings')
            ->with('success', ['username_updated', $validated['username']]);
    }

    public function changeProfilePicture(Request $request): RedirectResponse
    {
        $request->validate([
            'profile_picture' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $originalValues = [
            'avatar_url' => $user->avatar_url,
        ];

        $avatarUrl = $this->managedImageStorage->replaceUploadedImageIfPresent(
            currentUrl: $user->avatar_url,
            file: $request->file('profile_picture'),
            directory: self::PROFILE_IMAGE_DIRECTORY,
            shouldProcess: true,
        );

        $user->update([
            'avatar_url' => $avatarUrl,
        ]);

        $updatedUser = $user->fresh();
        $updatedValues = [
            'avatar_url' => $updatedUser->avatar_url,
        ];

        $changes = $this->buildSettingsChanges($originalValues, $updatedValues);

        $this->logUserSettingsChange(
            user: $updatedUser,
            action: 'user.settings.profile_picture_updated',
            message: 'audit_log.events.user.settings.profile_picture_updated',
            changes: $changes,
        );

        $this->notifyUserAboutSettingsChange(
            user: $updatedUser,
            type: 'user.settings.profile_picture_updated',
            titleKey: 'notifications.user.settings.profile_picture_updated.title',
            bodyKey: 'notifications.user.settings.profile_picture_updated.body',
            changes: $changes,
            fieldLabelKeys: self::ACCOUNT_SETTING_LABEL_KEYS,
        );

        return redirect()
            ->route('settings')
            ->with('success', ['profile_picture_updated']);
    }

    public function changeNotificationSettings(Request $request)
    {
        $validated = $request->validate([
            'application_notifications' => ['required', 'boolean'],
            'run_and_reminder_notifications' => ['required', 'boolean'],
            'group_update_notifications' => ['required', 'boolean'],
            'assignment_notifications' => ['required', 'boolean'],
            'account_character_notifications' => ['required', 'boolean'],
            'system_notice_notifications' => ['required', 'boolean'],
            'email_notifications' => ['required', 'boolean'],
            'discord_notifications' => ['required', 'boolean'],
            'notification_preferences' => ['sometimes', 'array'],
        ]);
        // Discord delivery uses the installed Discord app, not Discord as a login method.
        if (! $request->user()->discordUserIntegration()->exists()) {
            $validated['discord_notifications'] = false;
        }

        $user = $request->user();
        $originalValues = [
            'application_notifications' => $user->application_notifications,
            'run_and_reminder_notifications' => $user->run_and_reminder_notifications,
            'group_update_notifications' => $user->group_update_notifications,
            'assignment_notifications' => $user->assignment_notifications,
            'account_character_notifications' => $user->account_character_notifications,
            'system_notice_notifications' => $user->system_notice_notifications,
            'email_notifications' => $user->email_notifications,
            'discord_notifications' => $user->discord_notifications,
        ];

        $notificationPreferencesReviewedAt = $user->notification_preferences_reviewed_at ?? now();

        $notificationPreferences = $validated['notification_preferences'] ?? [];
        unset($validated['notification_preferences']);

        DB::transaction(function () use ($user, $validated, $notificationPreferencesReviewedAt, $notificationPreferences): void {
            $user->update([
                ...$validated,
                'notification_preferences_reviewed_at' => $notificationPreferencesReviewedAt,
            ]);

            if ($notificationPreferences !== []) {
                $this->notificationPreferenceSettingsService->persistUserPreferences($user, $notificationPreferences);
            }
        });

        $onboardingState = $user->onboardingState()->firstOrCreate([
            'user_id' => $user->id,
        ]);

        if ($onboardingState->notification_preferences_completed_at === null) {
            $onboardingState->update([
                'notification_preferences_completed_at' => $notificationPreferencesReviewedAt,
            ]);
        }

        $updatedUser = $user->fresh();
        $updatedValues = [
            'application_notifications' => $updatedUser->application_notifications,
            'run_and_reminder_notifications' => $updatedUser->run_and_reminder_notifications,
            'group_update_notifications' => $updatedUser->group_update_notifications,
            'assignment_notifications' => $updatedUser->assignment_notifications,
            'account_character_notifications' => $updatedUser->account_character_notifications,
            'system_notice_notifications' => $updatedUser->system_notice_notifications,
            'email_notifications' => $updatedUser->email_notifications,
            'discord_notifications' => $updatedUser->discord_notifications,
        ];
        $changes = $this->buildSettingsChanges($originalValues, $updatedValues);

        $this->logUserSettingsChange(
            user: $updatedUser,
            action: 'user.settings.notifications_updated',
            message: 'audit_log.events.user.settings.notifications_updated',
            changes: $changes,
        );

        $this->notifyUserAboutNotificationSettingChanges($updatedUser, $changes);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'notifications' => [
                    'application_notifications' => (bool) $updatedUser->application_notifications,
                    'run_and_reminder_notifications' => (bool) $updatedUser->run_and_reminder_notifications,
                    'group_update_notifications' => (bool) $updatedUser->group_update_notifications,
                    'assignment_notifications' => (bool) $updatedUser->assignment_notifications,
                    'account_character_notifications' => (bool) $updatedUser->account_character_notifications,
                    'system_notice_notifications' => (bool) $updatedUser->system_notice_notifications,
                    'email_notifications' => (bool) $updatedUser->email_notifications,
                    'discord_notifications' => (bool) $updatedUser->discord_notifications,
                    'notification_preferences_reviewed_at' => $updatedUser->notification_preferences_reviewed_at?->toIso8601String(),
                    'notification_preferences' => $this->notificationPreferenceSettingsService->serializeUserPreferences($updatedUser),
                ],
            ]);
        }

        return redirect()
            ->route('settings')
            ->with('success', ['notification_settings_updated']);

    }

    public function disconnectDiscordIntegration(Request $request): RedirectResponse
    {
        $user = $request->user();
        $integration = $user->discordUserIntegration()->first();

        if (! $integration) {
            return redirect()
                ->route('settings')
                ->with('success', ['discord_integration_disconnected']);
        }

        DiscordUserAppDisconnected::dispatch($integration->id);

        $integration->update([
            'revoked_at' => now(),
        ]);

        $user->update([
            'discord_notifications' => false,
        ]);

        $this->auditLogger->log(
            action: 'user.discord_app.disconnected',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER,
            scopeId: $user->id,
            message: 'audit_log.activity.user.discord_app.disconnected',
            actor: $user,
            subject: $user,
            metadata: [
                'discord_user_id' => $integration->discord_user_id,
            ],
        );

        return redirect()
            ->route('settings')
            ->with('success', ['discord_integration_disconnected']);
    }

    public function generateDiscordLinkToken(Request $request): RedirectResponse
    {
        $user = $request->user();
        $plainToken = Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8));
        $expiresAt = now()->addMinutes(30);

        $user->forceFill([
            'discord_link_token_hash' => hash('sha256', $plainToken),
            'discord_link_token_expires_at' => $expiresAt,
        ])->save();

        return redirect()
            ->route('settings')
            ->with('success', ['discord_user_link_token_generated'])
            ->with('flash_data', [
                'discord_user_link_token' => [
                    'token' => $plainToken,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);
    }

    public function changePrivacySettings(Request $request)
    {
        $validated = $request->validate([
            'public_profile' => ['required', 'boolean'],
            'public_characters' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $originalValues = [
            'public_profile' => $user->public_profile,
            'public_characters' => $user->public_characters,
        ];

        $user->update($validated);

        $updatedUser = $user->fresh();
        $updatedValues = [
            'public_profile' => $updatedUser->public_profile,
            'public_characters' => $updatedUser->public_characters,
        ];
        $changes = $this->buildSettingsChanges($originalValues, $updatedValues);

        $this->logUserSettingsChange(
            user: $updatedUser,
            action: 'user.settings.privacy_updated',
            message: 'audit_log.events.user.settings.privacy_updated',
            changes: $changes,
        );

        $this->notifyUserAboutSettingsChange(
            user: $updatedUser,
            type: 'user.settings.privacy_updated',
            titleKey: 'notifications.user.settings.privacy_updated.title',
            bodyKey: 'notifications.user.settings.privacy_updated.body',
            changes: $changes,
            fieldLabelKeys: self::PRIVACY_SETTING_LABEL_KEYS,
        );

        return redirect()
            ->route('settings')
            ->with('success', ['privacy_settings_updated']);
    }

    public function changeTimeDisplayPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'time_display_mode' => ['required', 'string', Rule::in(User::TIME_DISPLAY_MODES)],
        ]);

        $request->user()->update($validated);

        return response()->json([
            'success' => true,
            'time_display_mode' => $request->user()->time_display_mode,
        ]);
    }

    public function changeHomepagePreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'homepage_group_id' => [
                'nullable',
                'integer',
                Rule::exists('group_memberships', 'group_id')
                    ->where('user_id', $request->user()->id),
            ],
        ]);

        $request->user()->update($validated);

        return response()->json([
            'success' => true,
            'homepage_group_id' => $request->user()->homepage_group_id,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $this->auditLogger->log(
            action: 'user.settings.password_updated',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER,
            scopeId: $user->id,
            message: 'audit_log.events.user.settings.password_updated',
            actor: $user,
            subject: $user,
            metadata: [
                'changed_fields' => ['password'],
            ],
        );

        $this->notifyUserAboutSettingsChange(
            user: $user->fresh(),
            type: 'user.settings.password_updated',
            titleKey: 'notifications.user.settings.password_updated.title',
            bodyKey: 'notifications.user.settings.password_updated.body',
            changes: [
                'password' => [
                    'old' => null,
                    'new' => null,
                ],
            ],
            fieldLabelKeys: self::ACCOUNT_SETTING_LABEL_KEYS,
        );

        return redirect()
            ->route('settings')
            ->with('success', ['password_updated']);
    }

    public function destroyAccount(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->userAccountDeletionService->delete($user);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function logUserSettingsChange(
        User $user,
        string $action,
        string $message,
        array $changes,
    ): void {
        if ($changes === []) {
            return;
        }

        $this->auditLogger->log(
            action: $action,
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER,
            scopeId: $user->id,
            message: $message,
            actor: $user,
            subject: $user,
            metadata: [
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $originalValues
     * @param  array<string, mixed>  $updatedValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildSettingsChanges(array $originalValues, array $updatedValues): array
    {
        return collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $originalValues[$field],
                    'new' => $updatedValues[$field],
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     */
    private function notifyUserAboutNotificationSettingChanges(User $user, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $changedCategoryLabelKeys = [];
        $changedChannelLabelKeys = [];

        foreach (array_keys($changes) as $field) {
            if (isset(self::NOTIFICATION_SETTING_LABEL_KEYS[$field])) {
                $changedCategoryLabelKeys[] = self::NOTIFICATION_SETTING_LABEL_KEYS[$field];
            }

            if (isset(self::NOTIFICATION_CHANNEL_LABEL_KEYS[$field])) {
                $changedChannelLabelKeys[] = self::NOTIFICATION_CHANNEL_LABEL_KEYS[$field];
            }
        }

        $changedSettingLabelKeys = array_values([
            ...$changedCategoryLabelKeys,
            ...$changedChannelLabelKeys,
        ]);

        $this->notifyUserAboutSettingsChange(
            user: $user,
            type: 'user.settings.notifications_updated',
            titleKey: 'notifications.user.settings.notifications_updated.title',
            bodyKey: 'notifications.user.settings.notifications_updated.body',
            changes: $changes,
            fieldLabelKeys: [
                ...self::NOTIFICATION_SETTING_LABEL_KEYS,
                ...self::NOTIFICATION_CHANNEL_LABEL_KEYS,
            ],
            extraMessageParams: [
                'changed_category_label_keys' => array_values($changedCategoryLabelKeys),
                'changed_channel_label_keys' => array_values($changedChannelLabelKeys),
            ],
            extraPayload: [
                'changed_category_label_keys' => array_values($changedCategoryLabelKeys),
                'changed_channel_label_keys' => array_values($changedChannelLabelKeys),
            ],
        );
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, string>  $fieldLabelKeys
     * @param  array<string, mixed>  $extraMessageParams
     * @param  array<string, mixed>  $extraPayload
     */
    private function notifyUserAboutSettingsChange(
        User $user,
        string $type,
        string $titleKey,
        string $bodyKey,
        array $changes,
        array $fieldLabelKeys,
        array $extraMessageParams = [],
        array $extraPayload = [],
    ): void {
        if ($changes === []) {
            return;
        }

        $changedSettingLabelKeys = collect(array_keys($changes))
            ->map(fn (string $field) => $fieldLabelKeys[$field] ?? null)
            ->filter()
            ->values()
            ->all();

        $event = $this->notificationService->createEvent(
            type: $type,
            category: NotificationCategory::ACCOUNT_CHARACTER_UPDATES,
            titleKey: $titleKey,
            bodyKey: $bodyKey,
            messageParams: array_merge($extraMessageParams, [
                'changed_setting_label_keys' => $changedSettingLabelKeys,
            ]),
            actionUrl: route('settings'),
            actor: $user,
            subject: $user,
            payload: array_merge($extraPayload, [
                'changed_fields' => array_keys($changes),
                'changes' => $changes,
                'changed_setting_label_keys' => $changedSettingLabelKeys,
            ]),
            isMandatory: false,
            topic: NotificationTopic::ACCOUNT_SETTINGS,
        );

        $this->notificationService->sendInAppNotifications($event, $user);
    }
}
