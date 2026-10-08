<?php

use App\Models\AuditLog;
use App\Models\SurveyForm;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\Forms\FormDefinitionService;
use App\Services\Forms\FormManagementService;
use App\Services\Forms\FormResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function survey_question(string $type = 'short_text', bool $required = true): array
{
    return ['id' => (string) Str::uuid(), 'type' => $type, 'label' => ['en' => 'What do you think?'], 'description' => [], 'required' => $required, 'options' => in_array($type, FormDefinitionService::CHOICES, true) ? [
        ['id' => (string) Str::uuid(), 'label' => ['en' => 'Option A']], ['id' => (string) Str::uuid(), 'label' => ['en' => 'Option B']],
    ] : []];
}

function survey_definition(array $overrides = []): array
{
    return [...app(FormDefinitionService::class)->empty(), 'title' => ['en' => 'Community feedback'], 'questions' => [survey_question()], ...$overrides];
}

function survey_publish(User $admin, array $definition = [], string $slug = 'feedback'): SurveyForm
{
    $service = app(FormManagementService::class);
    $form = $service->save($admin, ['slug' => $slug, 'definition' => app(FormDefinitionService::class)->validate(survey_definition($definition))]);
    $service->transition($admin, $form, 1, 'publish');

    return $form->fresh();
}

function survey_submission(SurveyForm $form, mixed $answer = 'Great experience', int $revision = 0): array
{
    return ['version_id' => $form->published_version_id, 'response_revision' => $revision, 'submission_key' => (string) Str::uuid(), 'answers' => [$form->publishedVersion->definition['questions'][0]['id'] => $answer]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
});

it('creates a localized draft, publishes it at the exact forms URL, and audits changes', function () {
    $this->actingAs($this->admin)->get(route('admin.forms.create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Forms/Edit')->where('emptyDefinition.response_policy', 'single_editable'));
    $this->post(route('admin.forms.store'), ['slug' => 'feedback', 'definition' => survey_definition()])->assertRedirect();
    $form = SurveyForm::firstOrFail();
    $this->get('/forms/feedback')->assertNotFound();
    $this->post(route('admin.forms.transition', [$form, 'publish']), ['revision' => 1])->assertRedirect();
    $this->get('/forms/feedback')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Forms/Show')->where('form.definition.title.en', 'Community feedback')->where('form.is_open', true));
    expect(AuditLog::whereIn('action', ['admin.form.created', 'admin.form.publish'])->count())->toBe(2);
    expect($form->fresh()->versions()->count())->toBe(1);
});

it('protects every administration endpoint from ordinary users', function () {
    $form = survey_publish($this->admin);
    $this->actingAs(User::factory()->create());
    foreach (['index', 'create', 'edit', 'responses', 'export'] as $action) {
        $this->getJson(route('admin.forms.'.$action, in_array($action, ['index', 'create']) ? [] : $form))->assertForbidden();
    }
    $this->postJson(route('admin.forms.store'), ['slug' => 'another', 'definition' => survey_definition()])->assertForbidden();
    $this->putJson(route('admin.forms.update', $form), ['revision' => 2, 'slug' => 'feedback', 'definition' => survey_definition()])->assertForbidden();
    foreach (['publish', 'unpublish', 'open', 'close'] as $action) {
        $this->postJson(route('admin.forms.transition', [$form, $action]), ['revision' => 2])->assertForbidden();
    }
    $response = app(FormResponseService::class)->submit($form, $this->admin, str_repeat('a', 64), survey_submission($form));
    $this->getJson(route('admin.forms.response', [$form, $response]))->assertForbidden();
});

it('requires sign in and verified email for account forms and respects account bans', function () {
    $form = survey_publish($this->admin);
    $this->get('/forms/feedback')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canSubmit', false));
    $this->postJson('/forms/feedback', survey_submission($form))->assertForbidden();
    $this->actingAs(User::factory()->unverified()->create())->postJson('/forms/feedback', survey_submission($form))->assertForbidden();
    $this->actingAs(User::factory()->create(['banned_at' => now()]))->postJson('/forms/feedback', survey_submission($form))->assertForbidden();
    $this->assertDatabaseCount('survey_responses', 0);
});

