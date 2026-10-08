<?php

namespace App\Http\Requests;

use App\Services\RichText\RichTextDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveChangelogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'revision' => [$this->route('changelogEntry') ? 'required' : 'nullable', 'integer', 'min:1'],
            'version_mode' => ['required', Rule::in(['current', 'since_last'])],
            'version_context' => ['required', 'array:current_version,current_commit,previous_entry_id,previous_version,can_use_range'],
            'version_context.current_version' => ['nullable', 'string', 'max:50'],
            'version_context.current_commit' => ['nullable', 'string', 'max:100'],
            'version_context.previous_entry_id' => ['nullable', 'integer'],
            'version_context.previous_version' => ['nullable', 'string', 'max:50'],
            'version_context.can_use_range' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array:en,de,fr,ja'],
            'translations.en' => ['required', 'array:title,body'],
            'translations.*' => ['array:title,body'],
            'translations.*.title' => ['nullable', 'string', 'max:160'],
            'translations.*.body' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $documents = app(RichTextDocument::class);
            $translations = [];
            foreach ($this->input('translations') as $locale => $content) {
                $field = 'translations.'.$locale;
                $body = $documents->validate($content['body'], $field.'.body', 50000);
                $title = trim($content['title'] ?? '');
                $hasText = trim($documents->text($body)) !== '';
                if ($locale !== 'en' && $title === '' && ! $hasText && $documents->imageUrls($body) === []) {
                    continue;
                }
                if ($title === '' || ! $hasText) {
                    $validator->errors()->add($field.'.body', __('changelog.errors.translation_required'));
                }
                foreach ($documents->imageUrls($body) as $url) {
                    if (str_starts_with(rawurldecode(parse_url($url, PHP_URL_PATH) ?? ''), '/resource-assets/')) {
                        $validator->errors()->add($field.'.body', __('rich_text.invalid'));
                    }
                }
                $translations[$locale] = ['title' => $title, 'body' => $body];
            }
            $validator->setData(array_replace($validator->getData(), ['translations' => $translations]));
        }];
    }
}
