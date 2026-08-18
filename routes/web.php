<?php

use App\Http\Controllers\AccountApplicationController;
use App\Http\Controllers\AccountNotificationController;
use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\AdminCharacterController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminDiscordGuildIntegrationController;
use App\Http\Controllers\AdminFflogsPlaygroundController;
use App\Http\Controllers\AdminQuotaController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BozjaItemController;
use App\Http\Controllers\Calculator\CalculatorCatalogController;
use App\Http\Controllers\Calculator\CalculatorController;
use App\Http\Controllers\CharacterClassController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardProfileCustomizationController;
use App\Http\Controllers\DiscordAppInstallController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\FeaturedGroupController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GroupActivityApplicantQueueController;
use App\Http\Controllers\GroupActivityApplicationController;
use App\Http\Controllers\GroupActivityApplicationDeclineController;
use App\Http\Controllers\GroupActivityCalendarController;
use App\Http\Controllers\GroupActivityCompletionController;
use App\Http\Controllers\GroupActivityController;
use App\Http\Controllers\GroupActivityDuplicationController;
use App\Http\Controllers\GroupActivityFflogsCompletionPreviewController;
use App\Http\Controllers\GroupActivityFflogsController;
use App\Http\Controllers\GroupActivityFillInSlotController;
use App\Http\Controllers\GroupActivityManagementDataController;
use App\Http\Controllers\GroupActivityManagementWarningController;
use App\Http\Controllers\GroupActivityManualSlotAssignmentOptionsController;
use App\Http\Controllers\GroupActivityPartyFinderInfoController;
use App\Http\Controllers\GroupActivityRosterExportController;
use App\Http\Controllers\GroupActivitySelfAssignmentController;
use App\Http\Controllers\GroupActivitySlotApplicationReviewWarningController;
use App\Http\Controllers\GroupActivitySlotAssignmentContextController;
use App\Http\Controllers\GroupActivitySlotAssignmentController;
use App\Http\Controllers\GroupActivitySlotCheckInController;
use App\Http\Controllers\GroupActivitySlotCompositionHintController;
use App\Http\Controllers\GroupActivitySlotDesignationController;
use App\Http\Controllers\GroupActivitySlotGroupCompositionPresetController;
use App\Http\Controllers\GroupActivitySlotMissingController;
use App\Http\Controllers\GroupActivitySlotSwapController;
use App\Http\Controllers\GroupActivitySlotUnassignmentController;
use App\Http\Controllers\GroupAuditLogController;
use App\Http\Controllers\GroupAvailabilityController;
use App\Http\Controllers\GroupBozjaHolsterController;
use App\Http\Controllers\GroupContentController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupDashboardController;
use App\Http\Controllers\GroupDiscordIntegrationController;
use App\Http\Controllers\GroupInviteController;
use App\Http\Controllers\GroupLeaderboardController;
use App\Http\Controllers\GroupLegacyLeaderboardController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\GroupMemberNoteController;
use App\Http\Controllers\GroupMembershipApplicationController;
use App\Http\Controllers\GroupMembershipApplicationFormController;
use App\Http\Controllers\GroupMembershipApplicationReviewController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\GroupMembershipRequestController;
use App\Http\Controllers\GroupPhantomCompositionController;
use App\Http\Controllers\GroupRunListController;
use App\Http\Controllers\GroupSettingsController;
use App\Http\Controllers\GroupShortcutController;
use App\Http\Controllers\GroupStatisticsController;
use App\Http\Controllers\IntegrationClientController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MyRunsController;
use App\Http\Controllers\PhantomJobController;
use App\Http\Controllers\Planner\PlannerController;
use App\Http\Controllers\Planner\RaidPlanController;
use App\Http\Controllers\Planner\RaidPlanImageController;
use App\Http\Controllers\Planner\RaidPlanPageController;
use App\Http\Controllers\RaidPositionController;
use App\Http\Controllers\RunDiscoveryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SettingsLinkedSessionController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserOnboardingController;
use App\Http\Controllers\XIVAuthController;
use App\Http\Controllers\XivPluginDeviceAuthorizationController;
use App\Http\Controllers\XivPluginDeviceController;
use App\Http\Middleware\ApplyLocale;
use App\Models\GroupInvite;
use App\Services\Landing\LandingPageDataService;
use App\Support\Seo\ServerMeta;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public Marketing, Legal, And Discovery Pages
|--------------------------------------------------------------------------
*/

Route::pattern('locale', implode('|', ApplyLocale::SUPPORTED_LOCALES));

$appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'fullparty.test';