it('creates one editable response per account and keeps other users answers private', function () {
    $form = survey_publish($this->admin);
    $user = User::factory()->create();
    $this->actingAs($user)->post('/forms/feedback', survey_submission($form))->assertRedirect('/forms/feedback');
    $response = SurveyResponse::firstOrFail();
    $this->get('/forms/feedback')->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->where('existing.revision', 1)->missing('existing.respondent_key'));
    $this->post('/forms/feedback', survey_submission($form, 'Updated feedback', 1))->assertRedirect();
    expect($response->fresh()->revision)->toBe(2)->and(array_values($response->fresh()->answers))->toBe(['Updated feedback']);
    $this->actingAs(User::factory()->create())->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('existing', null));
    $this->assertDatabaseCount('survey_responses', 1);
});

it('rejects stale edits and retries identical submissions without creating duplicates', function () {
    $form = survey_publish($this->admin);
    $this->actingAs(User::factory()->create());
    $payload = survey_submission($form);
    $this->post('/forms/feedback', $payload)->assertRedirect();
    $this->post('/forms/feedback', $payload)->assertRedirect();
    $this->postJson('/forms/feedback', survey_submission($form))->assertUnprocessable()->assertJsonValidationErrors('form');
    $this->actingAs(User::factory()->create())->postJson('/forms/feedback', $payload)->assertConflict();
    $this->assertDatabaseCount('survey_responses', 1);
});

it('recognizes guest responses with an encrypted cookie without storing raw identifiers', function () {
    $form = survey_publish($this->admin, ['access' => 'anyone']);
    $token = str_repeat('g', 64);
    $this->get('/forms/feedback')->assertCookie('fullparty_forms');
    $this->withCookie('fullparty_forms', $token)->post('/forms/feedback', survey_submission($form))->assertRedirect();
    $this->withCookie('fullparty_forms', $token)->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('existing.revision', 1));
    $this->withCookie('fullparty_forms', $token)->post('/forms/feedback', survey_submission($form, 'Guest edit', 1))->assertRedirect();
    $response = SurveyResponse::firstOrFail();
    expect($response->user_id)->toBeNull()->and($response->respondent_key)->not->toBe($token)->and($response->revision)->toBe(2);
    $this->withCookie('fullparty_forms', str_repeat('h', 64))->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('existing', null));
});

it('supports a non-editable single response policy', function () {
    $form = survey_publish($this->admin, ['response_policy' => 'single']);
    $this->actingAs($this->admin)->post('/forms/feedback', survey_submission($form))->assertRedirect();
    $this->postJson('/forms/feedback', survey_submission($form, 'Changed', 1))->assertUnprocessable()->assertJsonValidationErrors('form');
    $this->assertDatabaseCount('survey_responses', 1);
});

it('supports multiple responses and idempotent guest retries', function () {
    $form = survey_publish($this->admin, ['access' => 'anyone', 'response_policy' => 'multiple']);
    $this->withCookie('fullparty_forms', str_repeat('x', 64));
    $payload = survey_submission($form);
    $this->post('/forms/feedback', $payload)->assertRedirect();
    $this->post('/forms/feedback', $payload)->assertRedirect();
    $this->post('/forms/feedback', survey_submission($form, 'Second response'))->assertRedirect();
    $this->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('existing', null));
    $this->assertDatabaseCount('survey_responses', 2);
});

it('blocks new responses and edits when closed and hides unpublished forms', function () {
    $form = survey_publish($this->admin);
    $this->actingAs($this->admin)->post('/forms/feedback', survey_submission($form))->assertRedirect();
    app(FormManagementService::class)->transition($this->admin, $form, 2, 'close');
    $this->postJson('/forms/feedback', survey_submission($form, 'Edit', 1))->assertUnprocessable();
    $this->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('form.is_open', false)->where('existing.revision', 1));
    app(FormManagementService::class)->transition($this->admin, $form, 3, 'unpublish');
    $this->get('/forms/feedback')->assertNotFound();
    $this->postJson('/forms/feedback', survey_submission($form))->assertNotFound();
    expect(SurveyResponse::count())->toBe(1);
    app(FormManagementService::class)->transition($this->admin, $form, 4, 'publish');
    expect($form->fresh()->is_open)->toBeFalse()->and($form->versions()->count())->toBe(1);
    app(FormManagementService::class)->transition($this->admin, $form, 5, 'open');
    $this->post('/forms/feedback', survey_submission($form, 'Edited after reopening', 1))->assertRedirect();
});

