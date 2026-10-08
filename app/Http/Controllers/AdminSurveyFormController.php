<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSurveyFormRequest;
use App\Models\SurveyForm;
use App\Models\SurveyResponse;
use App\Services\Forms\FormDefinitionService;
use App\Services\Forms\FormManagementService;
use App\Services\Forms\FormResultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSurveyFormController extends Controller
{
    public function __construct(private readonly FormManagementService $forms, private readonly FormDefinitionService $definitions, private readonly FormResultsService $results) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Forms/Index', ['forms' => SurveyForm::query()->withCount('responses')->latest('id')->paginate(20)->through(fn ($form) => [
            'slug' => $form->slug, 'title' => $this->definitions->text($form->draft['title']), 'is_published' => $form->is_published, 'is_open' => $form->is_open, 'responses_count' => $form->responses_count,
        ])]);
    }

    public function create(): Response
    {
        return $this->editor();
    }

    public function edit(SurveyForm $surveyForm): Response
    {
        return $this->editor($surveyForm);
    }

    private function editor(?SurveyForm $form = null): Response
    {
        return Inertia::render('Admin/Forms/Edit', ['formRecord' => $form ? [...$form->only(['slug', 'draft', 'revision', 'is_open', 'is_published', 'published_version_id']), 'has_changes' => $form->publishedVersion?->definition != $form->draft] : null, 'emptyDefinition' => $this->definitions->empty()]);
    }

    public function store(SaveSurveyFormRequest $request): RedirectResponse
    {
        return to_route('admin.forms.edit', $this->forms->save($request->user(), $request->validated()))->with('success', 'survey_saved');
    }

    public function update(SaveSurveyFormRequest $request, SurveyForm $surveyForm): RedirectResponse
    {
        return to_route('admin.forms.edit', $this->forms->save($request->user(), $request->validated(), $surveyForm))->with('success', 'survey_saved');
    }

    public function transition(Request $request, SurveyForm $surveyForm, string $action): RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->forms->transition($request->user(), $surveyForm, (int) $data['revision'], $action);

        return back()->with('success', 'survey_updated');
    }

    public function responses(Request $request, SurveyForm $surveyForm): Response
    {
        $versions = $surveyForm->versions()->latest('number')->get(['id', 'number', 'published_at']);
        $version = $surveyForm->versions()->findOrFail($request->integer('version') ?: $versions->first()?->id);

        return Inertia::render('Admin/Forms/Responses', [
            'formRecord' => ['slug' => $surveyForm->slug, 'title' => $this->definitions->text($version->definition['title'])],
            'versions' => $versions, 'selectedVersion' => $version->id, 'totalResponses' => $surveyForm->responses()->count(),
            'summary' => $this->results->summary($surveyForm, $version),
            'responses' => $this->results->query($surveyForm, $version)->with('user:id,name')->latest('id')->paginate(25)->withQueryString()->through(fn ($response) => [
                'id' => $response->id, 'respondent' => $response->user?->name ?? __('forms.guest'), 'created_at' => $response->created_at->toIso8601String(), 'updated_at' => $response->updated_at->toIso8601String(),
            ]),
        ]);
    }

    public function response(SurveyForm $surveyForm, SurveyResponse $surveyResponse): JsonResponse
    {
        abort_unless($surveyResponse->survey_form_id === $surveyForm->id, 404);
        $surveyResponse->load(['user:id,name', 'version']);

        return response()->json($this->results->response($surveyResponse))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, SurveyForm $surveyForm): StreamedResponse
    {
        $version = $surveyForm->versions()->findOrFail($request->integer('version') ?: $surveyForm->published_version_id);

        return $this->results->csv($surveyForm, $version);
    }
}
