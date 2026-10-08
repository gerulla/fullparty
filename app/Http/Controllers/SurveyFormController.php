<?php

namespace App\Http\Controllers;

use App\Models\SurveyForm;
use App\Services\Forms\FormDefinitionService;
use App\Services\Forms\FormRespondentIdentity;
use App\Services\Forms\FormResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SurveyFormController extends Controller
{
    public function __construct(private readonly FormRespondentIdentity $identity, private readonly FormDefinitionService $definitions, private readonly FormResponseService $responses) {}

    public function show(Request $request, SurveyForm $surveyForm): Response
    {
        abort_unless($surveyForm->is_published && $surveyForm->published_version_id, 404);
        $definition = $surveyForm->publishedVersion->definition;
        $identity = $this->identity->key($request);
        $existing = $definition['response_policy'] !== 'multiple' ? $surveyForm->responses()->with('version')->where('respondent_key', $identity)->latest('id')->first() : null;
        $canSubmit = $definition['access'] === 'anyone' || ($request->user() && $request->user()->hasVerifiedEmail());
        if (! $request->user() && $definition['access'] === 'account') {
            $request->session()->put('url.intended', route('forms.show', $surveyForm));
        }

        return Inertia::render('Forms/Show', [
            'form' => ['slug' => $surveyForm->slug, 'definition' => $definition, 'version_id' => $surveyForm->published_version_id, 'is_open' => $surveyForm->is_open],
            'canSubmit' => (bool) $canSubmit,
            'existing' => $existing ? [
                'answers' => $this->definitions->prefill($existing->answers, $existing->version->definition['questions'], $definition['questions']),
                'revision' => $existing->revision,
                'version_changed' => $existing->survey_form_version_id !== $surveyForm->published_version_id,
                'updated_at' => $existing->updated_at->toIso8601String(),
            ] : null,
            'submissionKey' => (string) Str::uuid(),
        ])->withViewData('robots', 'noindex');
    }

    public function store(Request $request, SurveyForm $surveyForm): RedirectResponse
    {
        $data = $request->validate([
            'answers' => ['present', 'array', 'max:50'], 'version_id' => ['required', 'integer'],
            'response_revision' => ['required', 'integer', 'min:0'], 'submission_key' => ['required', 'uuid'],
        ]);
        $this->responses->submit($surveyForm, $request->user(), $this->identity->key($request), $data);

        return to_route('forms.show', $surveyForm)->with('success', 'survey_response_saved');
    }
}
