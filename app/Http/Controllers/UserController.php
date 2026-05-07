<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Http\Request;

class UserController extends Controller
{
    private const ACCOUNT_SETTING_LABEL_KEYS = [
        'name' => 'general.username',
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
    ) {}

    public function changeUsername(Request $request)
    {
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
		]);
		// If the user doesn't have a discord account, disable discord notifications'
		if($request->user()->socialAccounts->where('provider', 'discord')->isEmpty()){
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

		$user->update($validated);

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

		return redirect()
			->route('settings')
			->with('success', ['notification_settings_updated']);
		
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
            isMandatory: true,
        );

        $this->notificationService->sendInAppNotifications($event, $user);
    }
}
