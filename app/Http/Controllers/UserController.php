<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger
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

        $this->logUserSettingsChange(
            user: $user->fresh(),
            action: 'user.settings.username_updated',
            message: 'audit_log.events.user.settings.username_updated',
            originalValues: $originalValues,
            updatedValues: $updatedValues,
        );

		return redirect()
			->route('settings')
			->with('success', ['username_updated', $validated['username']]);
    }
	
	public function changeNotificationSettings(Request $request)
	{
		$validated = $request->validate([
			'run_reminders' => ['required', 'boolean'],
			'application_notifications' => ['required', 'boolean'],
			'group_updates' => ['required', 'boolean'],
			'assignment_updates' => ['required', 'boolean'],
			'email_notifications' => ['required', 'boolean'],
			'discord_notifications' => ['required', 'boolean'],
		]);
		// If the user doesn't have a discord account, disable discord notifications'
		if($request->user()->socialAccounts->where('provider', 'discord')->isEmpty()){
			$validated['discord_notifications'] = false;
		}

        $user = $request->user();
        $originalValues = [
            'run_reminders' => $user->run_reminders,
            'application_notifications' => $user->application_notifications,
            'group_updates' => $user->group_updates,
            'assignment_updates' => $user->assignment_updates,
            'email_notifications' => $user->email_notifications,
            'discord_notifications' => $user->discord_notifications,
        ];

		$user->update($validated);

        $updatedUser = $user->fresh();

        $this->logUserSettingsChange(
            user: $updatedUser,
            action: 'user.settings.notifications_updated',
            message: 'audit_log.events.user.settings.notifications_updated',
            originalValues: $originalValues,
            updatedValues: [
                'run_reminders' => $updatedUser->run_reminders,
                'application_notifications' => $updatedUser->application_notifications,
                'group_updates' => $updatedUser->group_updates,
                'assignment_updates' => $updatedUser->assignment_updates,
                'email_notifications' => $updatedUser->email_notifications,
                'discord_notifications' => $updatedUser->discord_notifications,
            ],
        );

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

        $this->logUserSettingsChange(
            user: $updatedUser,
            action: 'user.settings.privacy_updated',
            message: 'audit_log.events.user.settings.privacy_updated',
            originalValues: $originalValues,
            updatedValues: [
                'public_profile' => $updatedUser->public_profile,
                'public_characters' => $updatedUser->public_characters,
            ],
        );

		return redirect()
			->route('settings')
			->with('success', ['privacy_settings_updated']);
	}

    /**
     * @param  array<string, mixed>  $originalValues
     * @param  array<string, mixed>  $updatedValues
     */
    private function logUserSettingsChange(
        $user,
        string $action,
        string $message,
        array $originalValues,
        array $updatedValues,
    ): void {
        $changes = collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $originalValues[$field],
                    'new' => $updatedValues[$field],
                ],
            ])
            ->all();

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
}
