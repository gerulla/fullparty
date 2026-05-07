<?php

namespace App\Services\Notifications;

use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

class NotificationMessageRenderer
{
    private const EMAIL_LABEL_KEY_MAP = [
        'general.username' => 'email/labels.username',
        'settings.notifications.applications' => 'email/labels.notifications.applications',
        'settings.notifications.assignments' => 'email/labels.notifications.assignments',
        'settings.notifications.runs_and_reminders' => 'email/labels.notifications.runs_and_reminders',
        'settings.notifications.group_updates' => 'email/labels.notifications.group_updates',
        'settings.notifications.account_character_updates' => 'email/labels.notifications.account_character_updates',
        'settings.notifications.system_notices' => 'email/labels.notifications.system_notices',
        'settings.notifications.email_notifications' => 'email/labels.notifications.email_notifications',
        'settings.notifications.discord_notifications' => 'email/labels.notifications.discord_notifications',
        'settings.privacy.profile_visibility' => 'email/labels.privacy.profile_visibility',
        'settings.privacy.show_character_data' => 'email/labels.privacy.show_character_data',
    ];

    /**
     * @return array{subject: string, body: ?string, action_url: ?string}
     */
    public function render(NotificationEvent $event, User $recipient): array
    {
        $locale = config('app.locale');
        $params = $this->resolveParams($event->message_params ?? [], $locale);

        return [
            'subject' => $this->translateNotificationKey($event->title_key, $params, $locale),
            'body' => $event->body_key
                ? $this->translateNotificationKey($event->body_key, $params, $locale)
                : null,
            'action_url' => $event->action_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resolveParams(array $params, string $locale): array
    {
        $changedSettings = $this->translateLabelKeys(
            $params['changed_setting_label_keys'] ?? [],
            $locale,
        );

        if ($changedSettings !== '') {
            $params['settings'] = $changedSettings;
            $params['categories'] = $changedSettings;
        }

        return collect($params)
            ->filter(fn (mixed $value) => is_scalar($value) || $value === null)
            ->all();
    }

    /**
     * @param  mixed  $value
     */
    private function translateLabelKeys(mixed $value, string $locale): string
    {
        if (!is_array($value)) {
            return '';
        }

        return collect($value)
            ->map(function (mixed $key) use ($locale) {
                if (!is_string($key)) {
                    return null;
                }

                $translationKey = self::EMAIL_LABEL_KEY_MAP[$key] ?? null;

                return $translationKey ? $this->translate($translationKey, [], $locale) : null;
            })
            ->filter(fn (?string $translation) => is_string($translation) && trim($translation) !== '')
            ->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function translate(string $key, array $params, string $locale): string
    {
        $translation = Lang::get($key, $params, $locale);

        return is_string($translation) ? $translation : $key;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function translateNotificationKey(?string $key, array $params, string $locale): string
    {
        if (!$key) {
            return '';
        }

        $translationKey = str_starts_with($key, 'notifications.')
            ? 'email/notifications.'.substr($key, strlen('notifications.'))
            : $key;

        return $this->translate($translationKey, $params, $locale);
    }
}