Route::domain('plan.'.$appHost)
    ->name('planner.')
    ->group(function () {
        Route::get('/', PlannerController::class)->name('index');
        Route::post('/raid-plans', [RaidPlanController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('raid-plans.store');
        Route::get('/view/{token}', [RaidPlanPageController::class, 'view'])
            ->where('token', '[A-Za-z0-9]{48}')
            ->name('view');
        Route::get('/edit/{token}', [RaidPlanPageController::class, 'edit'])
            ->where('token', '[A-Za-z0-9]{48}')
            ->name('edit');
        Route::patch('/edit/{token}', [RaidPlanController::class, 'update'])
            ->middleware('throttle:60,1')
            ->where('token', '[A-Za-z0-9]{48}')
            ->name('raid-plans.update');
        Route::post('/edit/{token}/assets/images', RaidPlanImageController::class)
            ->middleware('throttle:20,1')
            ->where('token', '[A-Za-z0-9]{48}')
            ->name('raid-plans.images.store');
    });

Route::domain('math.'.$appHost)
    ->name('calculator.')
    ->middleware(['auth', 'verified', 'admin'])
    ->group(function () {
        Route::get('/', CalculatorController::class)->name('index');
        Route::get('/catalog', [CalculatorCatalogController::class, 'index'])->name('catalog');
        Route::get('/actions/{calculatorAction}', [CalculatorCatalogController::class, 'action'])->name('actions.show');
        Route::get('/traits/{calculatorTrait}', [CalculatorCatalogController::class, 'trait'])->name('traits.show');
    });

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

$redirectToLocalizedPath = function (Request $request, string $path = '') {
    $locale = $request->session()->get('locale')
        ?? $request->cookie('locale')
        ?? config('app.locale');

    if (! in_array($locale, ApplyLocale::SUPPORTED_LOCALES, true)) {
        $locale = config('app.locale');
    }

    $targetPath = trim($path, '/');
    $target = '/'.$locale.($targetPath !== '' ? '/'.$targetPath : '');
    $queryString = $request->getQueryString();

    if ($queryString) {
        $target .= '?'.$queryString;
    }

    return redirect()->to($target);
};

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.legacy-update');

Route::get('/privacy-policy', fn (Request $request) => $redirectToLocalizedPath($request, 'privacy-policy'));
Route::get('/cookies', fn (Request $request) => $redirectToLocalizedPath($request, 'cookies'));
Route::get('/group-search-results', fn (Request $request) => $redirectToLocalizedPath($request, 'group-search-results'));
Route::get('/dashboard', fn (Request $request) => $redirectToLocalizedPath($request, 'home'));
Route::get('/xivplugin', [XivPluginDeviceController::class, 'show'])->name('xivplugin.device');
Route::get('/xivplugin/authorize', XivPluginDeviceAuthorizationController::class)
    ->middleware('auth')
    ->name('xivplugin.device.authorize');

Route::get('/auth/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
    $request->fulfill();

    return redirect()->route('dashboard');
})->middleware(['auth', 'signed']);

Route::middleware(['auth', 'verified'])->prefix('auth/discord-app')->group(function () {
    Route::get('/user/redirect', [DiscordAppInstallController::class, 'redirectUserInstall'])
        ->middleware('throttle:oauth')
        ->name('discord-app.user.redirect');
    Route::get('/user/callback', [DiscordAppInstallController::class, 'callbackUserInstall'])
        ->middleware('throttle:oauth')
        ->name('discord-app.user.callback');
    Route::get('/guild/redirect', [DiscordAppInstallController::class, 'redirectGuildInstall'])
        ->middleware('throttle:oauth')
        ->name('discord-app.guild.redirect');
    Route::get('/guild/callback', [DiscordAppInstallController::class, 'callbackGuildInstall'])
        ->middleware('throttle:oauth')
        ->name('discord-app.guild.callback');
});

foreach (['auth', 'dashboard', 'groups', 'invite', 'settings', 'account', 'characters', 'admin'] as $prefix) {
    Route::get("/{$prefix}/{path?}", fn (Request $request, ?string $path = null) => $redirectToLocalizedPath($request, trim($prefix.'/'.($path ?? ''), '/')))
        ->where('path', '.*');
}

Route::prefix('{locale?}')
    ->where(['locale' => implode('|', ApplyLocale::SUPPORTED_LOCALES)])
    ->group(function () {
        Route::get('/', function (Request $request, LandingPageDataService $landingPageDataService, ServerMeta $serverMeta) {
            return Inertia::render('Home', [
                'landing' => $landingPageDataService->forHome($request->user()),
            ])->withViewData('serverMeta', $serverMeta->home());
        })->name('home');

        Route::get('/privacy-policy', function () {
            return Inertia::render('Legal/PrivacyPolicy');
        })->name('legal.privacy');

        Route::get('/cookies', function () {
            return Inertia::render('Legal/CookiesPolicy');
        })->name('legal.cookies');

        /*
        |--------------------------------------------------------------------------
        | Visible Groups, Activities, And Invite Pages
        |--------------------------------------------------------------------------
        |
        | These routes must remain safe for guests and must not leak private activity
        | details without a valid secret key or guest access token.
        |
        */

        // Activity application entry points and guest application flows.
        Route::get('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'show'])
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application');

        Route::post('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'store'])
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware(['throttle:guest.application', 'throttle:application.submit'])
            ->name('groups.activities.application.store');

        Route::get('/groups/{group:slug}/activities/{activity}/application-edit/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'editGuest'])
            ->where('accessToken', '[A-Za-z0-9]{40}')
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application.edit-guest');

        Route::put('/groups/{group:slug}/activities/{activity}/application-edit/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'updateGuest'])
            ->where('accessToken', '[A-Za-z0-9]{40}')
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application.update-guest');

        Route::delete('/groups/{group:slug}/activities/{activity}/application-edit/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'destroyGuest'])
            ->where('accessToken', '[A-Za-z0-9]{40}')
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application.destroy-guest');

        Route::get('/groups/{group:slug}/activities/{activity}/application-confirmation/{secretKey?}', [GroupActivityApplicationController::class, 'confirmation'])
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application.confirmation');

        Route::get('/groups/{group:slug}/activities/{activity}/application-status/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'status'])
            ->where('accessToken', '[A-Za-z0-9]{40}')
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware('throttle:guest.application')
            ->name('groups.activities.application.status');

        Route::get('/groups/{group:slug}/activities/{activity}/application-search/{secretKey?}', [GroupActivityApplicationController::class, 'searchCharacters'])
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->middleware(['throttle:guest.application', 'throttle:external.lookup'])
            ->name('groups.activities.application.search-characters');

        Route::get('/groups/{group:slug}/activities/{activity}/calendar.ics/{secretKey?}', GroupActivityCalendarController::class)
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->name('groups.activities.calendar');

        // Public activity overview, with optional secret key for private activities.
        Route::get('/groups/{group:slug}/activities/{activity}/{secretKey?}', [GroupActivityController::class, 'overview'])
            ->where('secretKey', '[A-Za-z0-9]{40}')
            ->name('groups.activities.overview');

        // Public, read-only group dashboard pages for visible open-join groups.
        Route::prefix('groups/{group:slug}/dashboard')->middleware('group.dashboard.access')->group(function () {
            Route::get('/', [GroupDashboardController::class, 'show'])->name('groups.dashboard');
            Route::get('/activities', [GroupActivityController::class, 'index'])->name('groups.dashboard.activities.index');
        });

        // Group invite landing pages.
        Route::get('/invite/{token}', [GroupInviteController::class, 'show'])
            ->middleware('throttle:invite')
            ->name('groups.invites.show');

        /*
        |--------------------------------------------------------------------------
        | Authentication And Identity
        |--------------------------------------------------------------------------
        */

        Route::prefix('auth')->group(function () {
            // Guest entry: login and registration.
            Route::middleware('guest')->group(function () {
                Route::get('/login', function (Request $request) {
                    $inviteToken = $request->query('invite');

                    if (is_string($inviteToken) && GroupInvite::query()->where('token', $inviteToken)->exists()) {
                        $request->session()->put(
                            'url.intended',
                            route('groups.invites.show', ['token' => $inviteToken]),
                        );
                    }

                    return Inertia::render('auth/Login');
                })->name('login');

                Route::get('/register', function () {
                    return Inertia::render('auth/Register');
                })->name('register');

                Route::get('/forgot-password', function () {
                    return Inertia::render('auth/ForgotPassword');
                })->name('password.request');

                Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])
                    ->middleware('throttle:auth.email')
                    ->name('password.email');

                Route::get('/reset-password/{token}', function (Request $request, string $token) {
                    return Inertia::render('auth/ResetPassword', [
                        'token' => $token,
                        'email' => $request->query('email'),
                    ]);
                })->name('password.reset');

                Route::post('/reset-password', [AuthController::class, 'resetPassword'])
                    ->middleware('throttle:auth.email')
                    ->name('password.update');

                Route::post('/register', [AuthController::class, 'register'])
                    ->middleware('throttle:auth.registration')
                    ->name('register.store');
                Route::post('/login', [AuthController::class, 'login'])
                    ->middleware('throttle:login')
                    ->name('login.store');
            });

            // Email verification lifecycle.
            Route::get('/email/verify', function () {
                return Inertia::render('auth/VerifyEmail', [
                    'email' => request()->user()->email,
                    'status' => session('status'),
                ]);
            })->middleware('auth')->name('verification.notice');

            Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
                $request->fulfill();

                return redirect()->route('dashboard');
            })->middleware(['auth', 'signed'])->name('verification.verify');

            Route::post('/email/verification-notification', function (Request $request) {
                $request->user()->sendEmailVerificationNotification();

                return back()->with('status', 'verification-link-sent');
            })->middleware(['auth', 'throttle:6,1'])->name('verification.send');

            // Social sign-in providers.
            Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:oauth')->name('google.redirect');
            Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:oauth')->name('google.callback');

            Route::get('/discord/redirect', [DiscordAuthController::class, 'redirect'])->middleware('throttle:oauth')->name('discord.redirect');
            Route::get('/discord/callback', [DiscordAuthController::class, 'callback'])->middleware('throttle:oauth')->name('discord.callback');

            Route::get('/xivauth/redirect', [XIVAuthController::class, 'redirect'])->middleware('throttle:oauth')->name('xivauth.redirect');
            Route::get('/xivauth/callback', [XIVAuthController::class, 'callback'])->middleware('throttle:oauth')->name('xivauth.callback');

            // Logout remains available even before email verification completes.
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });

        /*
        |--------------------------------------------------------------------------
        | Locale Switching
        |--------------------------------------------------------------------------
        */

        Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

        /*
        |--------------------------------------------------------------------------
        | Authenticated And Verified Application Surface
        |--------------------------------------------------------------------------
        */

        Route::middleware(['auth', 'verified'])->group(function () {
            /*
            |--------------------------------------------------------------------------
            | Home
            |--------------------------------------------------------------------------
            */

            Route::get('/home', [DashboardController::class, 'show'])->name('dashboard');
            Route::put('/home/profile', [DashboardProfileCustomizationController::class, 'update'])->name('dashboard.profile.update');
            Route::patch('/onboarding', [UserOnboardingController::class, 'update'])->name('onboarding.update');
            Route::post('/onboarding/complete', [UserOnboardingController::class, 'complete'])->name('onboarding.complete');
            Route::get('/dashboard', fn () => redirect()->route('dashboard'));
            Route::get('/dashboard/search', GlobalSearchController::class)->name('dashboard.search');
            Route::get('/dashboard/runs', [RunDiscoveryController::class, 'index'])->name('dashboard.runs.index');
            Route::get('/dashboard/runs/discovery', [RunDiscoveryController::class, 'discover'])->name('dashboard.runs.discover');
            Route::post('/dashboard/runs/{activity}/save', [RunDiscoveryController::class, 'save'])->name('dashboard.runs.save');
            Route::delete('/dashboard/runs/{activity}/save', [RunDiscoveryController::class, 'unsave'])->name('dashboard.runs.unsave');

            /*
            |--------------------------------------------------------------------------
            | Groups: Discovery And Creation
            |--------------------------------------------------------------------------
            */

            Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
            Route::get('/groups/requests', [GroupMembershipRequestController::class, 'index'])->name('groups.requests.index');
            Route::get('/groups/featured', [GroupController::class, 'featured'])->name('groups.featured');
            Route::get('/groups/{group:slug}/details', [GroupController::class, 'details'])->name('groups.details');
            Route::get('/group-search-results', [GroupController::class, 'search'])->name('groups.search');
            Route::post('/groups', [GroupController::class, 'store'])
                ->middleware('throttle:group.create')
                ->name('groups.store');
            Route::delete('/groups/{group:slug}', [GroupController::class, 'destroy'])->name('groups.destroy');

            // Signed-in user application updates.
            Route::put('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'update'])
                ->where('secretKey', '[A-Za-z0-9]{40}')
                ->name('groups.activities.application.update');

            Route::post('/groups/{group:slug}/activities/{activity}/slots/{slot}/self-assign/{secretKey?}', [GroupActivitySelfAssignmentController::class, 'store'])
                ->where('secretKey', '[A-Za-z0-9]{40}')
                ->name('groups.activities.self-assignments.store');

            Route::delete('/groups/{group:slug}/activities/{activity}/slots/{slot}/self-assign/{secretKey?}', [GroupActivitySelfAssignmentController::class, 'destroy'])
                ->where('secretKey', '[A-Za-z0-9]{40}')
                ->name('groups.activities.self-assignments.destroy');

            /*
            |--------------------------------------------------------------------------
            | Groups: Membership And Moderation
            |--------------------------------------------------------------------------
            */

            Route::post('/groups/{group:slug}/join', [GroupMembershipController::class, 'join'])
                ->middleware('throttle:membership.write')
                ->name('groups.join');
            Route::get('/groups/{group:slug}/membership-application', [GroupMembershipApplicationController::class, 'create'])->name('groups.membership-applications.create');
            Route::post('/groups/{group:slug}/membership-application', [GroupMembershipApplicationController::class, 'store'])
                ->middleware('throttle:membership.write')
                ->name('groups.membership-applications.store');
            Route::put('/groups/{group:slug}/membership-application', [GroupMembershipApplicationController::class, 'update'])->name('groups.membership-applications.update');
            Route::post('/groups/{group:slug}/leave', [GroupMembershipController::class, 'leave'])->name('groups.leave');
            Route::patch('/groups/{group:slug}/notifications', [GroupMembershipController::class, 'updateNotifications'])->name('groups.notifications.update');
            Route::put('/groups/{group:slug}/members/{user}', [GroupMembershipController::class, 'update'])->name('groups.members.update');
            Route::delete('/groups/{group:slug}/members/{user}', [GroupMembershipController::class, 'destroy'])->name('groups.members.destroy');
            Route::post('/groups/{group:slug}/members/{user}/ban', [GroupMembershipController::class, 'ban'])->name('groups.members.ban');
            Route::delete('/groups/{group:slug}/bans/{user}', [GroupMembershipController::class, 'unban'])->name('groups.members.unban');
            Route::post('/groups/{group:slug}/transfer-ownership', [GroupMembershipController::class, 'transferOwnership'])->name('groups.transfer-ownership');

            // Shared member notes used across member management surfaces.
            Route::get('/groups/{group:slug}/members/{user}/notes', [GroupMemberNoteController::class, 'show'])->name('groups.members.notes.show');
            Route::post('/groups/{group:slug}/members/{user}/notes', [GroupMemberNoteController::class, 'store'])
                ->middleware('throttle:group.content.write')
                ->name('groups.members.notes.store');
            Route::put('/groups/{group:slug}/member-notes/{note}', [GroupMemberNoteController::class, 'update'])->name('groups.members.notes.update');
            Route::delete('/groups/{group:slug}/member-notes/{note}', [GroupMemberNoteController::class, 'destroy'])->name('groups.members.notes.destroy');
            Route::post('/groups/{group:slug}/member-notes/{note}/addenda', [GroupMemberNoteController::class, 'storeAddendum'])
                ->middleware('throttle:group.content.write')
                ->name('groups.members.notes.addenda.store');
            Route::put('/groups/{group:slug}/member-note-addenda/{addendum}', [GroupMemberNoteController::class, 'updateAddendum'])->name('groups.members.notes.addenda.update');
            Route::delete('/groups/{group:slug}/member-note-addenda/{addendum}', [GroupMemberNoteController::class, 'destroyAddendum'])->name('groups.members.notes.addenda.destroy');

            // Invite management and acceptance.
            Route::post('/groups/{group:slug}/invites', [GroupInviteController::class, 'store'])
                ->middleware('throttle:group.content.write')
                ->name('groups.invites.store');
            Route::delete('/groups/{group:slug}/invites/{invite}', [GroupInviteController::class, 'destroy'])->name('groups.invites.destroy');
            Route::post('/invite/{token}/accept', [GroupInviteController::class, 'accept'])
                ->middleware(['throttle:invite', 'throttle:membership.write'])
                ->name('groups.invites.accept');

            /*
            |--------------------------------------------------------------------------
            | Group Dashboard Surface
            |--------------------------------------------------------------------------
            |
            | These routes are behind group dashboard access and power the internal
            | group management experience.
            |
            */

            Route::prefix('groups/{group:slug}/dashboard')->middleware('group.dashboard.access')->group(function () {
                // Group dashboard landing and non-activity sections.
                Route::get('/members', [GroupMemberController::class, 'index'])->name('groups.dashboard.members');
                Route::get('/content/delubrum-reginae-savage', [GroupContentController::class, 'delubrumReginaeSavage'])->name('groups.dashboard.content.delubrum-reginae-savage');
                Route::post('/content/delubrum-reginae-savage/holsters', [GroupBozjaHolsterController::class, 'store'])->middleware('throttle:group.content.write')->name('groups.dashboard.content.delubrum-reginae-savage.holsters.store');
                Route::post('/content/delubrum-reginae-savage/holsters/{bozjaHolster}/clone', [GroupBozjaHolsterController::class, 'duplicate'])->middleware('throttle:group.content.write')->name('groups.dashboard.content.delubrum-reginae-savage.holsters.clone');
                Route::put('/content/delubrum-reginae-savage/holsters/{bozjaHolster}', [GroupBozjaHolsterController::class, 'update'])->name('groups.dashboard.content.delubrum-reginae-savage.holsters.update');
                Route::patch('/content/delubrum-reginae-savage/holsters/{bozjaHolster}/status', [GroupBozjaHolsterController::class, 'updateStatus'])->name('groups.dashboard.content.delubrum-reginae-savage.holsters.status.update');
                Route::patch('/content/delubrum-reginae-savage/holsters/{bozjaHolster}/default', [GroupBozjaHolsterController::class, 'makeDefault'])->name('groups.dashboard.content.delubrum-reginae-savage.holsters.default.update');
                Route::delete('/content/delubrum-reginae-savage/holsters/{bozjaHolster}', [GroupBozjaHolsterController::class, 'destroy'])->name('groups.dashboard.content.delubrum-reginae-savage.holsters.destroy');
                Route::get('/content/forked-tower-blood', [GroupContentController::class, 'forkedTowerBlood'])->name('groups.dashboard.content.forked-tower-blood');
                Route::get('/content/forked-tower-blood/phantom-compositions', [GroupPhantomCompositionController::class, 'index'])->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.index');
                Route::post('/content/forked-tower-blood/phantom-compositions', [GroupPhantomCompositionController::class, 'store'])->middleware('throttle:group.content.write')->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.store');
                Route::put('/content/forked-tower-blood/phantom-compositions/reorder', [GroupPhantomCompositionController::class, 'reorder'])->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.reorder');
                Route::get('/content/forked-tower-blood/phantom-compositions/{phantomComposition}', [GroupPhantomCompositionController::class, 'show'])->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.show');
                Route::put('/content/forked-tower-blood/phantom-compositions/{phantomComposition}', [GroupPhantomCompositionController::class, 'update'])->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.update');
                Route::delete('/content/forked-tower-blood/phantom-compositions/{phantomComposition}', [GroupPhantomCompositionController::class, 'destroy'])->name('groups.dashboard.content.forked-tower-blood.phantom-compositions.destroy');
                Route::get('/members/{user}/activity-summary', [GroupMemberController::class, 'activitySummary'])->name('groups.dashboard.members.activity-summary');
                Route::get('/availability', GroupAvailabilityController::class)->name('groups.dashboard.availability');
                Route::get('/availability/selection', [GroupAvailabilityController::class, 'selection'])->name('groups.dashboard.availability.selection');
                Route::put('/availability/settings', [GroupAvailabilityController::class, 'updateSettings'])->name('groups.dashboard.availability.settings.update');
                Route::put('/availability/schedule', [GroupAvailabilityController::class, 'updateSchedule'])->name('groups.dashboard.availability.schedule.update');
                Route::get('/statistics', GroupStatisticsController::class)->name('groups.dashboard.statistics');
                Route::post('/statistics/refresh', [GroupStatisticsController::class, 'refresh'])->name('groups.dashboard.statistics.refresh');
                Route::get('/leaderboard', GroupLeaderboardController::class)->name('groups.dashboard.leaderboard');
                Route::get('/leaderboard/ranking', [GroupLeaderboardController::class, 'ranking'])->name('groups.dashboard.leaderboard.ranking');
                Route::post('/leaderboard/refresh', [GroupLeaderboardController::class, 'refresh'])->name('groups.dashboard.leaderboard.refresh');
                Route::get('/legacy-leaderboard', GroupLegacyLeaderboardController::class)->name('groups.dashboard.legacy-leaderboard');
                Route::get('/membership-applications', [GroupMembershipApplicationReviewController::class, 'index'])->name('groups.dashboard.membership-applications.index');
                Route::post('/membership-applications/{application}/approve', [GroupMembershipApplicationReviewController::class, 'approve'])->name('groups.dashboard.membership-applications.approve');
                Route::post('/membership-applications/{application}/decline', [GroupMembershipApplicationReviewController::class, 'decline'])->name('groups.dashboard.membership-applications.decline');
                Route::get('/membership-application-form', [GroupMembershipApplicationFormController::class, 'edit'])->name('groups.dashboard.membership-application-form.edit');
                Route::put('/membership-application-form', [GroupMembershipApplicationFormController::class, 'update'])->name('groups.dashboard.membership-application-form.update');
                Route::get('/audit-log', [GroupAuditLogController::class, 'index'])->name('groups.dashboard.audit-log');
                Route::get('/discovery-settings', [GroupSettingsController::class, 'showDiscovery'])->name('groups.dashboard.discovery-settings');
                Route::put('/discovery-settings', [GroupSettingsController::class, 'updateDiscovery'])->name('groups.dashboard.discovery-settings.update');
                Route::get('/settings', [GroupSettingsController::class, 'show'])->name('groups.dashboard.settings');
                Route::put('/settings', [GroupSettingsController::class, 'update'])->name('groups.dashboard.settings.update');
                Route::get('/shortcuts', [GroupShortcutController::class, 'index'])->name('groups.dashboard.shortcuts.index');
                Route::put('/shortcuts', [GroupShortcutController::class, 'update'])->name('groups.dashboard.shortcuts.update');
                Route::get('/discord-integration', [GroupDiscordIntegrationController::class, 'show'])->name('groups.dashboard.discord-integration');
                Route::post('/discord-integration/link-token', [GroupDiscordIntegrationController::class, 'generateToken'])->name('groups.dashboard.discord-integration.link-token');
                Route::put('/discord-integration/settings', [GroupDiscordIntegrationController::class, 'updateSettings'])->name('groups.dashboard.discord-integration.settings.update');
                Route::delete('/discord-integration', [GroupDiscordIntegrationController::class, 'destroy'])->name('groups.dashboard.discord-integration.destroy');

                /*
                |--------------------------------------------------------------------------
                | Group Dashboard Activities: Pages And Core CRUD
                |--------------------------------------------------------------------------
                |
                | Keep static paths like /create and /edit easy to spot above the more
                | dynamic /activities/{activity} management routes.
                |
                */

                Route::get('/activities/create', [GroupActivityController::class, 'create'])->name('groups.dashboard.activities.create');
                Route::post('/activities', [GroupActivityController::class, 'store'])
                    ->middleware('throttle:run.create')
                    ->name('groups.dashboard.activities.store');
                Route::get('/activities/{activity}', [GroupActivityController::class, 'show'])->name('groups.dashboard.activities.show');
                Route::get('/activities/{activity}/edit', [GroupActivityController::class, 'edit'])->name('groups.dashboard.activities.edit');
                Route::put('/activities/{activity}', [GroupActivityController::class, 'update'])->name('groups.dashboard.activities.update');
                Route::delete('/activities/{activity}', [GroupActivityController::class, 'destroy'])->name('groups.dashboard.activities.destroy');

                /*
                |--------------------------------------------------------------------------
                | Group Dashboard Activities: Management Data And Read Models
                |--------------------------------------------------------------------------
                */

                // Full dashboard payloads, exports, and read-only queue details.
                Route::get('/activities/{activity}/management-data', [GroupActivityManagementDataController::class, 'show'])->name('groups.dashboard.activities.management-data');
                Route::delete('/activities/{activity}/management-warnings/{managementWarning}', [GroupActivityManagementWarningController::class, 'destroy'])->name('groups.dashboard.activities.management-warnings.destroy');
                Route::post('/activities/{activity}/party-finder-info', [GroupActivityPartyFinderInfoController::class, 'store'])->name('groups.dashboard.activities.party-finder-info.store');
                Route::get('/activities/{activity}/export-roster', [GroupActivityRosterExportController::class, 'show'])->name('groups.dashboard.activities.export-roster');
                Route::get('/activities/{activity}/applicant-queue', [GroupActivityApplicantQueueController::class, 'show'])->name('groups.dashboard.activities.applicant-queue');
                Route::get('/activities/{activity}/applicant-queue/applications/{application}', [GroupActivityApplicantQueueController::class, 'showApplication'])->name('groups.dashboard.activities.applicant-queue.application');
                Route::post('/activities/{activity}/applicant-queue/applications/{application}/character-refresh', [GroupActivityApplicantQueueController::class, 'refreshApplicationCharacter'])
                    ->middleware('throttle:external.lookup')
                    ->name('groups.dashboard.activities.applicant-queue.application-character-refresh');

                // FF Logs lookups and completion previews.
                Route::get('/activities/{activity}/characters/{character}/fflogs-progress', [GroupActivityFflogsController::class, 'show'])
                    ->middleware('throttle:external.lookup')
                    ->name('groups.dashboard.activities.fflogs-progress');
                Route::get('/activities/{activity}/applications/{application}/fflogs-progress', [GroupActivityFflogsController::class, 'showForApplication'])
                    ->middleware('throttle:external.lookup')
                    ->name('groups.dashboard.activities.application-fflogs-progress');
                Route::post('/activities/{activity}/fflogs-completion-preview', [GroupActivityFflogsCompletionPreviewController::class, 'show'])
                    ->middleware('throttle:external.lookup')
                    ->name('groups.dashboard.activities.fflogs-completion-preview');

                /*
                |--------------------------------------------------------------------------
                | Group Dashboard Activities: Assignment Context And Manual Options
                |--------------------------------------------------------------------------
                */

                // Modal bootstrap payloads for assignment flows.
                Route::get('/activities/{activity}/slots/{slot}/assignment-context', [GroupActivitySlotAssignmentContextController::class, 'show'])->name('groups.dashboard.activities.slot-assignments.context');
                Route::get('/activities/{activity}/slots/{slot}/manual-assignment-options', [GroupActivityManualSlotAssignmentOptionsController::class, 'show'])->name('groups.dashboard.activities.slot-manual-assignment-options.show');

                /*
                |--------------------------------------------------------------------------
                | Group Dashboard Activities: Roster, Attendance, And Queue Mutations
                |--------------------------------------------------------------------------
                */

                // Roster assignment and queue state changes.
                Route::post('/activities/{activity}/slot-swaps', [GroupActivitySlotSwapController::class, 'store'])->name('groups.dashboard.activities.slot-swaps.store');
                Route::post('/activities/{activity}/fill-ins', [GroupActivityFillInSlotController::class, 'store'])->name('groups.dashboard.activities.fill-ins.store');
                Route::patch('/activities/{activity}/fill-ins/{slot}', [GroupActivityFillInSlotController::class, 'update'])->name('groups.dashboard.activities.fill-ins.update');
                Route::post('/activities/{activity}/slots/{slot}/assign-application', [GroupActivitySlotAssignmentController::class, 'store'])->name('groups.dashboard.activities.slot-assignments.store');
                Route::post('/activities/{activity}/slots/{slot}/application-review-warning/clear', [GroupActivitySlotApplicationReviewWarningController::class, 'store'])->name('groups.dashboard.activities.slot-application-review-warnings.clear');
                Route::post('/activities/{activity}/slots/{slot}/return-to-queue', [GroupActivitySlotUnassignmentController::class, 'store'])->name('groups.dashboard.activities.slot-unassignments.store');
                Route::post('/activities/{activity}/applications/{application}/decline', [GroupActivityApplicationDeclineController::class, 'store'])->name('groups.dashboard.activities.application-declines.store');

                // Designation and attendance.
                Route::post('/activities/{activity}/raid-leaders/mark-group-staff', [GroupActivitySlotDesignationController::class, 'markGroupStaffRaidLeaders'])->name('groups.dashboard.activities.raid-leaders.mark-group-staff');
                Route::post('/activities/{activity}/slots/{slot}/designation', [GroupActivitySlotDesignationController::class, 'store'])->name('groups.dashboard.activities.slot-designations.store');
                Route::post('/activities/{activity}/slots/{slot}/composition-hints', [GroupActivitySlotCompositionHintController::class, 'update'])->name('groups.dashboard.activities.slot-composition-hints.update');
                Route::post('/activities/{activity}/slot-groups/composition-preset', [GroupActivitySlotGroupCompositionPresetController::class, 'store'])->name('groups.dashboard.activities.slot-group-composition-presets.store');
                Route::post('/activities/{activity}/slot-groups/composition-preset/apply-to-all', [GroupActivitySlotGroupCompositionPresetController::class, 'applyToAll'])->name('groups.dashboard.activities.slot-group-composition-presets.apply-to-all');
                Route::post('/activities/{activity}/slots/{slot}/check-in', [GroupActivitySlotCheckInController::class, 'store'])->name('groups.dashboard.activities.slot-checkins.store');
                Route::post('/activities/{activity}/slots/{slot}/mark-late', [GroupActivitySlotCheckInController::class, 'storeLate'])->name('groups.dashboard.activities.slot-checkins.late');
                Route::post('/activities/{activity}/slots/{slot}/undo-check-in', [GroupActivitySlotCheckInController::class, 'undo'])->name('groups.dashboard.activities.slot-checkins.undo');
                Route::post('/activities/{activity}/slot-groups/check-in', [GroupActivitySlotCheckInController::class, 'storeGroup'])->name('groups.dashboard.activities.slot-group-checkins.store');

                // Missing assignment tracking.
                Route::post('/activities/{activity}/slots/{slot}/mark-missing', [GroupActivitySlotMissingController::class, 'store'])->name('groups.dashboard.activities.slot-missing.store');
                Route::post('/activities/{activity}/missing-assignments/{assignment}/undo', [GroupActivitySlotMissingController::class, 'undo'])->name('groups.dashboard.activities.slot-missing.undo');

                /*
                |--------------------------------------------------------------------------
                | Group Dashboard Activities: Lifecycle Actions
                |--------------------------------------------------------------------------
                */

                Route::post('/activities/{activity}/schedule', [GroupActivityController::class, 'schedule'])->name('groups.dashboard.activities.schedule');
                Route::post('/activities/{activity}/duplicate', [GroupActivityDuplicationController::class, 'store'])
                    ->middleware('throttle:run.create')
                    ->name('groups.dashboard.activities.duplicate');
                Route::post('/activities/{activity}/publish-roster', [GroupActivityController::class, 'publishRoster'])->name('groups.dashboard.activities.publish-roster');
                Route::post('/activities/{activity}/complete', [GroupActivityCompletionController::class, 'store'])->name('groups.dashboard.activities.complete');
                Route::post('/activities/{activity}/cancel', [GroupActivityController::class, 'cancel'])->name('groups.dashboard.activities.cancel');
            });

            /*
            |--------------------------------------------------------------------------
            | Account Settings
            |--------------------------------------------------------------------------
            */

            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
            Route::post('/settings/username', [UserController::class, 'changeUsername'])->name('settings.username');
            Route::post('/settings/profile-picture', [UserController::class, 'changeProfilePicture'])->name('settings.profile-picture');
            Route::post('/settings/password', [UserController::class, 'changePassword'])->name('settings.password');
            Route::post('/settings/notifications', [UserController::class, 'changeNotificationSettings'])->name('settings.notifications');
            Route::patch('/settings/time-display', [UserController::class, 'changeTimeDisplayPreference'])->name('settings.time-display');
            Route::patch('/settings/homepage', [UserController::class, 'changeHomepagePreference'])->name('settings.homepage');
            Route::post('/settings/discord-integration/link-token', [UserController::class, 'generateDiscordLinkToken'])->name('settings.discord-integration.link-token');
            Route::delete('/settings/discord-integration', [UserController::class, 'disconnectDiscordIntegration'])->name('settings.discord-integration.destroy');
            Route::delete('/settings/linked-sessions/{token}', [SettingsLinkedSessionController::class, 'destroy'])->name('settings.linked-sessions.destroy');
            Route::post('/settings/privacy', [UserController::class, 'changePrivacySettings'])->name('settings.privacy');
            Route::delete('/settings/account', [UserController::class, 'destroyAccount'])->name('settings.account.destroy');
            Route::delete('/settings/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('settings.social-accounts.destroy');

            /*
            |--------------------------------------------------------------------------
            | Account Pages
            |--------------------------------------------------------------------------
            */

            Route::get('/account/characters', [CharacterController::class, 'list'])->name('account.characters');
            Route::get('/account/runs', MyRunsController::class)->name('account.runs.index');
            Route::get('/account/applications', [AccountApplicationController::class, 'index'])->name('account.applications');
            Route::get('/account/applications/history', [AccountApplicationController::class, 'history'])->name('account.applications.history');
            Route::delete('/account/applications/{application}', [AccountApplicationController::class, 'destroy'])->name('account.applications.destroy');
            Route::post('/groups/{group:slug}/run-list', [GroupRunListController::class, 'store'])->name('groups.run-list.store');
            Route::delete('/groups/{group:slug}/run-list', [GroupRunListController::class, 'destroy'])->name('groups.run-list.destroy');

            /*
            |--------------------------------------------------------------------------
            | Notification Inbox And Broadcast Opening
            |--------------------------------------------------------------------------
            */

            Route::get('/account/notifications', [AccountNotificationController::class, 'index'])->name('account.notifications.index');
            Route::get('/account/notifications/feed', [AccountNotificationController::class, 'feed'])->name('account.notifications.feed');
            Route::get('/account/notifications/summary', [AccountNotificationController::class, 'summary'])->name('account.notifications.summary');
            Route::post('/account/notifications/read-all', [AccountNotificationController::class, 'readAll'])->name('account.notifications.read-all');
            Route::get('/account/notifications/{notification}/open', [AccountNotificationController::class, 'open'])->name('account.notifications.open');
            Route::get('/account/notification-broadcasts/{broadcast}/open', [AccountNotificationController::class, 'openBroadcast'])->name('account.notifications.broadcasts.open');

            /*
            |--------------------------------------------------------------------------
            | Character Management
            |--------------------------------------------------------------------------
            */

            Route::post('/characters/exists', [CharacterController::class, 'exists'])
                ->middleware('throttle:external.lookup')
                ->name('characters.exists');
            Route::post('/characters/verify', [CharacterController::class, 'verify'])
                ->middleware('throttle:external.lookup')
                ->name('characters.verify');
            Route::post('/characters/{character}/refresh', [CharacterController::class, 'refreshCharacterData'])
                ->middleware('throttle:external.lookup')
                ->name('characters.refresh');
            Route::post('/characters/{character}/make-primary', [CharacterController::class, 'makePrimary'])->name('characters.make-primary');
            Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');
            Route::post('/characters/{character}/preferred-class', [CharacterController::class, 'markPreferredClass'])->name('characters.preferred-class');
            Route::post('/characters/{character}/preferred-phantom-job', [CharacterController::class, 'markPreferredPhantomJob'])->name('characters.preferred-phantom-job');
            Route::post('/characters/xivauth', [CharacterController::class, 'fetchXIVAuthCharacters'])
                ->middleware('throttle:external.lookup')
                ->name('characters.xivauth');
            Route::post('/characters/xivauth/import', [CharacterController::class, 'importXIVAuthCharacter'])
                ->middleware('throttle:external.lookup')
                ->name('characters.xivauth.import');

            /*
            |--------------------------------------------------------------------------
            | Admin Surface
            |--------------------------------------------------------------------------
            */

            Route::prefix('admin')->group(function () {
                // Admin dashboards and audit surfaces.
                Route::get('/character-data', [AdminController::class, 'characterData'])->name('admin.character-data');
                Route::get('/audit-log', [AdminController::class, 'auditLog'])->name('admin.audit-log');
                Route::get('/system-data', [AdminController::class, 'systemData'])->name('admin.system-data');
                Route::get('/fflogs-playground', [AdminFflogsPlaygroundController::class, 'index'])->name('admin.fflogs-playground.index');
                Route::post('/fflogs-playground/execute', [AdminFflogsPlaygroundController::class, 'execute'])->name('admin.fflogs-playground.execute');

                // System-wide notifications and temporary banners.
                Route::get('/system-notifications', [SystemNotificationController::class, 'index'])->name('admin.system-notifications.index');
                Route::post('/system-notifications/maintenance', [SystemNotificationController::class, 'storeMaintenance'])->name('admin.system-notifications.maintenance.store');
                Route::post('/system-notifications/announcements', [SystemNotificationController::class, 'storeAnnouncement'])->name('admin.system-notifications.announcements.store');
                Route::put('/system-notifications/banner', [SystemNotificationController::class, 'storeBanner'])->name('admin.system-notifications.banner.store');
                Route::delete('/system-notifications/banner', [SystemNotificationController::class, 'clearBanner'])->name('admin.system-notifications.banner.clear');

                // Group discovery curation.
                Route::get('/featured-groups', [FeaturedGroupController::class, 'index'])->name('admin.featured-groups.index');
                Route::post('/featured-groups', [FeaturedGroupController::class, 'store'])->name('admin.featured-groups.store');
                Route::put('/featured-groups/{featuredGroup}', [FeaturedGroupController::class, 'update'])->name('admin.featured-groups.update');
                Route::delete('/featured-groups/{featuredGroup}', [FeaturedGroupController::class, 'destroy'])->name('admin.featured-groups.destroy');

                // Authorized integration clients.
                Route::get('/integrations', [IntegrationClientController::class, 'index'])->name('admin.integrations.index');
                Route::post('/integrations', [IntegrationClientController::class, 'store'])->name('admin.integrations.store');
                Route::put('/integrations/{integrationClient}', [IntegrationClientController::class, 'update'])->name('admin.integrations.update');
                Route::post('/integrations/{integrationClient}/api-token', [IntegrationClientController::class, 'regenerateApiToken'])->name('admin.integrations.api-token.regenerate');
                Route::post('/integrations/{integrationClient}/webhook-secret', [IntegrationClientController::class, 'regenerateWebhookSecret'])->name('admin.integrations.webhook-secret.regenerate');
                Route::post('/integrations/{integrationClient}/healthcheck', [IntegrationClientController::class, 'runHealthcheck'])->name('admin.integrations.healthcheck.run');

                // Discord guild links.
                Route::get('/discord-guild-links', [AdminDiscordGuildIntegrationController::class, 'index'])->name('admin.discord-guild-links.index');
                Route::delete('/discord-guild-links/{discordGuildIntegration}', [AdminDiscordGuildIntegrationController::class, 'destroy'])->name('admin.discord-guild-links.destroy');

                // Account and group quota overrides.
                Route::get('/quotas', [AdminQuotaController::class, 'index'])->name('admin.quotas.index');
                Route::get('/quotas/subjects', [AdminQuotaController::class, 'subjects'])->name('admin.quotas.subjects');
                Route::post('/quotas', [AdminQuotaController::class, 'store'])->middleware('throttle:admin.write')->name('admin.quotas.store');
                Route::put('/quotas/{quotaOverride}', [AdminQuotaController::class, 'update'])->middleware('throttle:admin.write')->name('admin.quotas.update');
                Route::delete('/quotas/{quotaOverride}', [AdminQuotaController::class, 'destroy'])->middleware('throttle:admin.write')->name('admin.quotas.destroy');

                // Activity type administration.
                Route::get('/activity-types', [ActivityTypeController::class, 'index'])->name('admin.activity-types.index');
                Route::get('/activity-types/create', [ActivityTypeController::class, 'create'])->name('admin.activity-types.create');
                Route::post('/activity-types', [ActivityTypeController::class, 'store'])->name('admin.activity-types.store');
                Route::get('/activity-types/{activityType}/edit', [ActivityTypeController::class, 'edit'])->name('admin.activity-types.edit');
                Route::put('/activity-types/{activityType}', [ActivityTypeController::class, 'update'])->name('admin.activity-types.update');
                Route::post('/activity-types/{activityType}/clone', [ActivityTypeController::class, 'duplicate'])->name('admin.activity-types.clone');
                Route::post('/activity-types/{activityType}/publish', [ActivityTypeController::class, 'publish'])->name('admin.activity-types.publish');
                Route::delete('/activity-types/{activityType}', [ActivityTypeController::class, 'destroy'])->name('admin.activity-types.destroy');

                // Character definition administration.
                Route::redirect('/characters/definitions', '/admin/character-data')->name('admin.characters.definitions');
                Route::post('/characters/definitions', [AdminCharacterController::class, 'storeDefinition'])->name('admin.characters.definitions.store');
                Route::put('/characters/definitions/{definition}', [AdminCharacterController::class, 'updateDefinition'])->name('admin.characters.definitions.update');
                Route::delete('/characters/definitions/{definition}', [AdminCharacterController::class, 'destroyDefinition'])->name('admin.characters.definitions.destroy');
                Route::post('/characters/definitions/order', [AdminCharacterController::class, 'updateOrder'])->name('admin.characters.definitions.order');

                // Character class administration.
                Route::get('/character-classes', [CharacterClassController::class, 'index'])->name('admin.character-classes.index');
                Route::post('/character-classes', [CharacterClassController::class, 'store'])->name('admin.character-classes.store');
                Route::get('/character-classes/{characterClass}', [CharacterClassController::class, 'show'])->name('admin.character-classes.show');
                Route::put('/character-classes/{characterClass}', [CharacterClassController::class, 'update'])->name('admin.character-classes.update');
                Route::delete('/character-classes/{characterClass}', [CharacterClassController::class, 'destroy'])->name('admin.character-classes.destroy');

                // Phantom job administration.
                Route::get('/phantom-jobs', [PhantomJobController::class, 'index'])->name('admin.phantom-jobs.index');
                Route::post('/phantom-jobs', [PhantomJobController::class, 'store'])->name('admin.phantom-jobs.store');
                Route::get('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'show'])->name('admin.phantom-jobs.show');
                Route::put('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'update'])->name('admin.phantom-jobs.update');
                Route::delete('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'destroy'])->name('admin.phantom-jobs.destroy');

                // System data administration.
                Route::post('/raid-positions', [RaidPositionController::class, 'store'])->name('admin.raid-positions.store');
                Route::put('/raid-positions/{raidPosition}', [RaidPositionController::class, 'update'])->name('admin.raid-positions.update');
                Route::delete('/raid-positions/{raidPosition}', [RaidPositionController::class, 'destroy'])->name('admin.raid-positions.destroy');
                Route::post('/bozja-items', [BozjaItemController::class, 'store'])->name('admin.bozja-items.store');
                Route::put('/bozja-items/{bozjaItem}', [BozjaItemController::class, 'update'])->name('admin.bozja-items.update');
                Route::delete('/bozja-items/{bozjaItem}', [BozjaItemController::class, 'destroy'])->name('admin.bozja-items.destroy');
            });
        });
    });

Route::get('/{token}', [GroupInviteController::class, 'shortcut'])
    ->where('token', '[A-Za-z0-9]{1,10}')
    ->middleware('throttle:invite')
    ->name('groups.invites.shortcut');
