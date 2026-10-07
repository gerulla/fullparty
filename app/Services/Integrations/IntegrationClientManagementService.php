<?php

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;

final class IntegrationClientManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $actor, array $data, string $token, string $secret): IntegrationClient
    {
        return DB::transaction(function () use ($actor, $data, $token, $secret): IntegrationClient {
            $client = IntegrationClient::create([
                ...$data,
                'created_by_user_id' => $actor->id,
                'api_token_hash' => IntegrationClient::hashApiToken($token),
                'webhook_signing_secret' => $secret,
            ]);
            $this->record($actor, $client, 'created', ['permissions' => $client->only(['status', 'scopes', 'allowed_events'])]);

            return $client;
        });
    }

    public function update(User $actor, IntegrationClient $client, array $data): void
    {
        DB::transaction(function () use ($actor, $client, $data): void {
            $client = IntegrationClient::query()->lockForUpdate()->findOrFail($client->id);
            $before = $client->only(array_keys($data));
            $client->fill($data);
            $changedFields = array_keys($client->getDirty());
            $client->save();

            if ($changedFields === []) {
                return;
            }

            $changes = [];
            // Destinations may contain credentials in URLs: record their changed field names only.
            foreach (array_intersect($changedFields, ['name', 'type', 'status', 'scopes', 'allowed_events']) as $field) {
                $changes[$field] = ['old' => $before[$field] ?? null, 'new' => $client->getAttribute($field)];
            }
            $this->record($actor, $client, 'updated', ['changed_fields' => $changedFields, 'changes' => $changes]);
        });
    }

    public function rotateApiToken(User $actor, IntegrationClient $client, string $token): void
    {
        DB::transaction(function () use ($actor, $client, $token): void {
            $client->update(['api_token_hash' => IntegrationClient::hashApiToken($token)]);
            $this->record($actor, $client, 'api_token_rotated');
        });
    }

    public function rotateWebhookSecret(User $actor, IntegrationClient $client, string $secret): void
    {
        DB::transaction(function () use ($actor, $client, $secret): void {
            $client->update(['webhook_signing_secret' => $secret]);
            $this->record($actor, $client, 'webhook_secret_rotated');
        });
    }

    private function record(User $actor, IntegrationClient $client, string $event, array $metadata = []): void
    {
        $this->audit->log(
            action: 'admin.integration_client.'.$event,
            severity: AuditSeverity::SEVERE_CHANGE,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.integration_client.'.$event,
            actor: $actor,
            subject: $client,
            metadata: ['integration_client_id' => $client->id, 'integration_client_name' => $client->name, ...$metadata],
        );
    }
}
