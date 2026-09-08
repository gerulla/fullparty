<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SendPasswordResetLinkRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\UserSessionRevocationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly UserSessionRevocationService $sessionRevocationService,
    ) {}

    public function register(RegisterRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::forceCreate([
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
        $login = $request->validated('login');
        $password = $request->validated('password');
        $remember = (bool) $request->validated('remember', false);
        $user = $this->findPasswordLoginUser($login);

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        Auth::login($user, $remember);

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

    private function findPasswordLoginUser(string $login): ?User
    {
        $normalizedLogin = Str::lower($login);

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [$normalizedLogin])
                ->first();
        }

        return User::query()
            ->whereRaw('LOWER(name) = ?', [$normalizedLogin])
            ->first();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function sendPasswordResetLink(SendPasswordResetLinkRequest $request): RedirectResponse
    {
        Password::broker()->sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return back()->with('success', ['password_reset_link_sent']);
    }

    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker()->reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (User $user, string $password) {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => Hash::make($password)])->save();
                    $this->sessionRevocationService->revokeAll($user);
                });

                event(new PasswordReset($user));

                $this->auditLogger->log(
                    action: 'user.password_reset',
                    severity: AuditSeverity::INFO,
                    scopeType: AuditScope::USER,
                    scopeId: $user->id,
                    message: 'audit_log.events.user.password_reset',
                    actor: $user,
                    subject: $user,
                    metadata: [
                        'email' => $user->email,
                    ],
                );
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => $this->passwordResetErrorMessage($status),
            ]);
        }

        return redirect()
            ->route('login')
            ->with('success', ['password_reset']);
    }

    private function passwordResetErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => __('auth.reset_password_page.errors.invalid_token'),
            Password::INVALID_USER => __('auth.reset_password_page.errors.invalid_user'),
            default => __('auth.reset_password_page.errors.generic'),
        };
    }
}
