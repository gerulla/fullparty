<?php

namespace App\Http\Controllers;

use App\Models\PhantomJob;
use App\Services\AuditLogger;
use App\Services\ManagedImageStorage;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PhantomJobController extends Controller
{
    private const IMAGE_DIRECTORY = 'phantom-jobs';

    public function __construct(
        private readonly ManagedImageStorage $managedImageStorage,
        private readonly AuditLogger $auditLogger
    ) {}

    public function index(): RedirectResponse
    {
        $this->authorizeAdminAccess();

        return redirect()->route('admin.character-data');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $validated = $request->validate($this->rules());

        $validated['icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['icon_url'] ?? null,
            'icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['black_icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['black_icon_url'] ?? null,
            'black_icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['transparent_icon_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['transparent_icon_url'] ?? null,
            'transparent_icon_url',
            self::IMAGE_DIRECTORY
        );
        $validated['sprite_url'] = $this->managedImageStorage->downloadImageIfPresent(
            $validated['sprite_url'] ?? null,
            'sprite_url',
            self::IMAGE_DIRECTORY
        );

        $phantomJob = PhantomJob::create($validated);

        $this->auditLogger->log(
            action: 'admin.phantom_job.created',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.phantom_job.created',
            actor: auth()->user(),
            subject: $phantomJob,
            metadata: $this->phantomJobSnapshot($phantomJob),
        );

        return redirect()->back()->with('success', 'phantom_job_created');
    }

    public function show(PhantomJob $phantomJob): RedirectResponse
    {
        $this->authorizeAdminAccess();

        return redirect()->route('admin.character-data');
    }

    public function update(Request $request, PhantomJob $phantomJob): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $originalValues = $this->phantomJobSnapshot($phantomJob);
        $validated = $request->validate($this->rules($phantomJob->id));

        $validated['icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->icon_url,
            newUrl: $validated['icon_url'] ?? null,
            field: 'icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['black_icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->black_icon_url,
            newUrl: $validated['black_icon_url'] ?? null,
            field: 'black_icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['transparent_icon_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->transparent_icon_url,
            newUrl: $validated['transparent_icon_url'] ?? null,
            field: 'transparent_icon_url',
            directory: self::IMAGE_DIRECTORY
        );
        $validated['sprite_url'] = $this->managedImageStorage->replaceImageIfPresent(
            currentUrl: $phantomJob->sprite_url,
            newUrl: $validated['sprite_url'] ?? null,
            field: 'sprite_url',
            directory: self::IMAGE_DIRECTORY
        );

        $phantomJob->update($validated);

        $updatedValues = $this->phantomJobSnapshot($phantomJob->fresh());
        $changes = $this->buildChanges($originalValues, $updatedValues);

        if ($changes !== []) {
            $this->auditLogger->log(
                action: 'admin.phantom_job.updated',
                severity: AuditSeverity::CRITICAL,
                scopeType: AuditScope::ADMIN,
                scopeId: null,
                message: 'audit_log.events.admin.phantom_job.updated',
                actor: auth()->user(),
                subject: $phantomJob,
                metadata: [
                    'changed_fields' => array_keys($changes),
                    'changes' => $changes,
                ],
            );
        }

        return redirect()->back()->with('success', 'phantom_job_updated');
    }

    public function destroy(PhantomJob $phantomJob): RedirectResponse
    {
        $this->authorizeAdminAccess();

        $snapshot = $this->phantomJobSnapshot($phantomJob);
        $this->managedImageStorage->deleteManagedImage($phantomJob->icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->black_icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->transparent_icon_url, self::IMAGE_DIRECTORY);
        $this->managedImageStorage->deleteManagedImage($phantomJob->sprite_url, self::IMAGE_DIRECTORY);

        $phantomJob->delete();

        $this->auditLogger->log(
            action: 'admin.phantom_job.deleted',
            severity: AuditSeverity::CRITICAL,
            scopeType: AuditScope::ADMIN,
            scopeId: null,
            message: 'audit_log.events.admin.phantom_job.deleted',
            actor: auth()->user(),
            subject: [
                'subject_type' => PhantomJob::class,
                'subject_id' => $snapshot['id'],
            ],
            metadata: $snapshot,
        );

        return redirect()->back()->with('success', 'phantom_job_deleted');
    }

    /**
     * @return array<string, array<int, \Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    private function rules(?int $phantomJobId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('phantom_jobs', 'name')->ignore($phantomJobId),
            ],
            'max_level' => ['required', 'integer', 'min:1'],
            'icon_url' => ['nullable', 'url', 'max:500'],
            'black_icon_url' => ['nullable', 'url', 'max:500'],
            'transparent_icon_url' => ['nullable', 'url', 'max:500'],
            'sprite_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function phantomJobSnapshot(PhantomJob $phantomJob): array
    {
        return [
            'id' => $phantomJob->id,
            'name' => $phantomJob->name,
            'max_level' => $phantomJob->max_level,
            'icon_url' => $phantomJob->icon_url,
            'black_icon_url' => $phantomJob->black_icon_url,
            'transparent_icon_url' => $phantomJob->transparent_icon_url,
            'sprite_url' => $phantomJob->sprite_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $originalValues
     * @param  array<string, mixed>  $updatedValues
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function buildChanges(array $originalValues, array $updatedValues): array
    {
        return collect($updatedValues)
            ->keys()
            ->filter(fn (string $field) => $originalValues[$field] !== $updatedValues[$field])
            ->mapWithKeys(fn (string $field) => [
                $field => [
                    'old' => $originalValues[$field],
                    'new' => $updatedValues[$field],
                ],
            ])
            ->all();
    }

    private function authorizeAdminAccess(): void
    {
        if (!auth()->user()?->is_admin) {
            abort(403);
        }
    }
}
