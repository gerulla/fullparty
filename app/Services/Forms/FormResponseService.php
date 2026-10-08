<?php

namespace App\Services\Forms;

use App\Models\SurveyForm;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FormResponseService
{
    public function __construct(private readonly FormDefinitionService $definitions) {}

    public function submit(SurveyForm $form, ?User $user, string $identity, array $data): SurveyResponse
    {
        return DB::transaction(function () use ($form, $user, $identity, $data) {
            // The same lock covers publication, closing and submissions, including the first response.
            $record = SurveyForm::query()->lockForUpdate()->findOrFail($form->id);
            abort_unless($record->is_published && $record->published_version_id, 404);
            $definition = $record->publishedVersion->definition;
            abort_if($definition['access'] === 'account' && (! $user || ! $user->hasVerifiedEmail()), 403);
            $retry = $record->responses()->where('submission_key', $data['submission_key'])->first();
            if ($retry) {
                abort_unless(hash_equals($retry->respondent_key, $identity), 409);

                return $retry;
            }
            if (! $record->is_open) {
                throw ValidationException::withMessages(['form' => __('forms.closed')]);
            }
            if ($record->published_version_id !== (int) $data['version_id']) {
                throw ValidationException::withMessages(['form' => __('forms.errors.changed')]);
            }
            $response = $definition['response_policy'] === 'multiple' ? null : $record->responses()->where('respondent_key', $identity)->latest('id')->first();
            if ($response && $definition['response_policy'] === 'single') {
                throw ValidationException::withMessages(['form' => __('forms.already_submitted')]);
            }
            if (($response?->revision ?? 0) !== (int) $data['response_revision']) {
                throw ValidationException::withMessages(['form' => __('forms.errors.response_changed')]);
            }
            $answers = $this->definitions->answers($data['answers'], $definition['questions']);
            $response ??= new SurveyResponse(['survey_form_id' => $record->id, 'respondent_key' => $identity, 'user_id' => $user?->id]);
            $response->fill(['survey_form_version_id' => $record->published_version_id, 'submission_key' => $data['submission_key'], 'answers' => $answers, 'revision' => $response->exists ? $response->revision + 1 : 1])->save();

            return $response;
        });
    }
}
