<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\DiscordAuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\XIVAuthController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Http\Request;

Route::get('/', function () {
    return Inertia::render('Home');
});

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

Route::middleware(['auth', 'verified'])->group(function () {
	Route::get('/dashboard', function () {
		return Inertia::render('Dashboard/Dashboard');
	})->name('dashboard');
	
	//Settings
	Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
	Route::post('/settings/username', [UserController::class, 'changeUsername'])->name('settings.username');
	Route::post('/settings/notifications', [UserController::class, 'changeNotificationSettings'])->name('settings.notifications');
	Route::post('/settings/privacy', [UserController::class, 'changePrivacySettings'])->name('settings.privacy');
	
	//Character Routes
	Route::get('/account/characters', [CharacterController::class, 'list'])->name('account.characters');
	Route::post('/characters/exists', [CharacterController::class, 'exists'])->name('characters.exists');
	Route::post('/characters/verify', [CharacterController::class, 'verify'])->name('characters.verify');
	Route::post('/characters/xivauth', [CharacterController::class, 'fetchXIVAuthCharacters'])->name('characters.xivauth');
});