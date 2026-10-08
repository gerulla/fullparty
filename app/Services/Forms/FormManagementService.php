<?php

namespace App\Services\Forms;

use App\Models\SurveyForm;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Audit\AuditScope;
use App\Support\Audit\AuditSeverity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FormManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function save(User $actor, array $data, ?SurveyForm $form = null): SurveyForm
    {
        return DB::transaction(function () use ($actor, $data, $form) {
            $record = $form ? SurveyForm::query()->lockForUpdate()->findOrFail($form->id) : new SurveyForm;
            if ($record->exists) {
                $this->checkRevision($record, $data['revision']);
            }
            if ($record->published_version_id && $record->slug !== $data['slug']) {
                throw ValidationException::withMessages(['slug' => __('forms.errors.slug_locked')]);
            }
            $created = ! $record->exists;
            $record->fill(['slug' => $data['slug'], 'draft' => $data['definition'], 'created_by' => $created ? $actor->id : $record->created_by, 'updated_by' => $actor->id, 'revision' => $created ? 1 : $record->revision + 1])->save();
            $this->log($actor, $record, $created ? 'created' : 'updated');

            return $record;
        });
    }

    public function transition(User $actor, SurveyForm $form, int $revision, string $action): void
    {
        DB::transaction(function () use ($actor, $form, $revision, $action) {
            $record = SurveyForm::query()->lockForUpdate()->findOrFail($form->id);
            $this->checkRevision($record, $revision);
            if ($action === 'publish') {
                $version = $record->publishedVersion;
                if (! $version || $version->definition != $record->draft) {
                    $version = $record->versions()->create(['number' => (int) $record->versions()->max('number') + 1, 'definition' => $record->draft, 'published_by' => $actor->id, 'published_at' => now()]);
                }
                $record->fill(['is_open' => $record->published_version_id ? $record->is_open : true, 'published_version_id' => $version->id, 'is_published' => true]);
            } elseif ($action === 'unpublish') {
                $record->is_published = false;
            } else {
                if (! $record->published_version_id) {
                    throw ValidationException::withMessages(['revision' => __('forms.errors.not_published')]);
                }
                $record->is_open = $action === 'open';
            }
            $record->fill(['revision' => $record->revision + 1, 'updated_by' => $actor->id])->save();
            $this->log($actor, $record, $action);
        });
    }

    private function checkRevision(SurveyForm $form, int $revision): void
    {
        if ($form->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => __('forms.errors.conflict')]);
        }
    }

    private function log(User $actor, SurveyForm $form, string $action): void
    {
        $this->audit->log(action: 'admin.form.'.$action, severity: AuditSeverity::INFO, scopeType: AuditScope::ADMIN, scopeId: null,
            message: 'forms.audit.'.$action, actor: $actor, subject: $form, metadata: ['slug' => $form->slug, 'revision' => $form->revision]);
    }
}