it('keeps saved drafts private until publication and preserves older question versions', function () {
    $form = survey_publish($this->admin);
    $this->actingAs($this->admin)->post('/forms/feedback', survey_submission($form))->assertRedirect();
    $definition = $form->draft;
    $definition['title']['en'] = 'New title';
    $definition['questions'][0]['label']['en'] = 'A revised question';
    $this->put(route('admin.forms.update', $form), ['slug' => $form->slug, 'revision' => 2, 'definition' => $definition])->assertRedirect();
    $this->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('form.definition.title.en', 'Community feedback'));
    $this->post(route('admin.forms.transition', [$form, 'publish']), ['revision' => 3])->assertRedirect();
    $this->postJson('/forms/feedback', survey_submission($form, 'Old version', 1))->assertUnprocessable()->assertJsonValidationErrors('form');
    $this->get('/forms/feedback')->assertInertia(fn (Assert $page) => $page->where('form.definition.title.en', 'New title')->where('existing.version_changed', true));
    expect(SurveyResponse::first()->version->definition['questions'][0]['label']['en'])->toBe('What do you think?');
    $this->get(route('admin.forms.responses', ['surveyForm' => $form, 'version' => $form->published_version_id]))->assertInertia(fn (Assert $page) => $page->where('responses.total', 1)->where('totalResponses', 1));
});

