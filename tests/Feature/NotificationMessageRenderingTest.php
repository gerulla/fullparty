<?php

use App\Mail\NotificationDeliveryMail;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationMessageRenderer;
use Illuminate\Support\Arr;

it('renders application submission emails as complete text without a browser', function (string $locale, string $subject) {
    app()->setLocale($locale);
    $message = app(NotificationMessageRenderer::class)->render(new NotificationEvent([
        'title_key' => 'notifications.applications.submitted.title',
        'body_key' => 'notifications.applications.submitted.body',
        'message_params' => ['character' => 'Astra Vale', 'activity' => 'Weekly Savage'],
        'action_url' => 'https://fullparty.test/account/applications',
    ]), new User);

    expect($message['subject'])->toBe($subject)
        ->and($message['body'])->toContain('Astra Vale', 'Weekly Savage')
        ->not->toContain('notifications.', '{character}', '{activity}', ':character', ':activity');

    $mail = new NotificationDeliveryMail($message['subject'], $message['body'], $message['action_url']);
    expect($mail->envelope()->subject)->toBe($subject);
    $mail->assertSeeInHtml($subject);
    $mail->assertSeeInHtml('Astra Vale');
    $mail->assertSeeInHtml('Weekly Savage');
    $mail->assertSeeInHtml(__('email/labels.open'));
    $mail->assertDontSeeInHtml('email/notifications.');
})->with([
    ['en', 'Application submitted'],
    ['de', 'Bewerbung gesendet'],
    ['fr', 'Candidature envoyée'],
    ['ja', '応募が送信されました'],
]);

it('resolves every notification title and body for off-site delivery in each language', function (string $locale) {
    app()->setLocale($locale);
    $shared = json_decode(file_get_contents(lang_path($locale.'/notifications.json')), true, flags: JSON_THROW_ON_ERROR);
    $email = require lang_path($locale.'/email/notifications.php');
    $catalog = Arr::dot(array_replace_recursive($shared, $email));
    $renderer = app(NotificationMessageRenderer::class);

    foreach ($catalog as $key => $copy) {
        if (! preg_match('/^(.+)\.(body(?:_[a-z_]+)?)$/', $key, $match)) {
            continue;
        }

        $titleKey = $match[1].'.title';
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}|:([a-zA-Z_][a-zA-Z0-9_]*)/', $catalog[$titleKey].' '.$copy, $placeholders, PREG_SET_ORDER);
        $params = [];
        foreach ($placeholders as $placeholder) {
            $name = $placeholder[1] ?: $placeholder[2];
            $params[$name] = 'value-'.$name;
        }

        $message = $renderer->render(new NotificationEvent([
            'title_key' => 'notifications.'.$titleKey,
            'body_key' => 'notifications.'.$key,
            'message_params' => $params,
        ]), new User);

        foreach (['subject', 'body'] as $part) {
            expect($message[$part], $locale.': '.$key.' '.$part)->toBeString()->not->toBe('')
                ->not->toContain('notifications.');
            foreach (array_keys($params) as $parameter) {
                expect($message[$part])->not->toContain('{'.$parameter.'}', ':'.$parameter);
            }
        }
    }
})->with(['en', 'de', 'fr', 'ja']);

it('prefers email-specific wording and otherwise uses shared copy in the requested language', function () {
    app()->setLocale('fr');
    app('translator')->addLines([
        'email/notifications.system.announcement.title' => 'Email :headline',
        'notifications.system.announcement.title' => 'Shared :headline',
        'email/notifications.applications.submitted.title' => 'English email override',
    ], 'en');
    config(['app.locale' => 'en']);

    $renderer = app(NotificationMessageRenderer::class);
    $announcement = $renderer->render(new NotificationEvent([
        'title_key' => 'notifications.system.announcement.title',
        'message_params' => ['headline' => 'Maintenance'],
    ]), new User);
    expect($announcement['subject'])->toBe('Email Maintenance');

    config(['app.locale' => 'fr']);
    $application = $renderer->render(new NotificationEvent([
        'title_key' => 'notifications.applications.submitted.title',
    ]), new User);
    expect($application['subject'])->toBe('Candidature envoyée');
});
