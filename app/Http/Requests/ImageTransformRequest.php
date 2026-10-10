<?php

namespace App\Http\Requests;

use App\DTOs\Images\ImageTransformOptions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ImageTransformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $dimension = 'max:'.config('image_variants.max_dimension');

        return [
            'path' => ['required', 'string', 'max:512'],
            'width' => ['required_without:height', Rule::requiredIf($this->input('fit') === 'crop'), 'integer', 'min:1', $dimension],
            'height' => ['required_without:width', Rule::requiredIf($this->input('fit') === 'crop'), 'integer', 'min:1', $dimension],
            'fit' => ['sometimes', Rule::in(['contain', 'crop'])],
            'position' => ['sometimes', Rule::in(['center', 'top', 'bottom', 'left', 'right'])],
            'crop' => ['sometimes', 'string', 'regex:/\A[0-9]{1,4},[0-9]{1,4},[1-9][0-9]{0,3},[1-9][0-9]{0,3}\z/'],
            'upscale' => ['sometimes', 'boolean'],
        ];
    }

    public function options(): ImageTransformOptions
    {
        $data = $this->validated();

        return new ImageTransformOptions(
            width: isset($data['width']) ? (int) $data['width'] : null,
            height: isset($data['height']) ? (int) $data['height'] : null,
            fit: $data['fit'] ?? 'contain',
            position: $data['position'] ?? 'center',
            crop: isset($data['crop']) ? array_map('intval', explode(',', $data['crop'])) : null,
            upscale: (bool) ($data['upscale'] ?? false),
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        // Image requests do not have sessions or a form page to redirect back to.
        throw new HttpResponseException(response()->json(['errors' => $validator->errors()], 422));
    }
}
