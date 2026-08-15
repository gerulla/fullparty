<?php

namespace App\Http\Controllers;

use App\DTOs\QuotaCheck;
use App\Models\Group;
use App\Models\GroupUserNote;
use App\Models\GroupUserNoteAddendum;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Groups\GroupUserNoteVisibilityService;
use App\Services\Quotas\QuotaService;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use App\Support\Input\RequestTextInputSanitizer;
use App\Support\Quotas\QuotaKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GroupMemberNoteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RequestTextInputSanitizer $requestTextInputSanitizer,
        private readonly QuotaService $quotaService,
    ) {}

    public function show(Group $group, User $user, GroupUserNoteVisibilityService $noteVisibilityService): JsonResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        $user->loadMissing('characters');
        $this->authorizeModeratorAccess($group);

        if (! $group->hasMember($user->id) && ! $group->isBanned($user->id)) {
            abort(404);
        }

        $visibleNotes = $noteVisibilityService->loadVisibleNotesForTargets($group, auth()->id(), collect([$user->id]));

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'characters' => $user->characters
                    ->sort(function ($left, $right) {
                        if ($left->is_primary === $right->is_primary) {
                            return strcasecmp($left->name, $right->name);
                        }

                        return $left->is_primary ? -1 : 1;
                    })
                    ->values()
                    ->map(fn ($character) => [
                        'id' => $character->id,
                        'name' => $character->name,
                        'world' => $character->world,
                        'datacenter' => $character->datacenter,
                        'avatar_url' => $character->avatar_url,
                        'is_primary' => (bool) $character->is_primary,
                    ])
                    ->all(),
                'notes' => $noteVisibilityService->serializeVisibleNotesForUser(
                    $group,
                    $user,
                    auth()->id(),
                    $visibleNotes['group_notes_by_user_id'],
                    $visibleNotes['shared_notes_by_user_id'],
                ),
            ],
        ]);
    }

    public function store(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        $this->authorizeModeratorAccess($group);
        $this->requestTextInputSanitizer->sanitize($request, [], ['body']);

        if ($user->id === auth()->id()) {
            return redirect()->back()->withErrors([
                'error' => 'group_member_notes_self_forbidden',
            ]);
        }

        if (! $group->hasMember($user->id) && ! $group->isBanned($user->id)) {
            abort(404);
        }

        $validated = $request->validate([
            'severity' => ['required', Rule::in(GroupUserNote::SEVERITIES)],
            'body' => ['required', 'string', 'max:'.GroupUserNote::BODY_MAX_LENGTH],
            'is_shared_with_groups' => ['nullable', 'boolean'],
        ]);

        $note = $this->quotaService->run([
            new QuotaCheck(
                QuotaKey::MEMBER_NOTES_PER_MEMBER,
                $group,
                ['user_id' => $user->id],
            ),
        ], fn (): GroupUserNote => $group->userNotes()->create([
            'user_id' => $user->id,
            'author_user_id' => auth()->id(),
            'severity' => $validated['severity'],
            'body' => $validated['body'],
            'is_shared_with_groups' => (bool) ($validated['is_shared_with_groups'] ?? false),
        ]));

        $this->auditLogger->log(
            action: 'group.member.note.created',
            severity: $this->resolveAuditSeverity($note->severity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.created',
            actor: auth()->user(),
            subject: $user,
            metadata: [
                'note_severity' => $note->severity,
                'is_shared_with_groups' => $note->is_shared_with_groups,
                'note_excerpt' => Str::limit($note->body, 120),
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_created');
    }

    public function update(Request $request, Group $group, GroupUserNote $note): RedirectResponse
    {
        $group->loadMissing(['memberships']);
        $this->authorizeModeratorAccess($group);
        $this->authorizeCurrentGroupNote($group, $note);

        if ($note->author_user_id !== auth()->id()) {
            abort(403);
        }

        $this->requestTextInputSanitizer->sanitize($request, [], ['body']);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.GroupUserNote::BODY_MAX_LENGTH],
            'severity' => ['required', Rule::in(GroupUserNote::SEVERITIES)],
            'is_shared_with_groups' => ['nullable', 'boolean'],
        ]);

        $previousBody = $note->body;
        $previousSeverity = $note->severity;
        $previousShared = $note->is_shared_with_groups;

        $note->update([
            'body' => $validated['body'],
            'severity' => $validated['severity'],
            'is_shared_with_groups' => (bool) ($validated['is_shared_with_groups'] ?? false),
        ]);

        $this->auditLogger->log(
            action: 'group.member.note.updated',
            severity: $this->resolveAuditSeverity($note->severity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.updated',
            actor: auth()->user(),
            subject: $note->user,
            metadata: [
                'changes' => [
                    'note_body' => [
                        'old' => Str::limit($previousBody, 120),
                        'new' => Str::limit($note->body, 120),
                    ],
                    'note_severity' => [
                        'old' => $previousSeverity,
                        'new' => $note->severity,
                    ],
                    'is_shared_with_groups' => [
                        'old' => $previousShared,
                        'new' => $note->is_shared_with_groups,
                    ],
                ],
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_updated');
    }

    public function destroy(Group $group, GroupUserNote $note): RedirectResponse
    {
        $group->loadMissing(['memberships']);
        $this->authorizeModeratorAccess($group);
        $this->authorizeCurrentGroupNote($group, $note);

        if ($note->author_user_id !== auth()->id()) {
            abort(403);
        }

        $subjectUser = $note->user;
        $noteSeverity = $note->severity;
        $noteExcerpt = Str::limit($note->body, 120);

        $note->delete();

        $this->auditLogger->log(
            action: 'group.member.note.deleted',
            severity: $this->resolveAuditSeverity($noteSeverity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.deleted',
            actor: auth()->user(),
            subject: $subjectUser,
            metadata: [
                'note_severity' => $noteSeverity,
                'note_excerpt' => $noteExcerpt,
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_deleted');
    }

    public function storeAddendum(Request $request, Group $group, GroupUserNote $note): RedirectResponse
    {
        $group->loadMissing(['memberships']);
        $this->authorizeModeratorAccess($group);
        $this->authorizeCurrentGroupNote($group, $note);
        $this->requestTextInputSanitizer->sanitize($request, [], ['body']);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.GroupUserNote::ADDENDUM_MAX_LENGTH],
        ]);

        $addendum = $this->quotaService->run([
            new QuotaCheck(
                QuotaKey::MEMBER_NOTE_ADDENDA_PER_NOTE,
                $group,
                ['note_id' => $note->id],
            ),
        ], fn (): GroupUserNoteAddendum => $note->addenda()->create([
            'author_user_id' => auth()->id(),
            'body' => $validated['body'],
        ]));

        $this->auditLogger->log(
            action: 'group.member.note.addendum.created',
            severity: $this->resolveAuditSeverity($note->severity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.addendum.created',
            actor: auth()->user(),
            subject: $note->user,
            metadata: [
                'note_severity' => $note->severity,
                'note_excerpt' => Str::limit($note->body, 80),
                'addendum_excerpt' => Str::limit($addendum->body, 120),
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_addendum_created');
    }

    public function updateAddendum(Request $request, Group $group, GroupUserNoteAddendum $addendum): RedirectResponse
    {
        $group->loadMissing(['memberships']);
        $addendum->loadMissing(['note.user']);
        $this->authorizeModeratorAccess($group);
        $this->authorizeCurrentGroupNote($group, $addendum->note);

        if ($addendum->author_user_id !== auth()->id()) {
            abort(403);
        }

        $this->requestTextInputSanitizer->sanitize($request, [], ['body']);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:'.GroupUserNote::ADDENDUM_MAX_LENGTH],
        ]);

        $previousBody = $addendum->body;
        $addendum->update([
            'body' => $validated['body'],
        ]);

        $this->auditLogger->log(
            action: 'group.member.note.addendum.updated',
            severity: $this->resolveAuditSeverity($addendum->note->severity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.addendum.updated',
            actor: auth()->user(),
            subject: $addendum->note->user,
            metadata: [
                'note_severity' => $addendum->note->severity,
                'note_excerpt' => Str::limit($addendum->note->body, 80),
                'changes' => [
                    'addendum_excerpt' => [
                        'old' => Str::limit($previousBody, 120),
                        'new' => Str::limit($addendum->body, 120),
                    ],
                ],
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_addendum_updated');
    }

    public function destroyAddendum(Group $group, GroupUserNoteAddendum $addendum): RedirectResponse
    {
        $group->loadMissing(['memberships']);
        $addendum->loadMissing(['note.user']);
        $this->authorizeModeratorAccess($group);
        $this->authorizeCurrentGroupNote($group, $addendum->note);

        if ($addendum->author_user_id !== auth()->id()) {
            abort(403);
        }

        $note = $addendum->note;
        $addendumExcerpt = Str::limit($addendum->body, 120);
        $addendum->delete();

        $this->auditLogger->log(
            action: 'group.member.note.addendum.deleted',
            severity: $this->resolveAuditSeverity($note->severity),
            scopeType: AuditScope::GROUP,
            scopeId: $group->id,
            message: 'audit_log.events.group.member.note.addendum.deleted',
            actor: auth()->user(),
            subject: $note->user,
            metadata: [
                'note_severity' => $note->severity,
                'note_excerpt' => Str::limit($note->body, 80),
                'addendum_excerpt' => $addendumExcerpt,
            ],
        );

        return redirect()->back()->with('success', 'group_member_note_addendum_deleted');
    }

    private function authorizeModeratorAccess(Group $group): void
    {
        if (! $group->hasModeratorAccess(auth()->id())) {
            abort(403);
        }
    }

    private function authorizeCurrentGroupNote(Group $group, GroupUserNote $note): void
    {
        if ($note->group_id !== $group->id) {
            abort(404);
        }
    }

    private function resolveAuditSeverity(string $noteSeverity): string
    {
        return match ($noteSeverity) {
            GroupUserNote::SEVERITY_WARNING => AuditSeverity::MODERATION_CHANGE,
            GroupUserNote::SEVERITY_CRITICAL => AuditSeverity::CRITICAL,
            default => AuditSeverity::INFO,
        };
    }
}
