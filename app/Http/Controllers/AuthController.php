<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
	public function __construct(
		private readonly AuditLogger $auditLogger
	) {}

	public function register(RegisterRequest $request): RedirectResponse
	{
		$validated = $request->validated();
		
		$user = User::create([
			'name' => $validated['username'],
			'email' => strtolower($validated['email']),
			'password' => Hash::make($validated['password']),
		]);
		
		$user->sendEmailVerificationNotification();
		
		Auth::login($user);

		$this->auditLogger->log(
			action: 'user.registered',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::USER,
			scopeId: $user->id,
			message: 'audit_log.events.user.registered',
			actor: $user,
			subject: $user,
			metadata: [
				'registration_method' => 'password',
				'email' => $user->email,
			],
		);
		
		$request->session()->regenerate();
		
		return redirect()->route('verification.notice');
	}
	
	public function login(LoginRequest $request): RedirectResponse
	{
		$credentials = [
			'email' => $request->validated('email'),
			'password' => $request->validated('password'),
		];
		
		$remember = (bool) $request->validated('remember', false);
		
		if (! Auth::attempt($credentials, $remember)) {
			throw ValidationException::withMessages([
				'email' => __('auth.failed'),
			]);
		}

		$user = $request->user();
		
		$request->session()->regenerate();

		$this->auditLogger->log(
			action: 'user.logged_in',
			severity: AuditSeverity::INFO,
			scopeType: AuditScope::USER,
			scopeId: $user?->id,
			message: 'audit_log.events.user.logged_in',
			actor: $user,
			subject: $user,
			metadata: [
				'login_method' => 'password',
				'remember' => $remember,
			],
		);
		
		return redirect()->intended(route('dashboard'));
	}
	
	public function logout(Request $request): RedirectResponse
	{
		Auth::logout();
		
		$request->session()->invalidate();
		$request->session()->regenerateToken();
		
		return redirect()->route('login');
	}
}