it('prevents stale admin edits, URL changes after publishing, and duplicate slugs', function () {
    $form = survey_publish($this->admin);
    $this->actingAs($this->admin);
    $this->putJson(route('admin.forms.update', $form), ['slug' => 'feedback', 'revision' => 1, 'definition' => $form->draft])->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->putJson(route('admin.forms.update', $form), ['slug' => 'new-url', 'revision' => 2, 'definition' => $form->draft])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson(route('admin.forms.store'), ['slug' => 'feedback', 'definition' => $form->draft])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson(route('admin.forms.transition', [$form, 'close']), ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('revision');
});

it('validates all supported question types against their published definitions', function (string $type, mixed $valid, mixed $invalid) {
    $question = survey_question($type);
    if (in_array($type, FormDefinitionService::CHOICES, true)) {
        $valid = $type === 'multiple_choice' ? [$question['options'][0]['id']] : $question['options'][0]['id'];
    }
    $form = survey_publish($this->admin, ['questions' => [$question]]);
    $this->actingAs($this->admin)->postJson('/forms/feedback', survey_submission($form, $invalid))->assertUnprocessable()->assertJsonValidationErrors('answers.'.$question['id'].($type === 'multiple_choice' ? '.0' : ''));
    $this->post('/forms/feedback', survey_submission($form, $valid))->assertRedirect();
    expect(SurveyResponse::count())->toBe(1);
})->with([
    ['short_text', 'Nice', str_repeat('a', 501)], ['long_text', 'Detailed feedback', str_repeat('a', 5001)],
    ['single_choice', '', 'unknown'], ['multiple_choice', [], ['unknown']], ['dropdown', '', 'unknown'],
    ['rating', 5, 6], ['number', 0, 'not a number'], ['date', '2026-10-08', '2026-02-30'],
]);

it('rejects extra answer keys, missing required answers and numeric range violations', function () {
    $question = [...survey_question('number'), 'min' => 0, 'max' => 10];
    $form = survey_publish($this->admin, ['questions' => [$question]]);
    $payload = survey_submission($form);
    $payload['answers'] = [];
    $this->actingAs($this->admin)->postJson('/forms/feedback', $payload)->assertUnprocessable();
    $payload['answers'] = [$question['id'] => 2, 'injected' => 'extra'];
    $this->postJson('/forms/feedback', $payload)->assertUnprocessable()->assertJsonValidationErrors('answers');
    $this->postJson('/forms/feedback', survey_submission($form, 11))->assertUnprocessable();
    $this->post('/forms/feedback', survey_submission($form, 0))->assertRedirect();
});

it('allows optional empty answers and ignores invalid answers when prefilling revised questions', function () {
    $service = app(FormDefinitionService::class);
    $question = survey_question('multiple_choice', false);
    expect($service->answers([], [$question]))->toBe([$question['id'] => null]);
    $old = [$question];
    $answers = [$question['id'] => [$question['options'][0]['id']]];
    $question['options'] = [survey_question('single_choice')['options'][0]];
    expect($service->prefill($answers, $old, [$question]))->toBe([]);
    $question['type'] = 'short_text';
    expect($service->prefill($answers, $old, [$question]))->toBe([]);
});

it('validates definitions and rejects unsafe rich text', function () {
    $this->actingAs($this->admin);
    foreach ([['questions' => []], ['access' => 'unknown'], ['title' => ['de' => 'Kein Englisch']], ['questions' => [[...survey_question('single_choice'), 'options' => []]]], ['questions' => [[...survey_question('number'), 'min' => 10, 'max' => 1]]]] as $patch) {
        $this->postJson(route('admin.forms.store'), ['slug' => 'feedback', 'definition' => survey_definition($patch)])->assertUnprocessable();
    }
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Danger', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]]]]]];
    $this->postJson(route('admin.forms.store'), ['slug' => 'feedback', 'definition' => survey_definition(['intro' => ['en' => $doc]])])->assertUnprocessable();
    $this->assertDatabaseCount('survey_forms', 0);
});

it('aggregates answers, scopes results by form and version, and protects CSV from formulas', function () {
    $questions = [survey_question('short_text'), survey_question('rating'), survey_question('number'), survey_question('multiple_choice')];
    $form = survey_publish($this->admin, ['questions' => $questions]);
    $payload = survey_submission($form);
    $payload['answers'] = [$questions[0]['id'] => '=HYPERLINK("https://example.test")', $questions[1]['id'] => 4, $questions[2]['id'] => 0, $questions[3]['id'] => [$questions[3]['options'][0]['id']]];
    $this->actingAs($this->admin)->post('/forms/feedback', $payload)->assertRedirect();
    $response = SurveyResponse::firstOrFail();
    $this->get(route('admin.forms.responses', $form))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Forms/Responses')->where('summary.1.average', 4)->where('summary.2.average', 0)->where('summary.2.answered', 1)->where('summary.3.counts.0.count', 1)->where('responses.total', 1));
    $this->getJson(route('admin.forms.response', [$form, $response]))->assertOk()->assertJsonPath('answers.3.answer', 'Option A')->assertJsonMissingPath('respondent_key');
    $csv = $this->get(route('admin.forms.export', $form))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain('Option A');
    $other = survey_publish($this->admin, [], 'other');
    $this->getJson(route('admin.forms.response', [$other, $response]))->assertNotFound();
    $this->getJson(route('admin.forms.responses', ['surveyForm' => $other, 'version' => $form->published_version_id]))->assertNotFound();
    $this->getJson(route('admin.forms.export', ['surveyForm' => $other, 'version' => $form->published_version_id]))->assertNotFound();
});

it('limits anonymous submission attempts per IP', function () {
    $form = survey_publish($this->admin, ['access' => 'anyone', 'response_policy' => 'multiple']);
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/forms/feedback', [])->assertUnprocessable();
    }
    $this->postJson('/forms/feedback', survey_submission($form))->assertTooManyRequests();
});

it('does not expose public forms on the resources or API documentation hosts', function () {
    survey_publish($this->admin, ['access' => 'anyone']);
    foreach ([config('group_resources.public_host'), config('integration_api.docs_host')] as $host) {
        $this->get('https://'.$host.'/forms/feedback')->assertNotFound();
    }
});
