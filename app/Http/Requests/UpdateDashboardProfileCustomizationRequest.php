<?php

namespace App\Http\Requests;

use App\Rules\SafeImageDimensions;
use App\Support\Input\RequestTextInputSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardProfileCustomizationRequest extends FormRequest
{
    public const DESCRIPTION_MAX_LENGTH = 255;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'display_character_class_id' => ['nullable', 'integer', 'exists:character_classes,id'],
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
            'background_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new SafeImageDimensions],
            'reset_background_image' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'background_image.mimes' => __('dashboard.character_panel.customization.validation.image_invalid_format'),
        ];
    }

    protected function prepareForValidation(): void
    {
        app(RequestTextInputSanitizer::class)->sanitize($this, [], ['description']);

        $normalized = [];

        $reset = $this->input('reset_background_image');
        if (is_string($reset) && in_array(strtolower($reset), ['true', 'false'], true)) {
            $normalized['reset_background_image'] = strtolower($reset) === 'true';
        }

        if ($this->exists('display_character_class_id') && blank($this->input('display_character_class_id'))) {
            $normalized['display_character_class_id'] = null;
        }

        if ($this->exists('description') && blank($this->input('description'))) {
            $normalized['description'] = null;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
