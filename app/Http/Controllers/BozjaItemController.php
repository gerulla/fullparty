<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\BozjaItemRequest;
use App\Models\BozjaItem;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BozjaItemController extends Controller
{
    private const IMAGE_DIRECTORY = 'bozja-items';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(BozjaItemRequest $request): RedirectResponse
    {
        $values = $request->safe()->except('icon');
        $values['icon_url'] = $this->managedImageStorage->uploadImageIfPresent(
            $request->file('icon'),
            self::IMAGE_DIRECTORY,
        );

        $bozjaItem = BozjaItem::query()->create($values);

        $this->auditLogger->log(
            action: 'admin.bozja_item.created',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.bozja_item.created',
            actor: $request->user(),
            subject: $bozjaItem,
            metadata: $this->snapshot($bozjaItem),
        );

        return redirect()->back()->with('success', 'bozja_item_created');
    }

    public function update(BozjaItemRequest $request, BozjaItem $bozjaItem): RedirectResponse
    {
        $before = $this->snapshot($bozjaItem);
        $values = $request->safe()->except('icon');
        $values['icon_url'] = $this->managedImageStorage->replaceUploadedImageIfPresent(
            $bozjaItem->icon_url,
            $request->file('icon'),
            self::IMAGE_DIRECTORY,
        );

        $bozjaItem->update($values);
        $after = $this->snapshot($bozjaItem->fresh());
        $changes = $this->changes($before, $after);

        if ($changes !== []) {
            $this->auditLogger->log(
                action: 'admin.bozja_item.updated',
                severity: AuditSeverity::CRITICAL,
                scopeType: AuditScope::ADMIN,
                scopeId: null,
                message: 'audit_log.events.admin.bozja_item.updated',
                actor: $request->user(),
                subject: $bozjaItem,
                metadata: [
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
            );
        }

        return redirect()->back()->with('success', 'bozja_item_updated');
    }

    public function destroy(Request $request, BozjaItem $bozjaItem): RedirectResponse
    {
        if (! $request->user()?->is_admin) {
            abort(403);
        }

        $snapshot = $this->snapshot($bozjaItem);

        $this->managedImageStorage->deleteManagedImage($bozjaItem->icon_url, self::IMAGE_DIRECTORY);
        $bozjaItem->delete();

        $this->auditLogger->log(
            action: 'admin.bozja_item.deleted',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.bozja_item.deleted',
            actor: $request->user(),
            subject: [
                'subject_type' => BozjaItem::class,
                'subject_id' => $snapshot['id'],
            ],
            metadata: $snapshot,
        );

        return redirect()->back()->with('success', 'bozja_item_deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BozjaItem $item): array
    {
        return [
            'id' => $item->id,
            'key' => $item->key,
            'category' => $item->category,
            'name' => $item->name,
            'description' => $item->description,
            'classification' => $item->classification,
            'cache_weight' => $item->cache_weight,
            'icon_url' => $item->icon_url,
            'sort_order' => $item->sort_order,
            'is_active' => $item->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        return collect($after)
            ->keys()
            ->filter(fn (string $field) => $before[$field] !== $after[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $before[$field],
                    'new' => $after[$field],
                ],
            ])
            ->all();
    }
}
