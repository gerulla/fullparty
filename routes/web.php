<?php

use App\Http\Controllers\AdminCharacterController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AccountApplicationController;
use App\Http\Controllers\AccountNotificationController;
use App\Http\Controllers\GroupActivityController;
use App\Http\Controllers\GroupActivityApplicationController;
use App\Http\Controllers\GroupActivityApplicationDeclineController;
use App\Http\Controllers\GroupActivityApplicantQueueController;
use App\Http\Controllers\GroupActivityFflogsCompletionPreviewController;
use App\Http\Controllers\GroupActivityFflogsController;
use App\Http\Controllers\GroupActivityCompletionController;
use App\Http\Controllers\GroupActivityManagementDataController;
use App\Http\Controllers\GroupActivityRosterExportController;
use App\Http\Controllers\GroupActivitySlotCheckInController;
use App\Http\Controllers\GroupActivitySlotAssignmentContextController;
use App\Http\Controllers\GroupActivitySlotAssignmentController;
use App\Http\Controllers\GroupActivitySlotDesignationController;
use App\Http\Controllers\GroupActivityManualSlotAssignmentOptionsController;
use App\Http\Controllers\GroupActivitySlotMissingController;
use App\Http\Controllers\GroupActivitySlotUnassignmentController;
use App\Http\Controllers\GroupActivitySlotSwapController;
use App\Http\Controllers\ActivityTypeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CharacterClassController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupDashboardController;
use App\Http\Controllers\GroupFollowController;
use App\Http\Controllers\GroupAuditLogController;
use App\Http\Controllers\GroupInviteController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\GroupMemberNoteController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\GroupSettingsController;
use App\Http\Controllers\PhantomJobController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialAccountController;
use App\Http\Controllers\SystemNotificationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\XIVAuthController;
use App\Http\Controllers\LocaleController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Http\Request;

Route::get('/', function () {
    return Inertia::render('Home');
});
Route::get('/privacy-policy', function () {
    return Inertia::render('Legal/PrivacyPolicy');
})->name('legal.privacy');
Route::get('/cookies', function () {
    return Inertia::render('Legal/CookiesPolicy');
})->name('legal.cookies');

