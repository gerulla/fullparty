<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\GroupUserNote;
use App\Models\GroupUserNoteAddendum;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class GroupMemberNoteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger
    ) {}

    public function store(Request $request, Group $group, User $user): RedirectResponse
    {
        $group->loadMissing(['memberships', 'bans']);
        $this->authorizeModeratorAccess($group);

        $request->merge([
            'body' => trim((string) $request->input('body', '')),
        ]);

        if ($user->id === auth()->id()) {
            return redirect()->back()->withErrors([
                'error' => 'group_member_notes_self_forbidden',
            ]);
        }

        if (!$group->hasMember($user->id) && !$group->isBanned($user->id)) {
            abort(404);
        }

        $validated = $request->validate([
            'severity' => ['required', Rule::in(GroupUserNote::SEVERITIES)],
            'body' => ['required', 'string', 'max:5000'],
            'is_shared_with_groups' => ['nullable', 'boolean'],
        ]);

        $note = $group->userNotes()->create([
            'user_id' => $user->id,
            'author_user_id' => auth()->id(),
            'severity' => $validated['severity'],
            'body' => $validated['body'],
            'is_shared_with_groups' => (bool) ($validated['is_shared_with_groups'] ?? false),
        ]);

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

        $request->merge([
            'body' => trim((string) $request->input('body', '')),
        ]);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
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

        $request->merge([
            'body' => trim((string) $request->input('body', '')),
        ]);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
        ]);

        $addendum = $note->addenda()->create([
            'author_user_id' => auth()->id(),
            'body' => $validated['body'],
        ]);

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

    private function authorizeModeratorAccess(Group $group): void
    {
        if (!$group->hasModeratorAccess(auth()->id())) {
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
