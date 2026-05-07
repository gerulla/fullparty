<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\AuditLogger;
use App\Services\Notifications\AccountCharacterNotificationService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialAccountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AccountCharacterNotificationService $accountCharacterNotificationService,
    ) {}

    public function destroy(Request $request, SocialAccount $socialAccount): RedirectResponse
    {
        $user = $request->user();

        if ($socialAccount->user_id !== $user->id) {
            abort(403);
        }

        if ($user->socialAccounts()->count() <= 1 && blank($user->password)) {
            return redirect()
                ->route('settings')
                ->withErrors([
                    'error' => 'social_account_unlink_last_login_method',
                ]);
        }

        $provider = $socialAccount->provider;
        $providerUserId = $socialAccount->provider_user_id;

        DB::transaction(function () use ($socialAccount, $user, $provider): void {
            $socialAccount->delete();

            if ($provider === 'discord' && $user->discord_notifications) {
                $user->update([
                    'discord_notifications' => false,
                ]);
            }
        });

        $freshUser = $user->fresh('socialAccounts');

        $this->auditLogger->log(
            action: 'user.social_account.unlinked',
            severity: AuditSeverity::INFO,
            scopeType: AuditScope::USER,
            scopeId: $freshUser->id,
            message: 'audit_log.events.user.social_account.unlinked',
            actor: $freshUser,
            subject: $freshUser,
            metadata: [
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
            ],
        );

        $this->accountCharacterNotificationService->notifySocialAccountUnlinked($freshUser, $provider, $freshUser);

        return redirect()
            ->route('settings')
            ->with('success', ['social_account_unlinked']);
    }
}