Route::get('/groups/{group:slug}', [GroupController::class, 'show'])->name('groups.show');
Route::get('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'show'])
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application');
Route::get('/groups/{group:slug}/activities/{activity}/application-edit/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'editGuest'])
    ->where('accessToken', '[A-Za-z0-9]{40}')
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.edit-guest');
Route::get('/groups/{group:slug}/activities/{activity}/application-confirmation/{secretKey?}', [GroupActivityApplicationController::class, 'confirmation'])
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.confirmation');
Route::get('/groups/{group:slug}/activities/{activity}/application-status/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'status'])
    ->where('accessToken', '[A-Za-z0-9]{40}')
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.status');
Route::get('/groups/{group:slug}/activities/{activity}/application-search/{secretKey?}', [GroupActivityApplicationController::class, 'searchCharacters'])
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.search-characters');
Route::post('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'store'])
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.store');
Route::put('/groups/{group:slug}/activities/{activity}/application-edit/{accessToken}/{secretKey?}', [GroupActivityApplicationController::class, 'updateGuest'])
    ->where('accessToken', '[A-Za-z0-9]{40}')
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.application.update-guest');
Route::get('/groups/{group:slug}/activities/{activity}/{secretKey?}', [GroupActivityController::class, 'overview'])
    ->where('secretKey', '[A-Za-z0-9]{40}')
    ->name('groups.activities.overview');
Route::get('/invite/{token}', [GroupInviteController::class, 'show'])->name('groups.invites.show');

Route::prefix('auth')->group(function () {
	//Login and Register Pages
	Route::middleware('guest')->group(function () {
		Route::get('/login', function () {
			return Inertia::render('auth/Login');
		})->name('login');
		
		Route::get('/register', function () {
			return Inertia::render('auth/Register');
		})->name('register');
		Route::post('/register', [AuthController::class, 'register']);
		Route::post('/login', [AuthController::class, 'login']);
	});
	
	//Email Verification
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
	
	Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
	Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
	
	Route::get('/discord/redirect', [DiscordAuthController::class, 'redirect'])->name('discord.redirect');
	Route::get('/discord/callback', [DiscordAuthController::class, 'callback'])->name('discord.callback');
	
	Route::get('/xivauth/redirect', [XIVAuthController::class, 'redirect'])->name('xivauth.redirect');
	Route::get('/xivauth/callback', [XIVAuthController::class, 'callback'])->name('xivauth.callback');
	
	//Logout here so you can logout even without verifying
	Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware(['auth', 'verified'])->group(function () {
	Route::get('/dashboard', function () {
		return Inertia::render('Dashboard/Dashboard');
	})->name('dashboard');

	Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
	Route::get('/group-search-results', [GroupController::class, 'search'])->name('groups.search');
	Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
	Route::delete('/groups/{group:slug}', [GroupController::class, 'destroy'])->name('groups.destroy');
	Route::put('/groups/{group:slug}/activities/{activity}/application/{secretKey?}', [GroupActivityApplicationController::class, 'update'])
		->where('secretKey', '[A-Za-z0-9]{40}')
		->name('groups.activities.application.update');

	Route::post('/groups/{group:slug}/join', [GroupMembershipController::class, 'join'])->name('groups.join');
	Route::post('/groups/{group:slug}/leave', [GroupMembershipController::class, 'leave'])->name('groups.leave');
	Route::post('/groups/{group:slug}/follow', [GroupFollowController::class, 'store'])->name('groups.follow');
	Route::delete('/groups/{group:slug}/follow', [GroupFollowController::class, 'destroy'])->name('groups.unfollow');
	Route::patch('/groups/{group:slug}/follow-notifications', [GroupFollowController::class, 'updateNotifications'])->name('groups.follow-notifications.update');
	Route::put('/groups/{group:slug}/members/{user}', [GroupMembershipController::class, 'update'])->name('groups.members.update');
	Route::delete('/groups/{group:slug}/members/{user}', [GroupMembershipController::class, 'destroy'])->name('groups.members.destroy');
	Route::post('/groups/{group:slug}/members/{user}/ban', [GroupMembershipController::class, 'ban'])->name('groups.members.ban');
	Route::post('/groups/{group:slug}/members/{user}/notes', [GroupMemberNoteController::class, 'store'])->name('groups.members.notes.store');
	Route::put('/groups/{group:slug}/member-notes/{note}', [GroupMemberNoteController::class, 'update'])->name('groups.members.notes.update');
	Route::delete('/groups/{group:slug}/member-notes/{note}', [GroupMemberNoteController::class, 'destroy'])->name('groups.members.notes.destroy');
	Route::post('/groups/{group:slug}/member-notes/{note}/addenda', [GroupMemberNoteController::class, 'storeAddendum'])->name('groups.members.notes.addenda.store');
	Route::delete('/groups/{group:slug}/bans/{user}', [GroupMembershipController::class, 'unban'])->name('groups.members.unban');
	Route::post('/groups/{group:slug}/transfer-ownership', [GroupMembershipController::class, 'transferOwnership'])->name('groups.transfer-ownership');

	Route::post('/groups/{group:slug}/invites', [GroupInviteController::class, 'store'])->name('groups.invites.store');
	Route::delete('/groups/{group:slug}/invites/{invite}', [GroupInviteController::class, 'destroy'])->name('groups.invites.destroy');
	Route::post('/invite/{token}/accept', [GroupInviteController::class, 'accept'])->name('groups.invites.accept');

		Route::prefix('/groups/{group:slug}/dashboard')->middleware('group.dashboard.access')->group(function () {
		Route::get('/', [GroupDashboardController::class, 'show'])->name('groups.dashboard');
		Route::get('/members', [GroupMemberController::class, 'index'])->name('groups.dashboard.members');
		Route::get('/activities', [GroupActivityController::class, 'index'])->name('groups.dashboard.activities.index');
		Route::get('/activities/create', [GroupActivityController::class, 'create'])->name('groups.dashboard.activities.create');
		Route::post('/activities', [GroupActivityController::class, 'store'])->name('groups.dashboard.activities.store');
		Route::get('/activities/{activity}/edit', [GroupActivityController::class, 'edit'])->name('groups.dashboard.activities.edit');
		Route::get('/activities/{activity}/management-data', [GroupActivityManagementDataController::class, 'show'])->name('groups.dashboard.activities.management-data');
		Route::get('/activities/{activity}/export-roster', [GroupActivityRosterExportController::class, 'show'])->name('groups.dashboard.activities.export-roster');
		Route::post('/activities/{activity}/slots/{slot}/check-in', [GroupActivitySlotCheckInController::class, 'store'])->name('groups.dashboard.activities.slot-checkins.store');
		Route::post('/activities/{activity}/slots/{slot}/mark-late', [GroupActivitySlotCheckInController::class, 'storeLate'])->name('groups.dashboard.activities.slot-checkins.late');
		Route::post('/activities/{activity}/slots/{slot}/undo-check-in', [GroupActivitySlotCheckInController::class, 'undo'])->name('groups.dashboard.activities.slot-checkins.undo');
		Route::post('/activities/{activity}/slot-groups/check-in', [GroupActivitySlotCheckInController::class, 'storeGroup'])->name('groups.dashboard.activities.slot-group-checkins.store');
		Route::post('/activities/{activity}/slot-swaps', [GroupActivitySlotSwapController::class, 'store'])->name('groups.dashboard.activities.slot-swaps.store');
		Route::get('/activities/{activity}/slots/{slot}/assignment-context', [GroupActivitySlotAssignmentContextController::class, 'show'])->name('groups.dashboard.activities.slot-assignments.context');
		Route::get('/activities/{activity}/slots/{slot}/manual-assignment-options', [GroupActivityManualSlotAssignmentOptionsController::class, 'show'])->name('groups.dashboard.activities.slot-manual-assignment-options.show');
		Route::post('/activities/{activity}/slots/{slot}/assign-application', [GroupActivitySlotAssignmentController::class, 'store'])->name('groups.dashboard.activities.slot-assignments.store');
		Route::post('/activities/{activity}/slots/{slot}/designation', [GroupActivitySlotDesignationController::class, 'store'])->name('groups.dashboard.activities.slot-designations.store');
		Route::post('/activities/{activity}/slots/{slot}/mark-missing', [GroupActivitySlotMissingController::class, 'store'])->name('groups.dashboard.activities.slot-missing.store');
		Route::post('/activities/{activity}/missing-assignments/{assignment}/undo', [GroupActivitySlotMissingController::class, 'undo'])->name('groups.dashboard.activities.slot-missing.undo');
		Route::post('/activities/{activity}/slots/{slot}/return-to-queue', [GroupActivitySlotUnassignmentController::class, 'store'])->name('groups.dashboard.activities.slot-unassignments.store');
		Route::get('/activities/{activity}/applicant-queue', [GroupActivityApplicantQueueController::class, 'show'])->name('groups.dashboard.activities.applicant-queue');
		Route::get('/activities/{activity}/applicant-queue/applications/{application}', [GroupActivityApplicantQueueController::class, 'showApplication'])->name('groups.dashboard.activities.applicant-queue.application');
		Route::post('/activities/{activity}/applications/{application}/decline', [GroupActivityApplicationDeclineController::class, 'store'])->name('groups.dashboard.activities.application-declines.store');
		Route::get('/activities/{activity}/characters/{character}/fflogs-progress', [GroupActivityFflogsController::class, 'show'])->name('groups.dashboard.activities.fflogs-progress');
		Route::get('/activities/{activity}/applications/{application}/fflogs-progress', [GroupActivityFflogsController::class, 'showForApplication'])->name('groups.dashboard.activities.application-fflogs-progress');
		Route::post('/activities/{activity}/fflogs-completion-preview', [GroupActivityFflogsCompletionPreviewController::class, 'show'])->name('groups.dashboard.activities.fflogs-completion-preview');
		Route::get('/activities/{activity}', [GroupActivityController::class, 'show'])->name('groups.dashboard.activities.show');
		Route::put('/activities/{activity}', [GroupActivityController::class, 'update'])->name('groups.dashboard.activities.update');
		Route::post('/activities/{activity}/schedule', [GroupActivityController::class, 'schedule'])->name('groups.dashboard.activities.schedule');
		Route::post('/activities/{activity}/complete', [GroupActivityCompletionController::class, 'store'])->name('groups.dashboard.activities.complete');
		Route::post('/activities/{activity}/publish-roster', [GroupActivityController::class, 'publishRoster'])->name('groups.dashboard.activities.publish-roster');
		Route::post('/activities/{activity}/cancel', [GroupActivityController::class, 'cancel'])->name('groups.dashboard.activities.cancel');
		Route::delete('/activities/{activity}', [GroupActivityController::class, 'destroy'])->name('groups.dashboard.activities.destroy');
		Route::get('/audit-log', [GroupAuditLogController::class, 'index'])->name('groups.dashboard.audit-log');
		Route::get('/settings', [GroupSettingsController::class, 'show'])->name('groups.dashboard.settings');
		Route::put('/settings', [GroupSettingsController::class, 'update'])->name('groups.dashboard.settings.update');
	});
	
	//Settings
	Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
	Route::post('/settings/username', [UserController::class, 'changeUsername'])->name('settings.username');
	Route::post('/settings/notifications', [UserController::class, 'changeNotificationSettings'])->name('settings.notifications');
	Route::post('/settings/privacy', [UserController::class, 'changePrivacySettings'])->name('settings.privacy');
	Route::delete('/settings/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('settings.social-accounts.destroy');
	
	//Character Routes
	Route::get('/account/characters', [CharacterController::class, 'list'])->name('account.characters');
	Route::get('/account/applications', [AccountApplicationController::class, 'index'])->name('account.applications');
	Route::delete('/account/applications/{application}', [AccountApplicationController::class, 'destroy'])->name('account.applications.destroy');
	Route::get('/account/notifications', [AccountNotificationController::class, 'index'])->name('account.notifications.index');
	Route::get('/account/notifications/feed', [AccountNotificationController::class, 'feed'])->name('account.notifications.feed');
	Route::get('/account/notifications/summary', [AccountNotificationController::class, 'summary'])->name('account.notifications.summary');
	Route::post('/account/notifications/read-all', [AccountNotificationController::class, 'readAll'])->name('account.notifications.read-all');
	Route::get('/account/notifications/{notification}/open', [AccountNotificationController::class, 'open'])->name('account.notifications.open');
	Route::get('/account/notification-broadcasts/{broadcast}/open', [AccountNotificationController::class, 'openBroadcast'])->name('account.notifications.broadcasts.open');
	Route::post('/characters/exists', [CharacterController::class, 'exists'])->name('characters.exists');
	Route::post('/characters/verify', [CharacterController::class, 'verify'])->name('characters.verify');
	Route::post('/characters/{character}/refresh', [CharacterController::class, 'refreshCharacterData'])->name('characters.refresh');
	Route::post('/characters/{character}/make-primary', [CharacterController::class, 'makePrimary'])->name('characters.make-primary');
	Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');
	Route::post('/characters/{character}/preferred-class', [CharacterController::class, 'markPreferredClass'])->name('characters.preferred-class');
	Route::post('/characters/{character}/preferred-phantom-job', [CharacterController::class, 'markPreferredPhantomJob'])->name('characters.preferred-phantom-job');
	Route::post('/characters/xivauth', [CharacterController::class, 'fetchXIVAuthCharacters'])->name('characters.xivauth');
	Route::post('/characters/xivauth/import', [CharacterController::class, 'importXIVAuthCharacter'])->name('characters.xivauth.import');

	//Admin Routes
	Route::prefix('admin')->group(function () {
		Route::get('/character-data', [AdminController::class, 'characterData'])->name('admin.character-data');
		Route::get('/audit-log', [AdminController::class, 'auditLog'])->name('admin.audit-log');
		Route::get('/system-notifications', [SystemNotificationController::class, 'index'])->name('admin.system-notifications.index');
		Route::post('/system-notifications/maintenance', [SystemNotificationController::class, 'storeMaintenance'])->name('admin.system-notifications.maintenance.store');
		Route::post('/system-notifications/announcements', [SystemNotificationController::class, 'storeAnnouncement'])->name('admin.system-notifications.announcements.store');
		Route::put('/system-notifications/banner', [SystemNotificationController::class, 'storeBanner'])->name('admin.system-notifications.banner.store');
		Route::delete('/system-notifications/banner', [SystemNotificationController::class, 'clearBanner'])->name('admin.system-notifications.banner.clear');
		
		Route::get('/activity-types', [ActivityTypeController::class, 'index'])->name('admin.activity-types.index');
		Route::get('/activity-types/create', [ActivityTypeController::class, 'create'])->name('admin.activity-types.create');
		Route::post('/activity-types', [ActivityTypeController::class, 'store'])->name('admin.activity-types.store');
		Route::get('/activity-types/{activityType}/edit', [ActivityTypeController::class, 'edit'])->name('admin.activity-types.edit');
		Route::put('/activity-types/{activityType}', [ActivityTypeController::class, 'update'])->name('admin.activity-types.update');
		Route::post('/activity-types/{activityType}/publish', [ActivityTypeController::class, 'publish'])->name('admin.activity-types.publish');
		Route::delete('/activity-types/{activityType}', [ActivityTypeController::class, 'destroy'])->name('admin.activity-types.destroy');
		
		Route::redirect('/characters/definitions', '/admin/character-data')->name('admin.characters.definitions');
		Route::post('/characters/definitions', [AdminCharacterController::class, 'storeDefinition'])->name('admin.characters.definitions.store');
		Route::put('/characters/definitions/{definition}', [AdminCharacterController::class, 'updateDefinition'])->name('admin.characters.definitions.update');
		Route::delete('/characters/definitions/{definition}', [AdminCharacterController::class, 'destroyDefinition'])->name('admin.characters.definitions.destroy');
		Route::post('/characters/definitions/order', [AdminCharacterController::class, 'updateOrder'])->name('admin.characters.definitions.order');

		Route::get('/character-classes', [CharacterClassController::class, 'index'])->name('admin.character-classes.index');
		Route::post('/character-classes', [CharacterClassController::class, 'store'])->name('admin.character-classes.store');
		Route::get('/character-classes/{characterClass}', [CharacterClassController::class, 'show'])->name('admin.character-classes.show');
		Route::put('/character-classes/{characterClass}', [CharacterClassController::class, 'update'])->name('admin.character-classes.update');
		Route::delete('/character-classes/{characterClass}', [CharacterClassController::class, 'destroy'])->name('admin.character-classes.destroy');

		Route::get('/phantom-jobs', [PhantomJobController::class, 'index'])->name('admin.phantom-jobs.index');
		Route::post('/phantom-jobs', [PhantomJobController::class, 'store'])->name('admin.phantom-jobs.store');
		Route::get('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'show'])->name('admin.phantom-jobs.show');
		Route::put('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'update'])->name('admin.phantom-jobs.update');
		Route::delete('/phantom-jobs/{phantomJob}', [PhantomJobController::class, 'destroy'])->name('admin.phantom-jobs.destroy');
	});
});
