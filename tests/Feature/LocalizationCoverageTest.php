<?php

use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\NotificationMessageRenderer;
use App\Support\Localization\JsonGroupTranslationLoader;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;

it('renders a localized error page even when no route matches', function (string $locale, string $title) {
    config(['app.debug' => false]);
    $this->get('/'.$locale.'/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('lang="'.$locale.'"', false)
        ->assertSee($title);
})->with([
    ['en', 'Not Found'], ['de', 'Nicht gefunden'], ['fr', 'Non trouvé'], ['ja', '見つかりません'],
]);

it('localizes system notification parameters for the configured delivery language', function () {
    config(['app.locale' => 'ja']);
    $event = new NotificationEvent([
        'title_key' => 'notifications.assignments.designation_assigned.title',
        'body_key' => 'notifications.assignments.designation_assigned.body',
        'message_params' => ['activity' => 'Test', 'character' => 'Alice', 'designation' => 'Raid Leader'],
        'payload' => ['designation_key' => 'raid_leader'],
    ]);
    $message = app(NotificationMessageRenderer::class)->render($event, new User);
    expect($message['body'])->toContain('レイドリーダー')->not->toContain('Raid Leader');
});

it('resolves shared nested dictionaries on the server in every supported language', function (string $locale) {
    app()->setLocale($locale);

    foreach ([
        'auth.social_email_unverified', 'audit_log.defaults.empty', 'audit_log.defaults.true',
        'audit_log.defaults.false', 'auth.reset_password_page.errors.invalid_token',
        'auth.reset_password_page.errors.invalid_user', 'auth.reset_password_page.errors.generic',
        'groups.activities.create.validation.starts_at_not_past', 'audit_log.defaults.system',
        'audit_log.defaults.no_metadata', 'xivplugin.device.invalid_code',
        'groups.activities.management.duplicate.future_error', 'groups.availability.validation.selection_too_long',
        'groups.index.create_modal.validation.image_invalid_format', 'groups.common.validation.active_time_pair_required',
        'groups.common.validation.active_timezone_required', 'groups.common.validation.invalid_join_mode_for_group_type',
        'dashboard.character_panel.customization.validation.image_invalid_format', 'groups.availability.validation.cycle_week',
        'groups.availability.validation.end_time', 'groups.availability.validation.overlap', 'groups.shortcuts.validation.duplicate',
        'auth.link_social.expired', 'auth.link_social.authentication_failed', 'auth.link_social.identity_taken',
        'groups.activities.management.messages.designation_unavailable', 'rich_text.resource_link', 'rich_text.video_embed',
    ] as $key) {
        expect(__($key))->toBeString()->not->toBe($key)->not->toBe('');
    }

    expect(__('audit_log.defaults'))->toBeArray();
})->with(['en', 'de', 'fr', 'ja']);

it('uses localized validation rules and field names without an English fallback', function (string $locale) {
    app()->setLocale($locale);
    $errors = Validator::make(['email' => 'invalid'], ['username' => 'required', 'email' => 'email'])->errors();

    expect($errors->first('username'))
        ->toContain(__('validation.attributes.username'))
        ->not->toContain('The username field is required');
    expect($errors->first('email'))->not->toContain('must be a valid email address');
    expect(__('auth.failed'))->not->toContain('These credentials');
    expect(__('validation.array_keys'))->not->toContain('The :attribute field');
    expect(__('validation.base64'))->not->toContain('The :attribute field');
})->with(['de', 'fr', 'ja']);

it('interpolates shared Vue placeholders with Laravel parameters and preserves PHP overrides', function () {
    $loader = app('translation.loader');
    $files = app('files');
    $path = sys_get_temp_dir().'/fullparty-localization-'.bin2hex(random_bytes(6));
    $files->makeDirectory($path.'/fr/sample', 0755, true);
    try {
        $files->put($path.'/fr/sample.json', json_encode(['greeting' => 'Bonjour {name}', 'override' => 'JSON']));
        $files->put($path.'/fr/sample/nested.json', json_encode(['label' => 'Imbriqué']));
        $files->put($path.'/fr/sample.php', "<?php return ['override' => 'PHP :name'];");
        $loader = new JsonGroupTranslationLoader(
            new FileLoader($files, $path), $files, $path,
        );
        $translator = new Translator($loader, 'fr');

        expect($translator->get('sample.greeting', ['name' => 'Alice']))->toBe('Bonjour Alice');
        expect($translator->get('sample.override', ['name' => 'Alice']))->toBe('PHP Alice');
        expect($translator->get('sample.nested.label'))->toBe('Imbriqué');
        expect($loader->load('../fr', 'sample'))->toBe([]);
    } finally {
        $files->deleteDirectory($path);
    }
});
