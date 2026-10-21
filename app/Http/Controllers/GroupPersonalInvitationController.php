<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupInvite;
use App\Models\User;
use App\Services\Groups\ActivityGroupFollowUpService;
use App\Services\Groups\GroupPersonalInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupPersonalInvitationController extends Controller
{
    public function __construct(
        private readonly GroupPersonalInvitationService $invitations,
        private readonly ActivityGroupFollowUpService $followUps,
    ) {}

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->invitations->authorizeSender($group, $request->user());
        $validated = $request->validate(['recipient_user_id' => ['required', 'integer', 'exists:users,id']]);
        $invite = $this->invitations->send($group, User::findOrFail($validated['recipient_user_id']), $request->user());

        return response()->json(['data' => $this->followUps->invitationSummary($invite)]);
    }

    public function resend(Request $request, Group $group, GroupInvite $invite): JsonResponse
    {
        $resent = $this->invitations->resend($group, $invite, $request->user());

        return response()->json(['data' => $this->followUps->invitationSummary($resent)]);
    }

    public function decline(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $invite = GroupInvite::where('token', $token)->firstOrFail();
        $this->invitations->decline($invite, $request->user());

        return $request->expectsJson() ? response()->json(['status' => 'declined'])
            : redirect()->route('groups.invites.show', $token);
    }
}
