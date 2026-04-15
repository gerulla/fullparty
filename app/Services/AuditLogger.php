<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class AuditLogger
{
    public function log(
        string $action,
        string $severity,
        string $scopeType,
        ?int $scopeId,
        string $message,
        User|int|null $actor = null,
        Model|array|null $subject = null,
        ?array $metadata = null,
        ?Carbon $createdAt = null,
    ): AuditLog {
        $this->ensureValidSeverity($severity);
        $this->ensureValidScope($scopeType);

        $subjectPayload = $this->resolveSubject($subject);

        return AuditLog::create([
            'actor_user_id' => $this->resolveActorId($actor),
            'action' => $action,
            'severity' => $severity,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'subject_type' => $subjectPayload['subject_type'],
            'subject_id' => $subjectPayload['subject_id'],
            'message' => $message,
            'metadata' => $metadata,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function ensureValidSeverity(string $severity): void
    {
        if (!in_array($severity, AuditSeverity::VALUES, true)) {
            throw new InvalidArgumentException("Invalid audit severity [{$severity}] supplied.");
        }
    }

    private function ensureValidScope(string $scopeType): void
    {
        if (!in_array($scopeType, AuditScope::VALUES, true)) {
            throw new InvalidArgumentException("Invalid audit scope [{$scopeType}] supplied.");
        }
    }

    private function resolveActorId(User|int|null $actor): ?int
    {
        if ($actor instanceof User) {
            return $actor->id;
        }

        return $actor;
    }

    /**
     * @return array{subject_type: ?string, subject_id: ?int}
     */
    private function resolveSubject(Model|array|null $subject): array
    {
        if ($subject instanceof Model) {
            return [
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
            ];
        }

        if ($subject === null) {
            return [
                'subject_type' => null,
                'subject_id' => null,
            ];
        }

        if (
            array_key_exists('subject_type', $subject)
            && array_key_exists('subject_id', $subject)
        ) {
            return [
                'subject_type' => $subject['subject_type'],
                'subject_id' => $subject['subject_id'],
            ];
        }

        throw new InvalidArgumentException('Audit subject must be a model, null, or an array with subject_type and subject_id.');
    }
}
