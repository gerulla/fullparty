<?php

namespace App\Http\Requests;

use App\Services\Forms\FormDefinitionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveSurveyFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('survey_forms')->ignore($this->route('surveyForm'))],
            'revision' => [$this->route('surveyForm') ? 'required' : 'nullable', 'integer', 'min:1'],
            'definition' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $definition = app(FormDefinitionService::class)->validate($this->input('definition'));
            $validator->setData(array_replace($validator->getData(), ['definition' => $definition]));
        }];
    }
}
