<?php

namespace App\Services\Forms;

use App\Services\RichText\RichTextDocument;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormDefinitionService
{
    public const TYPES = ['short_text', 'long_text', 'single_choice', 'multiple_choice', 'dropdown', 'rating', 'number', 'date'];

    public const CHOICES = ['single_choice', 'multiple_choice', 'dropdown'];

    public function __construct(private readonly RichTextDocument $documents) {}

    public function empty(): array
    {
        return ['title' => ['en' => ''], 'intro' => ['en' => RichTextDocument::empty()], 'thank_you' => [], 'access' => 'account', 'response_policy' => 'single_editable', 'questions' => [], 'pages' => []];
    }

    public function validate(mixed $definition): array
    {
        $rules = [
            'definition' => ['required', 'array:title,intro,thank_you,access,response_policy,questions,pages'],
            'definition.access' => ['required', Rule::in(['account', 'anyone'])],
            'definition.response_policy' => ['required', Rule::in(['single_editable', 'single', 'multiple'])],
            'definition.title' => ['required', 'array:en,de,fr,ja'],
            'definition.title.en' => ['required', 'string', 'max:160'],
            'definition.title.*' => ['nullable', 'string', 'max:160'],
            'definition.intro' => ['present', 'array:en,de,fr,ja'],
            'definition.intro.*' => ['array'],
            'definition.thank_you' => ['present', 'array:en,de,fr,ja'],
            'definition.thank_you.*' => ['nullable', 'string', 'max:1000'],
            'definition.pages' => ['sometimes', 'array', 'list', 'max:20'],
            'definition.pages.*' => ['array:id,title,description'],
            'definition.pages.*.id' => ['required', 'uuid', 'distinct'],
            'definition.pages.*.title' => ['present', 'array:en,de,fr,ja'],
            'definition.pages.*.title.*' => ['nullable', 'string', 'max:160'],
            'definition.pages.*.description' => ['present', 'array:en,de,fr,ja'],
            'definition.pages.*.description.*' => ['nullable', 'string', 'max:1000'],
            'definition.questions' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'definition.questions.*' => ['array:id,type,label,description,required,options,min,max,page_id'],
            'definition.questions.*.page_id' => ['nullable', 'uuid'],
            'definition.questions.*.id' => ['required', 'uuid', 'distinct'],
            'definition.questions.*.type' => ['required', Rule::in(self::TYPES)],
            'definition.questions.*.label' => ['required', 'array:en,de,fr,ja'],
            'definition.questions.*.label.en' => ['required', 'string', 'max:300'],
            'definition.questions.*.label.*' => ['nullable', 'string', 'max:300'],
            'definition.questions.*.description' => ['present', 'array:en,de,fr,ja'],
            'definition.questions.*.description.*' => ['nullable', 'string', 'max:1000'],
            'definition.questions.*.required' => ['required', 'boolean'],
            'definition.questions.*.min' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
            'definition.questions.*.max' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
            'definition.questions.*.options' => ['present', 'array', 'list', 'max:25'],
            'definition.questions.*.options.*' => ['array:id,label'],
            'definition.questions.*.options.*.id' => ['required', 'uuid', 'distinct'],
            'definition.questions.*.options.*.label' => ['required', 'array:en,de,fr,ja'],
            'definition.questions.*.options.*.label.en' => ['required', 'string', 'max:200'],
            'definition.questions.*.options.*.label.*' => ['nullable', 'string', 'max:200'],
        ];
        $data = Validator::make(['definition' => $definition], $rules)->validate()['definition'];
        $pageIds = array_column($data['pages'] ?? [], 'id');
        foreach ($data['intro'] as $locale => $body) {
            $data['intro'][$locale] = $this->documents->validate($body, 'definition.intro.'.$locale, 10000);
            if ($locale !== 'en' && trim($this->documents->text($body)) === '' && $this->documents->imageUrls($body) === []) {
                unset($data['intro'][$locale]);
            }
            foreach ($this->documents->imageUrls($body) as $url) {
                if (str_starts_with(rawurldecode(parse_url($url, PHP_URL_PATH) ?? ''), '/resource-assets/')) {
                    throw ValidationException::withMessages(['definition.intro.'.$locale => __('rich_text.invalid')]);
                }
            }
        }
        foreach ($data['questions'] as $i => &$question) {
            $pageId = $question['page_id'] ?? null;
            if (($pageIds !== [] && ! in_array($pageId, $pageIds, true)) || ($pageIds === [] && $pageId !== null)) {
                throw ValidationException::withMessages(["definition.questions.$i.page_id" => __('forms.errors.page')]);
            }
            if (in_array($question['type'], self::CHOICES, true) && count($question['options']) < 2) {
                throw ValidationException::withMessages(["definition.questions.$i.options" => __('forms.errors.options')]);
            }
            if (! in_array($question['type'], self::CHOICES, true)) {
                $question['options'] = [];
            }
            if (isset($question['min'], $question['max']) && $question['min'] > $question['max']) {
                throw ValidationException::withMessages(["definition.questions.$i.max" => __('forms.errors.range')]);
            }
            $question['required'] = (bool) $question['required'];
        }
        unset($question);

        if ($pageIds !== []) {
            $data['questions'] = collect($pageIds)
                ->flatMap(fn (string $pageId) => array_values(array_filter($data['questions'], fn (array $question): bool => $question['page_id'] === $pageId)))
                ->all();
        }

        return $data;
    }

    public function answers(mixed $answers, array $questions): array
    {
        $rules = ['answers' => ['present', 'array:'.implode(',', array_column($questions, 'id'))]];
        foreach ($questions as $question) {
            $key = 'answers.'.$question['id'];
            $rules[$key] = [$question['required'] ? 'required' : 'nullable'];
            $rules[$key] = [...$rules[$key], ...match ($question['type']) {
                'short_text' => ['string', 'max:500'],
                'long_text' => ['string', 'max:5000'],
                'single_choice', 'dropdown' => ['string', Rule::in(array_column($question['options'], 'id'))],
                'multiple_choice' => ['array', 'list', 'max:25'],
                'rating' => ['integer', 'between:1,5'],
                'number' => ['numeric', 'between:'.($question['min'] ?? -1000000000).','.($question['max'] ?? 1000000000)],
                'date' => ['date_format:Y-m-d'],
            }];
            if ($question['type'] === 'multiple_choice') {
                $rules[$key.'.*'] = ['required', 'string', 'distinct', Rule::in(array_column($question['options'], 'id'))];
            }
        }
        $attributes = collect($questions)->mapWithKeys(fn ($question) => ['answers.'.$question['id'] => $this->text($question['label']), 'answers.'.$question['id'].'.*' => $this->text($question['label'])])->all();
        $values = Validator::make(['answers' => $answers], $rules, [], $attributes)->validate()['answers'];
        foreach ($questions as $question) {
            $value = $values[$question['id']] ?? null;
            $values[$question['id']] = $value !== null && in_array($question['type'], ['number', 'rating'], true) ? 0 + $value : $value;
        }

        return $values;
    }

    public function prefill(array $answers, array $oldQuestions, array $newQuestions): array
    {
        $types = array_column($oldQuestions, 'type', 'id');
        $result = [];
        foreach ($newQuestions as $question) {
            if (($types[$question['id']] ?? null) !== $question['type']) {
                continue;
            }
            try {
                $result += $this->answers([$question['id'] => $answers[$question['id']] ?? null], [[...$question, 'required' => false]]);
            } catch (ValidationException) { /* Changed options need a fresh answer. */
            }
        }

        return $result;
    }

    public function text(array $value, ?string $locale = null): string
    {
        return filled($value[$locale ?? app()->getLocale()] ?? null) ? $value[$locale ?? app()->getLocale()] : ($value['en'] ?? '');
    }
}
